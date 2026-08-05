<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly TwoFactorAuthenticator $authenticator) {}

    public function show(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request) instanceof User) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $key = 'two-factor|'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors(['code' => "Too many attempts. Try again in {$seconds} seconds."]);
        }

        $recoveryCode = $request->string('recovery_code')->toString();

        $passed = $recoveryCode !== ''
            ? $this->authenticator->consumeRecoveryCode($user, $recoveryCode)
            : $this->authenticator->verify($user, $request->string('code')->toString());

        if (! $passed) {
            RateLimiter::hit($key);

            return back()->withErrors(['code' => 'That code is not valid.']);
        }

        RateLimiter::clear($key);

        $remember = $request->session()->pull('two_factor.remember', false);
        $request->session()->forget('two_factor.user_id');

        Auth::login($user, $remember === true);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('two_factor.user_id');

        return is_int($id) ? User::find($id) : null;
    }
}
