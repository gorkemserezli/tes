<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CreateUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\CustomerGroup;
use App\Notifications\AccountApprovedNotification;
use App\Notifications\PasswordResetNotification;
use App\Services\SystemLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserManagementController extends Controller
{
    protected SystemLogService $systemLog;

    public function __construct(SystemLogService $systemLog)
    {
        $this->systemLog = $systemLog;
    }

    /**
     * List users with filters
     */
    public function index(Request $request)
    {
        $query = User::with(['company', 'groups'])
            ->when($request->role, function ($q, $role) {
                if ($role === 'admin') {
                    $q->where('is_admin', true);
                } elseif ($role === 'customer') {
                    $q->where('is_admin', false);
                }
            })
            ->when($request->status, function ($q, $status) {
                if ($status === 'active') {
                    $q->active();
                } elseif ($status === 'inactive') {
                    $q->where('is_active', false);
                } elseif ($status === 'pending') {
                    $q->whereHas('company', function ($company) {
                        $company->where('is_approved', false);
                    });
                }
            })
            ->when($request->group_id, function ($q, $groupId) {
                $q->whereHas('groups', function ($group) use ($groupId) {
                    $group->where('customer_groups.id', $groupId);
                });
            })
            ->when($request->search, function ($q, $search) {
                $q->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhereHas('company', function ($company) use ($search) {
                            $company->where('company_name', 'like', "%{$search}%")
                                ->orWhere('tax_number', 'like', "%{$search}%");
                        });
                });
            });

        // Sorting
        $sortField = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $users = $query->paginate($request->get('per_page', 20));

        return UserResource::collection($users)->additional([
            'meta' => [
                'statistics' => [
                    'total_users' => User::count(),
                    'total_admins' => User::where('is_admin', true)->count(),
                    'total_customers' => User::where('is_admin', false)->count(),
                    'active_users' => User::active()->count(),
                    'pending_approvals' => User::whereHas('company', function ($q) {
                        $q->where('is_approved', false);
                    })->count(),
                ],
            ],
        ]);
    }

    /**
     * Create new user (admin or customer)
     */
    public function store(CreateUserRequest $request)
    {
        DB::beginTransaction();

        try {
            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'is_active' => $request->is_active ?? true,
                'is_admin' => $request->is_admin ?? false,
                'email_verified_at' => $request->is_admin ? now() : null,
            ]);

            // Create company for customers
            if (!$request->is_admin && $request->filled('company')) {
                $company = $user->company()->create([
                    'company_name' => $request->company['company_name'],
                    'tax_number' => $request->company['tax_number'],
                    'tax_office' => $request->company['tax_office'],
                    'address' => $request->company['address'],
                    'city' => $request->company['city'],
                    'district' => $request->company['district'],
                    'postal_code' => $request->company['postal_code'] ?? null,
                    'is_approved' => $request->company['is_approved'] ?? false,
                    'approved_at' => $request->company['is_approved'] ? now() : null,
                    'approved_by' => $request->company['is_approved'] ? auth()->id() : null,
                    'credit_limit' => $request->company['credit_limit'] ?? 0,
                    'remaining_credit' => $request->company['credit_limit'] ?? 0,
                ]);
            }

            // Assign groups
            if ($request->has('group_ids') && !$request->is_admin) {
                $user->groups()->sync($request->group_ids);
            }

            DB::commit();

            $this->systemLog->info('admin', 'User created by admin', [
                'user_id' => $user->id,
                'created_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Kullanıcı başarıyla oluşturuldu.',
                'data' => new UserResource($user->load(['company', 'groups'])),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            $this->systemLog->error('admin', 'User creation failed', [
                'error' => $e->getMessage(),
                'created_by' => auth()->id(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Kullanıcı oluşturulurken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Show user details
     */
    public function show($id)
    {
        $user = User::with([
            'company',
            'groups',
            'orders' => function ($query) {
                $query->latest()->limit(10);
            },
            'balanceTransactions' => function ($query) {
                $query->latest()->limit(10);
            },
        ])->findOrFail($id);

        // Get user statistics
        $stats = [
            'total_orders' => $user->orders()->count(),
            'total_spent' => $user->orders()
                ->where('payment_status', 'paid')
                ->sum('grand_total'),
            'last_order_date' => $user->orders()
                ->latest()
                ->first()?->created_at?->format('d.m.Y'),
            'average_order_value' => $user->orders()
                    ->where('payment_status', 'paid')
                    ->avg('grand_total') ?? 0,
        ];

        return response()->json([
            'success' => true,
            'data' => new UserResource($user),
            'statistics' => $stats,
        ]);
    }

    /**
     * Update user
     */
    public function update(UpdateUserRequest $request, $id)
    {
        $user = User::findOrFail($id);

        DB::beginTransaction();

        try {
            // Update user
            $user->update([
                'name' => $request->name,
                'email' => $request->email,
                'phone' => $request->phone,
                'is_active' => $request->is_active ?? $user->is_active,
            ]);

            // Update password if provided
            if ($request->filled('password')) {
                $user->update(['password' => Hash::make($request->password)]);
            }

            // Update company if exists
            if ($user->company && $request->has('company')) {
                $user->company->update($request->company);
            }

            // Update groups
            if ($request->has('group_ids') && !$user->is_admin) {
                $user->groups()->sync($request->group_ids);
            }

            DB::commit();

            $this->systemLog->info('admin', 'User updated by admin', [
                'user_id' => $user->id,
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Kullanıcı başarıyla güncellendi.',
                'data' => new UserResource($user->load(['company', 'groups'])),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            $this->systemLog->error('admin', 'User update failed', [
                'user_id' => $id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Kullanıcı güncellenirken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Delete user
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        // Prevent self-deletion
        if ($user->id === auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Kendi hesabınızı silemezsiniz.',
            ], 400);
        }

        // Check if user has orders
        if ($user->orders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Siparişi olan kullanıcılar silinemez. Kullanıcıyı pasif yapabilirsiniz.',
            ], 400);
        }

        try {
            $user->delete();

            $this->systemLog->info('admin', 'User deleted by admin', [
                'deleted_user_id' => $id,
                'deleted_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Kullanıcı başarıyla silindi.',
            ]);

        } catch (\Exception $e) {
            $this->systemLog->error('admin', 'User deletion failed', [
                'user_id' => $id,
                'error' => $e->getMessage(),
            ], $e);

            return response()->json([
                'success' => false,
                'message' => 'Kullanıcı silinirken bir hata oluştu.',
            ], 500);
        }
    }

    /**
     * Toggle user status
     */
    public function toggleStatus($id)
    {
        $user = User::findOrFail($id);

        // Prevent self-deactivation
        if ($user->id === auth()->id() && $user->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Kendi hesabınızı pasif yapamazsınız.',
            ], 400);
        }

        $user->update(['is_active' => !$user->is_active]);

        $this->systemLog->info('admin', 'User status toggled', [
            'user_id' => $user->id,
            'new_status' => $user->is_active ? 'active' : 'inactive',
            'changed_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $user->is_active
                ? 'Kullanıcı aktif edildi.'
                : 'Kullanıcı pasif yapıldı.',
            'data' => [
                'is_active' => $user->is_active,
            ],
        ]);
    }

    /**
     * Reset user password
     */
    public function resetPassword(Request $request, $id)
    {
        $request->validate([
            'send_email' => 'boolean',
        ]);

        $user = User::findOrFail($id);

        // Generate random password
        $newPassword = Str::random(12);

        $user->update(['password' => Hash::make($newPassword)]);

        // Send email if requested
        if ($request->get('send_email', true)) {
            $user->notify(new PasswordResetNotification($newPassword));
        }

        $this->systemLog->info('admin', 'User password reset by admin', [
            'user_id' => $user->id,
            'reset_by' => auth()->id(),
            'email_sent' => $request->get('send_email', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Şifre başarıyla sıfırlandı.',
            'data' => [
                'new_password' => $request->get('send_email', true)
                    ? 'Email ile gönderildi'
                    : $newPassword,
            ],
        ]);
    }

    /**
     * Get available customer groups
     */
    public function getGroups()
    {
        $groups = CustomerGroup::active()
            ->withCount('users')
            ->get()
            ->map(function ($group) {
                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'discount_percentage' => $group->discount_percentage,
                    'user_count' => $group->users_count,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }

    /**
     * Export users
     */
    public function export(Request $request)
    {
        $request->validate([
            'format' => 'in:csv,xlsx',
            'filters' => 'array',
        ]);

        // Apply same filters as index
        $query = User::with(['company', 'groups']);

        if ($request->has('filters')) {
            // Apply filters...
        }

        $users = $query->get();

        $format = $request->get('format', 'xlsx');
        $fileName = 'users_' . date('Y-m-d_H-i-s') . '.' . $format;

        if ($format === 'csv') {
            return $this->exportCsv($users, $fileName);
        } else {
            return \Maatwebsite\Excel\Facades\Excel::download(
                new \App\Exports\UsersExport($users),
                $fileName
            );
        }
    }

    /**
     * Export users as CSV
     */
    protected function exportCsv($users, $fileName)
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ];

        $callback = function () use ($users) {
            $file = fopen('php://output', 'w');

            // Headers
            fputcsv($file, [
                'ID',
                'Ad Soyad',
                'Email',
                'Telefon',
                'Şirket',
                'Vergi No',
                'Durum',
                'Rol',
                'Kayıt Tarihi',
            ]);

            // Data
            foreach ($users as $user) {
                fputcsv($file, [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->phone,
                    $user->company?->company_name ?? '-',
                    $user->company?->tax_number ?? '-',
                    $user->is_active ? 'Aktif' : 'Pasif',
                    $user->is_admin ? 'Admin' : 'Müşteri',
                    $user->created_at->format('d.m.Y H:i'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
