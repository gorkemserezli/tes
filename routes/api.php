<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Customer\ProfileController;
use App\Http\Controllers\Api\Customer\ProductController;
use App\Http\Controllers\Api\Customer\CartController;
use App\Http\Controllers\Api\Customer\OrderController;
use App\Http\Controllers\Api\Customer\BalanceController;
use App\Http\Controllers\Api\Customer\NotificationController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\UserManagementController;
use App\Http\Controllers\Api\Admin\CompanyManagementController;
use App\Http\Controllers\Api\Admin\ProductManagementController;
use App\Http\Controllers\Api\Admin\OrderManagementController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\SystemLogController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\WebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::prefix('v1')->group(function () {

    // Auth routes
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('verify-2fa', [AuthController::class, 'verifyTwoFactor']);
        Route::post('resend-2fa', [AuthController::class, 'resendTwoFactor']);

        // Email verification
        Route::post('email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware(['throttle:6,1']);
        Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->name('verification.verify');

        // Password reset
        Route::post('forgot-password', [PasswordResetController::class, 'forgotPassword']);
        Route::post('reset-password', [PasswordResetController::class, 'resetPassword']);
    });

    // Webhook endpoints
    Route::prefix('webhooks')->group(function () {
        Route::post('paytr', [WebhookController::class, 'paytr']);
        Route::post('aras-cargo', [WebhookController::class, 'arasCargo']);
    });
});

// Protected routes
Route::prefix('v1')->middleware(['auth:sanctum', 'verified'])->group(function () {

    // Auth routes
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::get('me', [AuthController::class, 'me']);
    });

    // Customer routes
    Route::middleware(['active', 'company.approved'])->group(function () {

        // Profile
        Route::prefix('profile')->group(function () {
            Route::get('/', [ProfileController::class, 'show']);
            Route::put('/', [ProfileController::class, 'update']);
            Route::put('password', [ProfileController::class, 'updatePassword']);
            Route::get('company', [ProfileController::class, 'company']);
        });

        // Products
        Route::prefix('products')->group(function () {
            Route::get('/', [ProductController::class, 'index']);
            Route::get('featured', [ProductController::class, 'featured']);
            Route::get('categories', [ProductController::class, 'categories']);
            Route::get('category/{slug}', [ProductController::class, 'byCategory']);
            Route::get('{slug}', [ProductController::class, 'show']);
            Route::get('{id}/price', [ProductController::class, 'getPrice']);
        });

        // Cart
        Route::prefix('cart')->group(function () {
            Route::get('/', [CartController::class, 'index']);
            Route::post('add', [CartController::class, 'add']);
            Route::put('update/{id}', [CartController::class, 'update']);
            Route::delete('remove/{id}', [CartController::class, 'remove']);
            Route::delete('clear', [CartController::class, 'clear']);
            Route::get('summary', [CartController::class, 'summary']);
        });

        // Orders
        Route::prefix('orders')->group(function () {
            Route::get('/', [OrderController::class, 'index']);
            Route::post('/', [OrderController::class, 'store']);
            Route::get('{orderNumber}', [OrderController::class, 'show']);
            Route::post('{orderNumber}/cancel', [OrderController::class, 'cancel']);
            Route::get('{orderNumber}/invoice', [OrderController::class, 'invoice']);
            Route::get('{orderNumber}/tracking', [OrderController::class, 'tracking']);
        });

        // Balance
        Route::prefix('balance')->group(function () {
            Route::get('/', [BalanceController::class, 'index']);
            Route::get('transactions', [BalanceController::class, 'transactions']);
            Route::post('request-deposit', [BalanceController::class, 'requestDeposit']);
        });

        // Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'index']);
            Route::get('unread-count', [NotificationController::class, 'unreadCount']);
            Route::post('{id}/mark-as-read', [NotificationController::class, 'markAsRead']);
            Route::post('mark-all-as-read', [NotificationController::class, 'markAllAsRead']);
        });

        // Payment
        Route::prefix('payment')->group(function () {
            Route::post('process', [PaymentController::class, 'process']);
            Route::get('success', [PaymentController::class, 'success'])->name('payment.success');
            Route::get('fail', [PaymentController::class, 'fail'])->name('payment.fail');
        });
    });

    // Admin routes
    Route::prefix('admin')->middleware(['admin'])->group(function () {

        // Dashboard
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('dashboard/stats', [DashboardController::class, 'stats']);

        // User Management
        Route::prefix('users')->group(function () {
            Route::get('/', [UserManagementController::class, 'index']);
            Route::post('/', [UserManagementController::class, 'store']);
            Route::get('{id}', [UserManagementController::class, 'show']);
            Route::put('{id}', [UserManagementController::class, 'update']);
            Route::delete('{id}', [UserManagementController::class, 'destroy']);
            Route::post('{id}/toggle-status', [UserManagementController::class, 'toggleStatus']);
            Route::post('{id}/reset-password', [UserManagementController::class, 'resetPassword']);
        });

        // Company Management
        Route::prefix('companies')->group(function () {
            Route::get('/', [CompanyManagementController::class, 'index']);
            Route::get('pending', [CompanyManagementController::class, 'pending']);
            Route::get('{id}', [CompanyManagementController::class, 'show']);
            Route::put('{id}', [CompanyManagementController::class, 'update']);
            Route::post('{id}/approve', [CompanyManagementController::class, 'approve']);
            Route::post('{id}/reject', [CompanyManagementController::class, 'reject']);
            Route::post('{id}/update-credit-limit', [CompanyManagementController::class, 'updateCreditLimit']);
            Route::post('{id}/adjust-balance', [CompanyManagementController::class, 'adjustBalance']);
        });

        // Product Management
        Route::prefix('products')->group(function () {
            Route::get('/', [ProductManagementController::class, 'index']);
            Route::post('/', [ProductManagementController::class, 'store']);
            Route::get('{id}', [ProductManagementController::class, 'show']);
            Route::put('{id}', [ProductManagementController::class, 'update']);
            Route::delete('{id}', [ProductManagementController::class, 'destroy']);
            Route::post('{id}/toggle-status', [ProductManagementController::class, 'toggleStatus']);
            Route::post('{id}/update-stock', [ProductManagementController::class, 'updateStock']);
            Route::post('{id}/upload-images', [ProductManagementController::class, 'uploadImages']);
            Route::delete('{id}/images/{imageId}', [ProductManagementController::class, 'deleteImage']);

            // Categories
            Route::get('categories/tree', [ProductManagementController::class, 'categoryTree']);
            Route::post('categories', [ProductManagementController::class, 'storeCategory']);
            Route::put('categories/{id}', [ProductManagementController::class, 'updateCategory']);
            Route::delete('categories/{id}', [ProductManagementController::class, 'destroyCategory']);

            // Custom Prices
            Route::get('{id}/custom-prices', [ProductManagementController::class, 'customPrices']);
            Route::post('{id}/custom-prices', [ProductManagementController::class, 'storeCustomPrice']);
            Route::put('custom-prices/{priceId}', [ProductManagementController::class, 'updateCustomPrice']);
            Route::delete('custom-prices/{priceId}', [ProductManagementController::class, 'destroyCustomPrice']);
        });

        // Order Management
        Route::prefix('orders')->group(function () {
            Route::get('/', [OrderManagementController::class, 'index']);
            Route::get('{id}', [OrderManagementController::class, 'show']);
            Route::put('{id}/status', [OrderManagementController::class, 'updateStatus']);
            Route::post('{id}/add-note', [OrderManagementController::class, 'addNote']);
            Route::post('{id}/upload-shipping-document', [OrderManagementController::class, 'uploadShippingDocument']);
            Route::post('{id}/create-shipment', [OrderManagementController::class, 'createShipment']);
            Route::put('{id}/shipment', [OrderManagementController::class, 'updateShipment']);
        });

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('sales', [ReportController::class, 'sales']);
            Route::get('products', [ReportController::class, 'products']);
            Route::get('customers', [ReportController::class, 'customers']);
            Route::get('stock', [ReportController::class, 'stock']);
            Route::get('balance', [ReportController::class, 'balance']);
            Route::post('export/{type}', [ReportController::class, 'export']);
        });

        // System Logs
        Route::prefix('system-logs')->group(function () {
            Route::get('/', [SystemLogController::class, 'index']);
            Route::get('{id}', [SystemLogController::class, 'show']);
            Route::get('stats', [SystemLogController::class, 'stats']);
            Route::post('clean', [SystemLogController::class, 'clean']);
        });

        // Settings
        Route::prefix('settings')->group(function () {
            Route::get('/', [SettingsController::class, 'index']);
            Route::put('/', [SettingsController::class, 'update']);
            Route::get('payment', [SettingsController::class, 'payment']);
            Route::put('payment', [SettingsController::class, 'updatePayment']);
            Route::get('shipping', [SettingsController::class, 'shipping']);
            Route::put('shipping', [SettingsController::class, 'updateShipping']);
        });
    });
});

// Health check
Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});
