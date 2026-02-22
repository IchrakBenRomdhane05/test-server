<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ActivityLog;
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{

private OtpService $otpService;

    public function __construct(OtpService $otpService)
    {
        $this->otpService = $otpService;
    }

    /**
     * POST /api/auth/register
     * Step 1: Register user (unverified), send OTP
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'role' => [
                'required',
                'string',
                Rule::in(['super_admin', 'gerant', 'caissier', 'employe', 'comptable']),
            ],
        ]);

                // Create user but NOT verified yet
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'email_verified_at' => null, // Not verified
        ]);

         // Send OTP for email verification
        $otp = $this->otpService->generate($user);

        $response = [
            'mfa_required' => true,
            'user_id' => $user->id,
            'email' => $user->email,
            'message' => 'Code de vérification envoyé à votre email.',
        ];

        // For development only - remove in production
        if (config('app.debug')) {
            $response['dev_code'] = $otp->code;
        }

        return response()->json($response, 201);
    }

    /**
     * POST /api/auth/verify-registration
     * Step 2: Verify OTP to complete registration
     */
    public function verifyRegistration(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'code'    => 'required|string|size:6',
        ]);

        $user = User::findOrFail($request->user_id);

        // Check if already verified
        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email déjà vérifié.',
            ], 422);
        }

        if (!$this->otpService->verify($user, $request->code)) {
            return response()->json([
                'message' => 'Code invalide ou expiré.',
            ], 422);
        }

        // Mark email as verified
        $user->update(['email_verified_at' => now()]);

        $token = $this->issueToken($user);

        ActivityLog::log('register', "{$user->name} a créé un compte ({$user->role})", $request, $user->id);

        return response()->json([
                'message' => 'Inscription réussie. Veuillez vous connecter.',
            'user'    => $user,
        ]);
    }

    /**
     * POST /api/auth/resend-registration-otp
     * Resend OTP for registration
     */
    public function resendRegistrationOtp(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($request->user_id);

        // Check if already verified
        if ($user->email_verified_at) {
            return response()->json([
                'message' => 'Email déjà vérifié.',
            ], 422);
        }

        $otp = $this->otpService->generate($user);

        $response = [
            'message' => 'Nouveau code envoyé.',
        ];

        // For development only - remove in production
        if (config('app.debug')) {
            $response['dev_code'] = $otp->code;
        }

        return response()->json($response);
    }

    /**
     * POST /api/auth/login
     * Step 1: Validate credentials, send OTP
     * Rate limited: 5 attempts per 5 minutes per email+IP
     */
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        // Rate limiting key: combination of email + IP
        $throttleKey = 'login:' . Str::lower($request->email) . '|' . $request->ip();
        $maxAttempts = 5;
        $decayMinutes = 5;

        // Check if currently locked out
        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            ActivityLog::log('login_blocked', "Tentative de connexion bloquée pour {$request->email} (trop de tentatives)", $request);

            return response()->json([
                'message' => 'Trop de tentatives de connexion. Veuillez réessayer.',
                'locked' => true,
                'retry_after' => $seconds,
            ], 429);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            // Increment failed attempt
            RateLimiter::hit($throttleKey, $decayMinutes * 60);

            $attemptsLeft = $maxAttempts - RateLimiter::attempts($throttleKey);

            ActivityLog::log('login_failed', "Échec de connexion pour {$request->email}", $request, $user?->id);

            $message = 'Email ou mot de passe incorrect.';
            if ($attemptsLeft <= 2 && $attemptsLeft > 0) {
                $message .= " Attention : il vous reste {$attemptsLeft} tentative(s).";
            }

            return response()->json([
                'message' => $message,
                'attempts_left' => $attemptsLeft,
            ], 401);
        }

        // Successful credentials -> clear rate limiter
        RateLimiter::clear($throttleKey);

        // Password correct -> send OTP
        $otp = $this->otpService->generate($user);

        $response = [
            'mfa_required' => true,
            'user_id'      => $user->id,
            'email'        => $user->email,
            'message'      => 'Code de vérification envoyé.',
        ];

        // For development only - remove in production
        if (config('app.debug')) {
            $response['dev_code'] = $otp->code;
        }
        return response()->json($response);
    }

    /**
     * POST /api/auth/verify-mfa
     * Step 2: Verify OTP, issue token
     */
    public function verifyMfa(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'code'    => 'required|string|size:6',
        ]);

        $user = User::findOrFail($request->user_id);

        if (!$this->otpService->verify($user, $request->code)) {
            return response()->json([
                'message' => 'Code invalide ou expiré.',
            ], 422);
        }

        $token = $this->issueToken($user);

        ActivityLog::log('login', "{$user->name} s'est connecté", $request, $user->id);

        return response()->json([
            'message' => 'Authentification réussie.',
            'token'   => $token,
            'role'    => $user->role,
            'user'    => $user,
        ]);
    }

    /**
     * POST /api/auth/resend-mfa
     * Resend a new OTP code
     */
    public function resendMfa(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($request->user_id);
        $otp = $this->otpService->generate($user);

        $response = [
            'message' => 'Nouveau code envoyé.',
        ];

        // For development only - remove in production
        if (config('app.debug')) {
            $response['dev_code'] = $otp->code;
        }

        return response()->json($response);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
            'role' => $request->user()?->role,
        ]);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if ($user) {
            ActivityLog::log('logout', "{$user->name} s'est déconnecté", $request, $user->id);
            $user->forceFill(['api_token' => null])->save();
        }

        return response()->json(['message' => 'Déconnecté avec succès.']);
    }

    /**
     * POST /api/auth/forgot-password
     * Send OTP to email for password reset
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Si cet email existe, un code de vérification a été envoyé.',
            ]);
        }

        $otp = $this->otpService->generate($user);

        $response = [
            'message' => 'Si cet email existe, un code de vérification a été envoyé.',
            'user_id' => $user->id,
            'email' => $user->email,
        ];

        if (config('app.debug')) {
            $response['dev_code'] = $otp->code;
        }

        return response()->json($response);
    }

    /**
     * POST /api/auth/reset-password
     * Verify OTP and set new password
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'user_id'  => 'required|exists:users,id',
            'code'     => 'required|string|size:6',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::findOrFail($request->user_id);

        if (!$this->otpService->verify($user, $request->code)) {
            return response()->json([
                'message' => 'Code invalide ou expiré.',
            ], 422);
        }

        $user->update(['password' => $request->password]);

        // Mark email as verified since OTP proves ownership
        if (!$user->email_verified_at) {
            $user->update(['email_verified_at' => now()]);
        }

        ActivityLog::log('password_reset', "{$user->name} a réinitialisé son mot de passe", $request, $user->id);

        return response()->json([
            'message' => 'Mot de passe réinitialisé avec succès. Veuillez vous connecter.',
        ]);
    }

    /**
     * POST /api/auth/resend-forgot-otp
     * Resend OTP for forgot password flow
     */
    public function resendForgotOtp(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($request->user_id);
        $otp = $this->otpService->generate($user);

        $response = [
            'message' => 'Nouveau code envoyé.',
        ];

        if (config('app.debug')) {
            $response['dev_code'] = $otp->code;
        }

        return response()->json($response);
    }

    private function issueToken(User $user): string
    {
        $plainToken = Str::random(60);

        $user->forceFill([
            'api_token' => hash('sha256', $plainToken),
        ])->save();

        return $plainToken;
    }
}
