<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $accountIsActive = $user instanceof User
            && User::query()
                ->whereKey($user->getAuthIdentifier())
                ->whereNull('disabled_at')
                ->exists();

        if ($accountIsActive) {
            return $next($request);
        }

        if ($user instanceof User) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        abort(403, __('This account is disabled.'));
    }
}
