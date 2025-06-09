<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\BalanceTransaction;
use App\Models\Notification;
use App\Services\SystemLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyManagementController extends Controller
{
    protected SystemLogService $systemLog;

    public function __construct(SystemLogService $systemLog)
    {
        $this->systemLog = $systemLog;
    }

    /**
     * List companies with filters
     */
    public function index(Request $request)
    {
        $query = Company::with(['user', 'balanceTransactions' => function ($q) {
            $q->latest()->limit(5);
        }])
            ->when($request->status, function ($q, $status) {
                if ($status === 'approved') {
                    $q->approved();
                } elseif ($status === 'pending') {
                    $q->pending();
                }
            })
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('company_name', 'like', "%{$search}%")
                        ->orWhere('tax_number', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($user) use ($search) {
                            $user->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->city, function ($q, $city) {
                $q->where('city', $city);
            })
            ->when($request->has_balance, function ($q) {
                $q->where('balance', '>', 0);
            })
            ->when($request->has_credit, function ($q) {
                $q->where('credit_limit', '>', 0);
            });

        // Sorting
        $sortField = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $companies = $query->paginate($request->get('per_page', 20));

        // Get statistics
        $stats = [
            'total_companies' => Company::count(),
            'approved_companies' => Company::approved()->count(),
            'pending_companies' => Company::pending()->count(),
            'total_balance' => Company::sum('balance'),
            'total_credit_limit' => Company::sum('credit_limit'),
        ];

        return CompanyResource::collection($companies)->additional([
            'meta' => [
                'statistics' => $stats,
            ],
        ]);
    }

    /**
     * Get pending companies
     */
    public function pending(Request $request)
    {
        $companies = Company::pending()
            ->with('user')
            ->latest()
            ->paginate($request->get('per_page', 20));

        return CompanyResource::collection($companies);
    }

    /**
     * Show company details
     */
    public function show($id)
    {
        $company = Company::with([
            'user.orders' => function ($query) {
                $query->latest()->limit(10);
            },
            'balanceTransactions' => function ($query) {
                $query->latest()->limit(20);
            },
        ])->findOrFail($id);

        // Get company statistics
        $stats = [
            'total_orders' => $company->user->orders()->count(),
            'pending_orders' => $company->user->orders()
                ->where('status', 'pending')
                ->count(),
            'total_spent' => $company->user->orders()
                ->where('payment_status', 'paid')
                ->sum('grand_total'),
            'last_order_date' => $company->user->orders()
                ->latest()
                ->first()?->created_at?->format('d.m.Y'),
            'balance_usage' => BalanceTransaction::where('user_id', $company->user_id)
                ->where('type', 'order_payment')
                ->sum('amount'),
        ];

        return response()->json([
            'success' => true,
            'data' => new CompanyResource($company),
            'statistics' => $stats,
        ]);
    }

    /**
     * Update company
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'company_name' => 'sometimes|string|max:255',
            'tax_office' => 'sometimes|string|max:255',
            'address' => 'sometimes|string|max:500',
            'city' => 'sometimes|string|max:100',
            'district' => 'sometimes|string|max:100',
            'postal_code' => 'sometimes|string|max:10',
        ]);

        $company = Company::findOrFail($id);

        try {
            $company->update($request->only([
                'company_name',
                'tax_office',
                'address',
                'city',
                'district',
                'postal_code',
            ]));

            $this->systemLog->info('admin', 'Company updated by admin', [
                'company_id' => $company->id,
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Şirket bilgileri güncellendi.',
                'data' => new CompanyResource($company),
            ]);

        } catch (\Exception $e) {
            $this->systemLog->error('admin', 'Company update failed', [
                'company_id' => $id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Şirket güncellenirken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Approve company
     */
    public function approve($id)
    {
        $company = Company::pending()->findOrFail($id);

        DB::beginTransaction();

        try {
            $company->approve(auth()->user());

            // Activate user
            $company->user->update(['is_active' => true]);

            // Send notification
            Notification::createAccountNotification(
                $company->user,
                Notification::TYPE_ACCOUNT_APPROVED
            );

            DB::commit();

            $this->systemLog->info('admin', 'Company approved', [
                'company_id' => $company->id,
                'approved_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Şirket başarıyla onaylandı.',
                'data' => new CompanyResource($company->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            $this->systemLog->error('admin', 'Company approval failed', [
                'company_id' => $id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Şirket onaylanırken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Reject company
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $company = Company::pending()->findOrFail($id);

        DB::beginTransaction();

        try {
            $company->reject();

            // Send notification with reason
            Notification::createAccountNotification(
                $company->user,
                Notification::TYPE_ACCOUNT_REJECTED,
                'Hesabınız reddedildi. Sebep: ' . $request->reason
            );

            DB::commit();

            $this->systemLog->info('admin', 'Company rejected', [
                'company_id' => $company->id,
                'rejected_by' => auth()->id(),
                'reason' => $request->reason,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Şirket reddedildi.',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            $this->systemLog->error('admin', 'Company rejection failed', [
                'company_id' => $id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Şirket reddedilirken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Update credit limit
     */
    public function updateCreditLimit(Request $request, $id)
    {
        $request->validate([
            'credit_limit' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ]);

        $company = Company::findOrFail($id);
        $oldLimit = $company->credit_limit;

        try {
            $company->updateCreditLimit($request->credit_limit);

            // Log activity
            $this->systemLog->info('admin', 'Company credit limit updated', [
                'company_id' => $company->id,
                'old_limit' => $oldLimit,
                'new_limit' => $request->credit_limit,
                'updated_by' => auth()->id(),
                'note' => $request->note,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Kredi limiti güncellendi.',
                'data' => [
                    'credit_limit' => $company->credit_limit,
                    'remaining_credit' => $company->remaining_credit,
                ],
            ]);

        } catch (\Exception $e) {
            $this->systemLog->error('admin', 'Credit limit update failed', [
                'company_id' => $id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Kredi limiti güncellenirken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Adjust company balance
     */
    public function adjustBalance(Request $request, $id)
    {
        $request->validate([
            'amount' => 'required|numeric',
            'type' => 'required|in:add,deduct,set',
            'reason' => 'required|string|max:500',
        ]);

        $company = Company::findOrFail($id);

        DB::beginTransaction();

        try {
            switch ($request->type) {
                case 'add':
                    $transaction = $company->addBalance(
                        $request->amount,
                        $request->reason,
                        auth()->user()
                    );
                    $message = 'Bakiye eklendi.';
                    break;

                case 'deduct':
                    if ($company->balance < $request->amount) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Yetersiz bakiye.',
                        ], 400);
                    }

                    $transaction = $company->deductBalance(
                        $request->amount,
                        $request->reason
                    );
                    $message = 'Bakiye düşüldü.';
                    break;

                case 'set':
                    $transaction = $company->adjustBalance(
                        $request->amount,
                        $request->reason,
                        auth()->user()
                    );
                    $message = 'Bakiye ayarlandı.';
                    break;
            }

            // Send notification
            $notificationAmount = abs($transaction->amount);
            if ($transaction->amount > 0) {
                Notification::createPaymentNotification(
                    $company->user,
                    Notification::TYPE_BALANCE_ADDED,
                    $notificationAmount,
                    $request->reason
                );
            }

            DB::commit();

            $this->systemLog->info('admin', 'Company balance adjusted', [
                'company_id' => $company->id,
                'type' => $request->type,
                'amount' => $request->amount,
                'new_balance' => $company->fresh()->balance,
                'adjusted_by' => auth()->id(),
                'reason' => $request->reason,
            ]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'balance' => $company->fresh()->balance,
                    'transaction_id' => $transaction->id,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            $this->systemLog->error('admin', 'Balance adjustment failed', [
                'company_id' => $id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Bakiye ayarlanırken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Get balance transactions
     */
    public function balanceTransactions(Request $request, $id)
    {
        $company = Company::findOrFail($id);

        $transactions = BalanceTransaction::where('user_id', $company->user_id)
            ->with('createdBy')
            ->when($request->type, function ($q, $type) {
                $q->where('type', $type);
            })
            ->when($request->date_from, function ($q, $date) {
                $q->whereDate('created_at', '>=', $date);
            })
            ->when($request->date_to, function ($q, $date) {
                $q->whereDate('created_at', '<=', $date);
            })
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $transactions,
            'summary' => [
                'current_balance' => $company->balance,
                'total_deposits' => BalanceTransaction::where('user_id', $company->user_id)
                    ->whereIn('type', ['deposit', 'refund'])
                    ->sum('amount'),
                'total_withdrawals' => abs(BalanceTransaction::where('user_id', $company->user_id)
                    ->whereIn('type', ['withdraw', 'order_payment'])
                    ->sum('amount')),
            ],
        ]);
    }

    /**
     * Export companies
     */
    public function export(Request $request)
    {
        $request->validate([
            'format' => 'in:csv,xlsx',
            'status' => 'in:all,approved,pending',
        ]);

        $query = Company::with('user');

        if ($request->status && $request->status !== 'all') {
            if ($request->status === 'approved') {
                $query->approved();
            } else {
                $query->pending();
            }
        }

        $companies = $query->get();

        $format = $request->get('format', 'xlsx');
        $fileName = 'companies_' . date('Y-m-d_H-i-s') . '.' . $format;

        if ($format === 'csv') {
            return $this->exportCsv($companies, $fileName);
        } else {
            return \Maatwebsite\Excel\Facades\Excel::download(
                new \App\Exports\CompaniesExport($companies),
                $fileName
            );
        }
    }

    /**
     * Export companies as CSV
     */
    protected function exportCsv($companies, $fileName)
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ];

        $callback = function () use ($companies) {
            $file = fopen('php://output', 'w');

            // Headers
            fputcsv($file, [
                'ID',
                'Şirket Adı',
                'Vergi No',
                'Vergi Dairesi',
                'Yetkili',
                'Email',
                'Telefon',
                'Şehir',
                'Bakiye',
                'Kredi Limiti',
                'Durum',
                'Kayıt Tarihi',
            ]);

            // Data
            foreach ($companies as $company) {
                fputcsv($file, [
                    $company->id,
                    $company->company_name,
                    $company->tax_number,
                    $company->tax_office,
                    $company->user->name,
                    $company->user->email,
                    $company->user->phone,
                    $company->city,
                    $company->balance,
                    $company->credit_limit,
                    $company->is_approved ? 'Onaylı' : 'Beklemede',
                    $company->created_at->format('d.m.Y H:i'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
