<?php

use App\Models\Admin;

/*
 * An admin account can be switched off (admins.is_active). Super switches off
 * HQ and state admins, HQ switches off state admins — the same reach as every
 * other edit on these screens. A switched-off admin cannot sign in, and one
 * already signed in is put out on their next click.
 */

dataset('switchable admins', [
    'state admin' => fn () => Admin::factory()->create(['password' => 'kraf1234']),
    'hq' => fn () => Admin::factory()->hq()->create(['password' => 'kraf1234']),
]);

/* ── Signing in ──────────────────────────────────────────────────────── */

it('refuses to sign in a switched-off admin, saying why only to the right password', function (Admin $admin) {
    $admin->update(['is_active' => false]);

    $this->post(route('admin.login'), ['username' => $admin->username, 'password' => 'kraf1234'])
        ->assertSessionHasErrors(['username' => 'This admin account has been switched off. Contact HQ to have it switched back on.']);
    $this->assertGuest('admin');

    // A wrong password learns nothing about the account.
    $this->post(route('admin.login'), ['username' => $admin->username, 'password' => 'wrong-one1'])
        ->assertSessionHasErrors(['username' => trans('auth.failed')]);
    $this->assertGuest('admin');
})->with('switchable admins');

it('signs in once switched back on', function (Admin $admin) {
    $admin->update(['is_active' => false]);
    $admin->update(['is_active' => true]);

    $this->post(route('admin.login'), ['username' => $admin->username, 'password' => 'kraf1234'])
        ->assertRedirect(route('admin.dashboard'));
    $this->assertAuthenticatedAs($admin, 'admin');
})->with('switchable admins');

it('puts out an admin already signed in, on their next click', function (Admin $admin) {
    // A real sign-in, so the session carries it. actingAs() does not write the
    // session: after forgetGuards() the next request would be a plain guest,
    // put out by auth:admin, and this would pass without the middleware.
    $this->post(route('admin.login'), ['username' => $admin->username, 'password' => 'kraf1234']);
    auth()->forgetGuards();
    $this->get(route('admin.dashboard'))->assertOk();

    $admin->update(['is_active' => false]);
    auth()->forgetGuards();

    $this->get(route('admin.dashboard'))
        ->assertRedirect(route('admin.login'))
        ->assertSessionHasErrors('username');
    $this->assertGuest('admin');
})->with('switchable admins');

/* ── Who may switch whom ─────────────────────────────────────────────── */

it('lets super switch off and on both HQ and state admins', function (Admin $target) {
    $this->actingAs(superAdmin(), 'admin');

    $this->patch(route('admin.admins.active', $target))->assertSessionHas('success');
    expect($target->fresh()->is_active)->toBeFalse();

    $this->patch(route('admin.admins.active', $target))->assertSessionHas('success');
    expect($target->fresh()->is_active)->toBeTrue();
})->with('switchable admins');

it('lets HQ switch off a state admin, and no one else', function () {
    $hq = Admin::factory()->hq()->create();
    $this->actingAs($hq, 'admin');

    $state = stateAdmin();
    $this->patch(route('admin.admins.active', $state))->assertSessionHas('success');
    expect($state->fresh()->is_active)->toBeFalse();

    $peer = Admin::factory()->hq()->create();
    $super = superAdmin();
    $this->patch(route('admin.admins.active', $peer))->assertForbidden();
    $this->patch(route('admin.admins.active', $super))->assertForbidden();
    // Nor through the edit form.
    $this->put(route('admin.admins.update', $peer), [
        'name' => $peer->name, 'username' => $peer->username, 'role' => 'admin', 'state' => 'Johor',
    ])->assertForbidden();

    expect($peer->fresh()->is_active)->toBeTrue()
        ->and($super->fresh()->is_active)->toBeTrue();
});

it('keeps a state admin off the switch altogether', function () {
    $this->actingAs(stateAdmin(), 'admin')
        ->patch(route('admin.admins.active', stateAdmin('Perak')))->assertForbidden();
});

it('never switches off your own account, by button or by form', function () {
    $me = superAdmin();
    superAdmin();   // so the last-super floor is not what refuses it
    $this->actingAs($me, 'admin');

    $this->patch(route('admin.admins.active', $me))->assertSessionHasErrors();
    $this->put(route('admin.admins.update', $me), [
        'name' => $me->name, 'username' => $me->username, 'role' => 'super',   // no is_active: unticked
    ])->assertSessionHas('success');

    expect($me->fresh()->is_active)->toBeTrue();

    $this->get(route('admin.admins.edit', $me))
        ->assertSee('Your own account stays switched on.')
        ->assertDontSee('id="is_active"', false);
});

/* ── The edit form and the list ──────────────────────────────────────── */

it('switches an admin off and on from the edit form', function () {
    $this->actingAs(superAdmin(), 'admin');
    $state = stateAdmin();
    $form = ['name' => $state->name, 'username' => $state->username, 'role' => 'admin', 'state' => 'Johor'];

    $this->put(route('admin.admins.update', $state), $form)->assertSessionHas('success');   // unticked
    expect($state->fresh()->is_active)->toBeFalse();

    $this->put(route('admin.admins.update', $state), $form + ['is_active' => '1'])->assertSessionHas('success');
    expect($state->fresh()->is_active)->toBeTrue();
});

it('creates an admin switched on when the box stays ticked, off when it is cleared', function () {
    $this->actingAs(superAdmin(), 'admin');
    $base = ['role' => 'admin', 'state' => 'Johor', 'password' => 'kraf12345', 'password_confirmation' => 'kraf12345'];

    $this->post(route('admin.admins.store'), $base + ['name' => 'On', 'username' => 'on', 'is_active' => '1']);
    $this->post(route('admin.admins.store'), $base + ['name' => 'Off', 'username' => 'off']);

    expect(Admin::where('username', 'on')->value('is_active'))->toBeTruthy()
        ->and(Admin::where('username', 'off')->value('is_active'))->toBeFalsy();

    // And the create form starts ticked — that box, not some other.
    $html = $this->get(route('admin.admins.create', ['group' => 'state']))->getContent();
    expect($html)->toMatch('/<input[^>]*id="is_active"[^>]*\bchecked\b[^>]*>/s');
});

it('shows each admin\'s status on the list, with the switch beside every account but your own', function () {
    $me = superAdmin();
    $on = stateAdmin('Johor', ['name' => 'Aktif Satu']);
    $off = stateAdmin('Perak', ['name' => 'Mati Dua', 'is_active' => false]);

    $html = $this->actingAs($me, 'admin')->get(route('admin.admins.state'))->assertOk()
        ->assertSeeInOrder(['Aktif Satu', 'Active', 'Switch off'])
        ->assertSeeInOrder(['Mati Dua', 'Inactive', 'Switch on'])
        ->getContent();

    expect($html)->toContain(route('admin.admins.active', $on))
        ->toContain(route('admin.admins.active', $off));

    $hqList = $this->get(route('admin.admins.hq'))->getContent();
    expect($hqList)->not->toContain(route('admin.admins.active', $me));
});

/* ── The floor ───────────────────────────────────────────────────────── */

it('refuses to switch off the last active super', function () {
    $last = superAdmin();
    superAdmin(['is_active' => false]);   // a switched-off super does not count
    $this->actingAs(Admin::factory()->hq()->create(), 'admin');

    expect(fn () => $last->update(['is_active' => false]))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect($last->fresh()->is_active)->toBeTrue();
});

it('counts only an active super when deleting or demoting the last one', function () {
    $last = superAdmin();
    superAdmin(['is_active' => false]);

    expect(fn () => $last->delete())->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and(fn () => $last->update(['role' => 'hq']))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('lets one super switch off another while a third stays on', function () {
    $me = superAdmin();
    $other = superAdmin();

    $this->actingAs($me, 'admin')->patch(route('admin.admins.active', $other))->assertSessionHas('success');

    expect($other->fresh()->is_active)->toBeFalse();
});
