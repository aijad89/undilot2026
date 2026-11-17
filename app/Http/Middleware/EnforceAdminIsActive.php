<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ejects an admin whose account has been switched off (`admins.is_active`).
 *
 * The login refuses a switched-off account, but that only stops the NEXT
 * sign-in. An admin already signed in — or holding a remember-me cookie —
 * would keep working until they chose to leave. This is what makes the
 * switch take effect on their very next click. Take it off the admin group
 * and switching an admin off waits for them to sign out.
 *
 * The admin-guard twin of EnforceUserIsActive. TrackAdminLastSeen stays
 * presence tracking only; the two are separate so that each name says what
 * it does.
 */
class EnforceAdminIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $admin = $request->user('admin');

        if ($admin && ! $admin->is_active) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors([
                'username' => 'This admin account has been switched off. Contact HQ to have it switched back on.',
            ]);
        }

        return $next($request);
    }
}
