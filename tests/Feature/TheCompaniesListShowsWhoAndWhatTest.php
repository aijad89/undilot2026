<?php

use App\Models\Company;
use App\Models\CompanyKraf;
use App\Models\KrafBidang;
use App\Models\KrafSubBidang;
use App\Models\User;

/**
 * The Companies list in the office's column order — owner, IC, community ID,
 * company, craft category and sub-category, zone.
 */
function listedCompany(string $name, string $zone, array $owner = []): Company
{
    return Company::factory()->create([
        'name' => $name,
        'state' => $zone,
        'user_id' => User::factory()->create($owner)->id,
    ]);
}

it('shows the owner, IC, community ID, category and sub-category of each company', function () {
    $company = listedCompany('Batik Seri', 'Perak', [
        'name' => 'Aminah Yusof', 'ic_number' => '880101-08-1234', 'community_id' => 'KOM-0042',
    ]);
    $category = KrafBidang::factory()->create(['name' => 'TEKSTIL']);
    CompanyKraf::factory()->create([
        'company_id' => $company->id, 'bidang_id' => $category->id,
        'sub_bidang_id' => KrafSubBidang::factory()->create(['bidang_id' => $category->id, 'name' => 'Batik Lukis'])->id,
    ]);

    $this->actingAs(stateAdmin('Perak'), 'admin')->get(route('admin.companies.index'))
        ->assertOk()
        ->assertSeeInOrder(['Aminah Yusof', '880101-08-1234', 'KOM-0042', 'Batik Seri', 'TEKSTIL', 'Batik Lukis', 'Perak']);
});

it('saves a community ID from the company form', function () {
    $company = listedCompany('Tenun Jaya', 'Perak');

    $this->actingAs(superAdmin(), 'admin')->put(route('admin.companies.update', $company), [
        'name' => 'Tenun Jaya', 'state' => 'Perak',
        'owner_name' => $company->user->name,
        'community_id' => 'KOM-0099', 'ic_number' => $company->user->ic_number, 'is_active' => '1', 'account_active' => '1',
    ])->assertSessionHas('success');

    expect($company->user->fresh()->community_id)->toBe('KOM-0099');
});

it('finds a company by IC or community ID', function () {
    listedCompany('Wanted Co', 'Perak', ['ic_number' => '900202-08-5555', 'community_id' => 'KOM-0777']);
    listedCompany('Other Co', 'Perak');
    listedCompany('Johor Co', 'Johor', ['community_id' => 'KOM-0777']);
    $admin = stateAdmin('Perak');

    foreach (['900202', 'KOM-0777'] as $search) {
        $this->actingAs($admin, 'admin')->get(route('admin.companies.index', ['search' => $search]))
            ->assertSee('Wanted Co')->assertDontSee('Other Co')->assertDontSee('Johor Co');
    }
});
