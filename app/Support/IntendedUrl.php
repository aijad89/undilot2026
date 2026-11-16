<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where to send someone after they sign in: the page they were bounced from,
 * but only if it belongs to the side of the app they just signed in to.
 *
 * TWO GUARDS SHARE ONE SESSION KEY. Both `auth` and `auth:admin` store the
 * bounced-from page in `url.intended`. Open /dashboard while signed out (it
 * stores /dashboard), then sign in at /admin/login: a plain intended() sent the
 * admin to /dashboard — where an admin is a guest on the `web` guard — and on to
 * the participant login form, looking as if the admin sign-in had failed (K09).
 * The mirror case sent a participant to an admin page and the admin login.
 *
 * A URL from the other side is dropped, not kept for later: it is stale by
 * definition, and keeping it would ambush the next sign-in instead.
 *
 * Compared against url('admin'), not a hard-coded '/admin', so it holds when
 * the app is served from a subpath (CLAUDE.md: the app must stay subpath-safe).
 */
final class IntendedUrl
{
    public static function redirect(Request $request, bool $admin, string $fallback): RedirectResponse
    {
        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && self::isAdminUrl($intended) === $admin) {
            return redirect()->to($intended);
        }

        return redirect()->to($fallback);
    }

    public static function isAdminUrl(string $url): bool
    {
        $base = url('admin');

        return $url === $base
            || str_starts_with($url, $base.'/')
            || str_starts_with($url, $base.'?');
    }
}
