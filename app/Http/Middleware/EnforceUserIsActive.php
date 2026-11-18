<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Ejects a `web` user whose own account, or whose company, has been deactivated.
 *
 * This is the ONLY place that enforcement happens for the web guard. Deactivation is
 * decided elsewhere — an admin toggles `users.is_active` or `companies.is_active` — and
 * without this middleware on a route group, an already-logged-in user keeps their session
 * and keeps working. Removing it from a group re-admits deactivated accounts silently.
 *
 * It was called `TrackUserLastSeen`, alias `track.last-seen`, until 2026-09-09. The name
 * described the second half of the method and hid the first: a route group reading
 * `['auth', 'track.last-seen']` looks like it carries bookkeeping that could be trimmed.
 *
 * The last-seen write stays here as a documented side effect rather than moving to its own
 * middleware: it is two cache reads and one throttled `saveQuietly()` on a request that has
 * already loaded the user, and splitting it would put a second class on every user route.
 * On the admin guard the two halves are two classes: `EnforceAdminIsActive` ends the
 * session of a switched-off admin, and `TrackAdminLastSeen` does only the tracking.
 */
class EnforceUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        // `user('web')`, not `user()`. The unnamed form resolves the DEFAULT
        // guard, and this class enforces a column only `users` has — so once
        // the default is `admin`, it reads `is_active` off an Admin. Outside
        // production that is a 500 (preventAccessingMissingAttributes); in
        // production the attribute is simply absent, which is falsy, so the
        // branch below fires: it invalidates the session — ending the ADMIN's
        // session, since there is only one — and redirects to the participant
        // login saying their account has been deactivated.
        //
        // `TrackAdminLastSeen`, the twin on the other guard, already names
        // `user('admin')`. This is that same line.
        $user = $request->user('web');

        if ($user) {
            if (! $user->is_active || ($user->company && ! $user->company->is_active)) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                // withErrors, not with('error'): the login page shows only
                // $errors and `status`, so a flashed `error` is never seen.
                return redirect()->route('login')->withErrors(['community_id' => $user->is_active
                    ? 'Your company has been deactivated. Please contact the administrator.'
                    : 'Your account has been deactivated. Please contact the administrator.']);
            }

            Cache::put("online_status_web_{$user->id}", now(), now()->addMinutes(5));

            $dbKey = "last_seen_db_user_{$user->id}";
            if (!Cache::has($dbKey)) {
                $user->last_seen_at = now();
                $user->saveQuietly();
                Cache::put($dbKey, true, now()->addSeconds(60));
            }
        }

        return $next($request);
    }
}
