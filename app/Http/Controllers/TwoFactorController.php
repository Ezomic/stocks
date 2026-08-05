<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactorAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorAuthenticator $authenticator) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $user->forceFill([
            'two_factor_secret' => $this->authenticator->generateSecret(),
            'two_factor_recovery_codes' => $this->authenticator->generateRecoveryCodes(),
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect()->route('settings')
            ->with('success', 'Scan the QR code, then confirm with a code to finish enabling two-factor authentication.');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $request->validate(['code' => ['required', 'string']]);

        if ($user->two_factor_secret === null) {
            return redirect()->route('settings')
                ->with('error', 'Start the setup again, there is no pending secret.');
        }

        if (! $this->authenticator->verify($user, $request->string('code')->toString())) {
            return redirect()->route('settings')->withErrors(['code' => 'That code is not valid.']);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return redirect()->route('settings')
            ->with('success', 'Two-factor authentication is on. Store your recovery codes somewhere safe.');
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $user->forceFill([
            'two_factor_recovery_codes' => $this->authenticator->generateRecoveryCodes(),
        ])->save();

        return redirect()->route('settings')->with('success', 'New recovery codes generated. The old ones no longer work.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect()->route('settings')->with('success', 'Two-factor authentication is off.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        return $user;
    }
}
