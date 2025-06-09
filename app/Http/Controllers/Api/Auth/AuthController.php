<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyTwoFactorRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Company;
use App\Notifications\TwoFactorCodeNotification;
use App\Notifications\WelcomeNotification;
use App\Services\SystemLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected $systemLog;

    public function __construct(SystemLogService $systemLog)
    {
        $this->systemLog = $systemLog;
    }

    /**
     * Register new B2B customer
     */
    public function register(RegisterRequest $request)
    {
        DB::beginTransaction();

        try {
            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'is_active' => false, // Admin onayı bekleyecek
                'is_admin' => false,
            ]);

            // Create company
            $company = Company::create([
                'user_id' => $user->id,
                'company_name' => $request->company_name,
                'tax_number' => $request->tax_number,
                'tax_office' => $request->tax_office,
                'address' => $request->address,
                'city' => $request->city,
                'district' => $request->district,
                'postal_code' => $request->postal_code,
                'is_approved' => false,
            ]);

            // Send verification email
            $user->sendEmailVerificationNotification();

            // Send welcome notification
            $user->notify(new WelcomeNotification());

            // Log activity
            $this->systemLog->info('auth', 'New B2B registration', [
                'user_id' => $user->id,
                'company' => $company->company_name,
                'tax_number' => $company->tax_number
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Kayıt başarılı. Email adresinize gönderilen doğrulama linkine tıklayın ve admin onayını bekleyin.',
                'data' => new UserResource($user)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            $this->systemLog->error('auth', 'Registration failed', [
                'error' => $e->getMessage(),
                'email' => $request->email
            ]);

            return response()->json([
                'message' => 'Kayıt sırasında bir hata oluştu.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->only('email', 'password');

        if (!Auth::attempt($credentials)) {
            $this->systemLog->warning('auth', 'Failed login attempt', [
                'email' => $request->email,
                'ip' => $request->ip()
            ]);

            throw ValidationException::withMessages([
                'email' => ['Email veya şifre hatalı.'],
            ]);
        }

        $user = Auth::user();

        // Check if user is active
        if (!$user->isActive()) {
            Auth::logout();

            $this->systemLog->warning('auth', 'Inactive user login attempt', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            return response()->json([
                'message' => 'Hesabınız aktif değil. Lütfen email doğrulaması yapın ve admin onayını bekleyin.'
            ], 403);
        }

        // Check if company is approved for customers
        if ($user->isCustomer() && !$user->isApproved()) {
            Auth::logout();

            return response()->json([
                'message' => 'Şirketiniz henüz onaylanmamış. Lütfen admin onayını bekleyin.'
            ], 403);
        }

        // Generate 2FA code if enabled
        if (config('auth.two_factor_enabled', true)) {
            $code = $user->generateTwoFactorCode();

            // Send 2FA code via email
            $user->notify(new TwoFactorCodeNotification($code));

            $this->systemLog->info('auth', '2FA code sent', [
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Doğrulama kodu email adresinize gönderildi.',
                'requires_two_factor' => true,
                'two_factor_token' => encrypt($user->id)
            ]);
        }

        // Create token
        $token = $user->createToken('auth-token')->plainTextToken;

        // Update last login
        $user->updateLastLogin();

        $this->systemLog->info('auth', 'User logged in', [
            'user_id' => $user->id,
            'ip' => $request->ip()
        ]);

        return response()->json([
            'message' => 'Giriş başarılı.',
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ]);
    }

    /**
     * Verify two factor code
     */
    public function verifyTwoFactor(VerifyTwoFactorRequest $request)
    {
        try {
            $userId = decrypt($request->two_factor_token);
            $user = User::findOrFail($userId);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Geçersiz doğrulama token\'ı.'
            ], 400);
        }

        if (!$user->verifyTwoFactorCode($request->code)) {
            $this->systemLog->warning('auth', 'Invalid 2FA code', [
                'user_id' => $user->id
            ]);

            return response()->json([
                'message' => 'Doğrulama kodu hatalı veya süresi dolmuş.'
            ], 400);
        }

        // Clear two factor code
        $user->clearTwoFactorCode();

        // Create token
        $token = $user->createToken('auth-token')->plainTextToken;

        // Update last login
        $user->updateLastLogin();

        $this->systemLog->info('auth', '2FA verification successful', [
            'user_id' => $user->id
        ]);

        return response()->json([
            'message' => 'Doğrulama başarılı.',
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        $this->systemLog->info('auth', 'User logged out', [
            'user_id' => $user->id
        ]);

        return response()->json([
            'message' => 'Çıkış yapıldı.'
        ]);
    }

    /**
     * Get authenticated user
     */
    public function me(Request $request)
    {
        $user = $request->user();
        $user->load(['company', 'groups']);

        return new UserResource($user);
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request)
    {
        $user = $request->user();

        // Delete old token
        $request->user()->currentAccessToken()->delete();

        // Create new token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ]);
    }

    /**
     * Resend two factor code
     */
    public function resendTwoFactor(Request $request)
    {
        $request->validate([
            'two_factor_token' => 'required|string'
        ]);

        try {
            $userId = decrypt($request->two_factor_token);
            $user = User::findOrFail($userId);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Geçersiz doğrulama token\'ı.'
            ], 400);
        }

        // Check if we can resend (rate limiting)
        if ($user->two_factor_expires_at && $user->two_factor_expires_at->subMinutes(8)->isFuture()) {
            return response()->json([
                'message' => 'Yeni kod göndermek için lütfen bekleyin.'
            ], 429);
        }

        // Generate new code
        $code = $user->generateTwoFactorCode();

        // Send new code
        $user->notify(new TwoFactorCodeNotification($code));

        $this->systemLog->info('auth', '2FA code resent', [
            'user_id' => $user->id
        ]);

        return response()->json([
            'message' => 'Yeni doğrulama kodu gönderildi.'
        ]);
    }
}
