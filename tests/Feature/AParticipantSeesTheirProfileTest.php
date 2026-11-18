<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/**
 * A participant's profile: their account and company as the office keeps them,
 * read-only. No password: they sign in with community ID + IC.
 */
function profileOwner(): Company
{
    $user = User::factory()->create([
        'name' => 'Siti Aminah', 'phone' => '012-345 6789',
        'ic_number' => '800101-14-5678', 'password' => 'lama1234',
    ]);

    return Company::factory()->create(['user_id' => $user->id, 'name' => 'Seramik Klang Studio', 'state' => 'Selangor', 'plot_size' => '3x6', 'kraf_code' => 'TTK']);
}

it('shows the account and company as the office keeps them, the IC number in full', function () {
    $company = profileOwner();

    $this->actingAs($company->user, 'web')->get(route('profile'))
        ->assertOk()
        ->assertSeeInOrder(['Your account', 'Siti Aminah', '012-345 6789', '800101-14-5678'])
        ->assertSeeInOrder(['Your company', 'Seramik Klang Studio', 'Selangor', '3x6'])
        ->assertDontSee('Your company on record')
        // The craft code is the office's, never shown to the participant.
        ->assertDontSee('TTK');
});

it('is reached from the menu, and only when signed in', function () {
    $this->get(route('profile'))->assertRedirect(route('login'));

    $company = profileOwner();
    $this->actingAs($company->user, 'web')->get(route('dashboard'))
        ->assertSee('href="'.route('profile').'"', false);
});

it('has no password to change: participants sign in with community ID + IC', function () {
    $company = profileOwner();

    $this->actingAs($company->user, 'web')->get(route('profile'))->assertOk()
        ->assertDontSee('Change password')
        ->assertDontSee('current_password', false);

    // The endpoint is gone, not just unlinked.
    expect(Route::has('profile.password'))->toBeFalse();
    $this->put(url('/profile/password'), ['current_password' => 'lama1234', 'password' => 'baharu123', 'password_confirmation' => 'baharu123'])
        ->assertNotFound();
    expect(Hash::check('lama1234', $company->user->fresh()->password))->toBeTrue();
});

it('shows the community ID they sign in with', function () {
    $company = profileOwner();
    $company->user->update(['community_id' => 'KOM-0042']);

    $this->actingAs($company->user, 'web')->get(route('profile'))
        ->assertSeeInOrder(['Community ID', 'KOM-0042', 'Mobile phone'])
        // No email line at all: the project keeps none.
        ->assertDontSee('<dt>Email</dt>', false);
});

it('stacks its two cards down one column: account, company', function () {
    $this->actingAs(profileOwner()->user, 'web')->get(route('profile'))->assertOk()
        ->assertSeeInOrder(['class="ap-profile-stack"', 'Your account', 'Your company'], false)
        // The two side-by-side columns are gone, not just restyled.
        ->assertDontSee('class="ap-dash-side"', false)
        ->assertDontSee('ap-dash-grid ap-profile-grid', false);
});

it('shows the address the office keeps, its line breaks kept, or a dash when there is none', function () {
    $company = profileOwner();
    $company->user->update(['address' => "No. 3, Jalan Seramik,\n41000 Klang"]);

    $this->actingAs($company->user, 'web')->get(route('profile'))->assertOk()
        ->assertSeeInOrder(['Your account', 'IC number', 'Address', "No. 3, Jalan Seramik,\n41000 Klang", 'Your company'], false);

    $company->user->update(['address' => null]);

    $this->get(route('profile'))
        ->assertSeeInOrder(['<dt>Address</dt>', '—', 'Your company'], false);
});

it('is as wide as every other portal page: the column sets no width of its own', function () {
    $html = $this->actingAs(profileOwner()->user, 'web')->get(route('profile'))->getContent();

    preg_match('/\.ap-profile-stack\s*\{([^}]*)\}/', $html, $rule);

    expect($rule)->not->toBeEmpty()
        ->and(preg_match('/\b(max-)?width\s*:/', $rule[1]))->toBe(0);
});
