<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreApiTokenRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    public function store(StoreApiTokenRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $token = $user->createToken($request->string('name')->toString());

        // Flashed once, rendered on the next load of the settings page, then gone.
        return redirect()->route('settings')
            ->with('success', 'API token created.')
            ->with('createdToken', [
                'name' => $token->accessToken->name,
                'plainText' => $token->plainTextToken,
            ]);
    }

    public function destroy(Request $request, string $token): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // Scoped to the acting user's own tokens: another user's id deletes nothing.
        $user->tokens()->whereKey($token)->delete();

        return redirect()->route('settings')->with('success', 'API token revoked.');
    }
}
