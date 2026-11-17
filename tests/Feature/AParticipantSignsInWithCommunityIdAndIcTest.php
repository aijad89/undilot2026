<?php

use App\Models\Company;
use App\Models\User;

/*
 * Participants sign in with COMMUNITY ID + IC NUMBER and no password (owner's
 * decision 2026-11-17: participants have no email, so the emailed reset link
 * that sets a password could never reach them). Admins keep email + password.
 *
 * Both values must belong to the SAME account. The IC is compared as digits,
 * the ID trimmed and without regard to case.
 */

function idIcUser(array $attributes = []): User
{
    return User::factory()->create($attributes + ['community_id' => '07-3-8', 'ic_number' => '890508-12-0000']);
}

function signInWith(string $id, string $ic)
{
    return test()->post(route('login'), ['community_id' => $id, 'ic_number' => $ic]);
}

it('signs in with the ID and IC, however the IC is written', function (string $id, string $ic) {
    $user = idIcUser();

    signInWith($id, $ic)->assertRedirect(route('dashboard'));

    expect(auth('web')->id())->toBe($user->id);
})->with([
    'as stored' => ['07-3-8', '890508-12-0000'],
    'IC as bare digits' => ['07-3-8', '890508120000'],
    'IC with spaces' => ['07-3-8', '890508 12 0000'],
    'ID padded and in other case' => ['  07-3-8 ', '890508-12-0000'],
]);

it('refuses an ID and an IC that belong to two different people', function () {
    idIcUser();
    User::factory()->create(['community_id' => '09-1-1', 'ic_number' => '800601-10-0000']);

    signInWith('07-3-8', '800601-10-0000')->assertSessionHasErrors('community_id');

    expect(auth('web')->check())->toBeFalse();
});

it('refuses the right ID with the wrong IC, and the right IC with the wrong ID', function () {
    idIcUser();

    signInWith('07-3-8', '890508-12-0001')->assertSessionHasErrors('community_id');
    signInWith('07-3-9', '890508-12-0000')->assertSessionHasErrors('community_id');

    expect(auth('web')->check())->toBeFalse();
});

it('cannot sign in an account with no community ID', function () {
    idIcUser(['community_id' => null]);

    signInWith('', '890508-12-0000')->assertSessionHasErrors('community_id');

    expect(auth('web')->check())->toBeFalse();
});

it('signs in neither of two accounts holding the same ID and IC', function () {
    idIcUser();
    // The IC unique rule lives in the company form, not the schema, so the
    // pair can arrive twice by import or by hand. Nothing says which is meant.
    User::factory()->create(['community_id' => '07-3-8', 'ic_number' => '890508120000']);

    signInWith('07-3-8', '890508-12-0000')->assertSessionHasErrors('community_id');

    expect(auth('web')->check())->toBeFalse();
});

it('refuses a switched-off account, or a switched-off company, with the reason', function () {
    idIcUser(['is_active' => false]);

    signInWith('07-3-8', '890508-12-0000')
        ->assertSessionHasErrors(['community_id' => 'Your account has been deactivated. Please contact the administrator.']);
    expect(auth('web')->check())->toBeFalse();

    $other = User::factory()->create(['community_id' => '09-1-1', 'ic_number' => '800601-10-0000']);
    Company::factory()->create(['user_id' => $other->id, 'is_active' => false]);

    signInWith('09-1-1', '800601-10-0000')
        ->assertSessionHasErrors(['community_id' => 'Your company has been deactivated. Please contact the administrator.']);
    expect(auth('web')->check())->toBeFalse();
});

it('stops guessing after five wrong tries on one ID', function () {
    idIcUser();

    foreach (range(1, 5) as $n) {
        signInWith('07-3-8', "000000-00-000{$n}");
    }

    // The right IC now, and still refused: the counter is per ID, not per IC.
    signInWith('07-3-8', '890508-12-0000')->assertSessionHasErrors('community_id');

    expect(auth('web')->check())->toBeFalse();
});

it('asks for the ID and IC, and offers no password or reset link', function () {
    $this->get(route('login'))->assertOk()
        ->assertSee('name="community_id"', false)
        ->assertSee('name="ic_number"', false)
        ->assertDontSee('name="password"', false)
        ->assertDontSee('Forgot password');
});

it('gives a company created without an email a placeholder one, and requires the ID and IC', function () {
    $this->actingAs(superAdmin(), 'admin');

    $this->post(route('admin.companies.store'), [
        'name' => 'Kraf Tanpa Emel', 'state' => 'Johor', 'owner_name' => 'Aminah',
    ])->assertSessionHasErrors(['ic_number', 'community_id'])->assertSessionDoesntHaveErrors('email');

    $this->post(route('admin.companies.store'), [
        'name' => 'Kraf Tanpa Emel', 'state' => 'Johor', 'owner_name' => 'Aminah',
        'ic_number' => '900101-01-2222', 'community_id' => 'KOM-0003',
    ])->assertSessionHasNoErrors();

    expect(User::where('community_id', 'KOM-0003')->value('email'))->toBe('peserta900101012222@legacy.test');
});
