<?php

use App\Models\Company;
use App\Models\User;

/*
 * The account card on the company form carries the owner's postal address:
 * typed by the office, stored on the user, read back into the form.
 */

function addressedCompany(?string $address = null): Company
{
    return Company::factory()->create([
        'name' => 'Batik Alamat', 'state' => 'Perak',
        'user_id' => User::factory()->create(['address' => $address])->id,
    ]);
}

function companyPut(Company $company, array $overrides = []): array
{
    return array_merge([
        'name' => $company->name, 'state' => 'Perak',
        'owner_name' => $company->user->name, 'email' => $company->user->email,
        'ic_number' => $company->user->ic_number, 'community_id' => 'KOM-0001',
        'is_active' => '1', 'account_active' => '1',
    ], $overrides);
}

it('saves the address from the edit form, and shows it back', function () {
    $company = addressedCompany();
    $address = "No. 12, Jalan Batik 3,\n31400 Ipoh, Perak";

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.companies.update', $company), companyPut($company, ['address' => $address]))
        ->assertSessionHas('success');

    expect($company->user->fresh()->address)->toBe($address);

    $this->get(route('admin.companies.edit', $company))->assertOk()
        ->assertSee('<textarea id="address" name="address"', false)
        ->assertSee(e($address), false);
});

it('saves the address when the company is created', function () {
    $this->actingAs(superAdmin(), 'admin')->post(route('admin.companies.store'), [
        'name' => 'Baru Beralamat', 'state' => 'Perak',
        'owner_name' => 'Siti', 'email' => 'siti@example.test',
        'ic_number' => '900101-08-1111', 'community_id' => 'KOM-0002',
        'address' => 'Lot 5, Kampung Kraf, Kuala Kangsar',
    ])->assertSessionHasNoErrors();

    expect(User::where('email', 'siti@example.test')->value('address'))->toBe('Lot 5, Kampung Kraf, Kuala Kangsar');
});

it('clears the address when the field is emptied', function () {
    $company = addressedCompany('Old address');

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.companies.update', $company), companyPut($company, ['address' => '']))
        ->assertSessionHas('success');

    expect($company->user->fresh()->address)->toBeNull();
});

it('refuses an address longer than 500 characters', function () {
    $company = addressedCompany('Kept');

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.companies.update', $company), companyPut($company, ['address' => str_repeat('a', 501)]))
        ->assertSessionHasErrors('address');

    expect($company->user->fresh()->address)->toBe('Kept');
});

it('puts the password fields side by side, under one note', function () {
    $html = $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.companies.edit', addressedCompany()))->getContent();

    // Both fields come before the note: the note no longer sits under one of
    // them, making it taller than its neighbour.
    expect(strpos($html, 'id="password"'))->toBeLessThan(strpos($html, 'id="password_confirmation"'))
        ->and(strpos($html, 'id="password_confirmation"'))->toBeLessThan(strpos($html, 'Leave both empty to keep the current password.'));
});
