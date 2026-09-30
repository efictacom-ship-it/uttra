<?php

declare(strict_types=1);

namespace App\Modules\Identity\Controllers;

use App\Modules\Identity\Models\User;
use App\Shared\Audit\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController
{
    public function __construct(private readonly AuditService $audit) {}

    public function show(): Response|RedirectResponse
    {
        if (User::query()->count() === 0) {
            return redirect()->route('setup.show');
        }

        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ], [], [
            'email' => 'البريد الإلكتروني',
            'password' => 'كلمة المرور',
        ]);

        $key = 'login:'.strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'محاولات كثيرة. انتظر '.RateLimiter::availableIn($key).' ثانية ثم أعد المحاولة.',
            ]);
        }

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password'], 'is_active' => true], (bool) ($data['remember'] ?? false))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['email' => 'بيانات الدخول غير صحيحة.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $this->audit->log('user', Auth::id(), 'login');

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->audit->log('user', Auth::id(), 'logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function showPassword(): Response
    {
        return Inertia::render('Auth/ChangePassword');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Auth::validate(['email' => $user->email, 'password' => $data['current_password']])) {
            throw ValidationException::withMessages(['current_password' => 'كلمة المرور الحالية غير صحيحة.']);
        }

        $user->setPassword($data['password']);
        $user->save();
        $this->audit->log('user', $user->id, 'password_changed');

        return back()->with('success', 'تم تحديث كلمة المرور.');
    }
}
