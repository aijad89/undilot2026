<?php

use App\Models\Admin;
use App\Models\User;

/*
 * Signing out, on both guards.
 *
 * Signed in through the real forms, NOT actingAs(): actingAs() puts a user on
 * the guard in memory, so a sign-out that destroys the session would look like
 * it did nothing, and one that forgot to would look like it worked. Between
 * requests the guards are forgotten, as a fresh request would have them.
 *
 * WHAT SIGNING OUT MUST DO: end the session, not merely forget the user. Both
 * doors call `invalidate()` — a new session id, the old data gone, a new CSRF
 * token — so a session id copied before sign-out is worth nothing after it.
 * (`regenerateToken()` after `invalidate()` is belt and braces: invalidate()
 * already flushes the old token. Removing it was tried as a mutation and
 * survived, correctly.)
 * One consequence, measured and locked below: the two guards share one session,
 * so signing out of either signs a browser out of both. Changing that means
 * keeping session data alive past a sign-out, and should be a decision.
 */

function signInParticipant($test): User
{
    $user = User::factory()->create(['community_id' => '07-3-8', 'ic_number' => '890508-12-0000']);
    $test->post(route('login'), ['community_id' => '07-3-8', 'ic_number' => '890508-12-0000']);
    auth()->forgetGuards();

    return $user;
}

function signInAdmin($test): Admin
{
    $admin = Admin::factory()->super()->create(['password' => 'kraf1234']);
    $test->post(route('admin.login'), ['username' => $admin->username, 'password' => 'kraf1234']);
    auth()->forgetGuards();

    return $admin;
}

it('signs a participant out to the public home page, and their pages close behind them', function () {
    signInParticipant($this);
    $this->get(route('dashboard'))->assertOk();
    auth()->forgetGuards();

    $this->post(route('logout'))->assertRedirect(route('home'));   // named: subpath-safe
    auth()->forgetGuards();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    expect(auth('web')->check())->toBeFalse();
});

it('signs an admin out to the admin sign-in, and the admin screens close behind them', function () {
    signInAdmin($this);
    $this->get(route('admin.dashboard'))->assertOk();
    auth()->forgetGuards();

    $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
    auth()->forgetGuards();

    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    expect(auth('admin')->check())->toBeFalse();
});

it('ends the session itself, with a new id and a new CSRF token', function (string $who) {
    $who === 'participant' ? signInParticipant($this) : signInAdmin($this);

    $idBefore = session()->getId();
    $tokenBefore = session()->token();

    $this->post(route($who === 'participant' ? 'logout' : 'admin.logout'));

    expect(session()->getId())->not->toBe($idBefore)
        ->and(session()->token())->not->toBe($tokenBefore);
})->with(['participant', 'admin']);

it('kills a "remember me" sign-in too, so the cookie cannot sign anyone back in', function (string $who) {
    // invalidate() alone empties the session and LOOKS like a sign-out. The
    // remember cookie lives outside the session, though: only the guard's own
    // logout() expires it and rotates remember_token, which is what makes a
    // copy of the cookie worthless. Both sign-in forms offer "Remember me".
    $guard = $who === 'participant' ? 'web' : 'admin';
    $model = $who === 'participant'
        ? User::factory()->create(['community_id' => '07-3-8', 'ic_number' => '890508-12-0000'])
        : Admin::factory()->super()->create(['password' => 'kraf1234']);

    $recaller = auth($guard)->getRecallerName();

    $signIn = $this->post(route($who === 'participant' ? 'login' : 'admin.login'), [
        ...($who === 'participant'
            ? ['community_id' => '07-3-8', 'ic_number' => '890508-12-0000']
            : ['username' => $model->username, 'password' => 'kraf1234']),
        'remember' => 'on',
    ])->assertCookie($recaller);

    $tokenBefore = $model->fresh()->remember_token;
    expect($tokenBefore)->not->toBeNull();
    auth()->forgetGuards();

    // The test client does not carry cookies from one response to the next
    // request; a browser does, and logout() only expires a cookie it was sent.
    $this->withCookie($recaller, $signIn->getCookie($recaller)->getValue())
        ->post(route($who === 'participant' ? 'logout' : 'admin.logout'))
        ->assertCookieExpired($recaller);

    expect($model->fresh()->remember_token)->not->toBe($tokenBefore);
})->with(['participant', 'admin']);

it('signs a browser out of both guards, because they share one session', function (string $door) {
    signInParticipant($this);
    signInAdmin($this);

    $this->get(route('dashboard'))->assertOk();
    auth()->forgetGuards();
    $this->get(route('admin.dashboard'))->assertOk();
    auth()->forgetGuards();

    $this->post(route($door));
    auth()->forgetGuards();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    auth()->forgetGuards();
    $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
})->with(['logout', 'admin.logout']);

it('signs out only by POST, so a link or an image cannot sign anyone out', function (string $door) {
    $this->get(route($door))->assertMethodNotAllowed();
})->with(['logout', 'admin.logout']);

it('sends a signed-out visitor who posts sign-out to the right sign-in, not an error', function () {
    $this->post(route('logout'))->assertRedirect(route('login'));
    auth()->forgetGuards();
    $this->post(route('admin.logout'))->assertRedirect(route('admin.login'));
});
