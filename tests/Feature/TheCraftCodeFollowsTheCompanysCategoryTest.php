<?php

use App\Models\Company;
use App\Models\CompanyKraf;
use App\Models\KrafBidang;
use Database\Seeders\KrafCodeSeeder;

/**
 * The craft code dropdown on a company offers only the codes of the craft
 * categories that company has picked: a TEKSTIL company sees TBPS, TST…, not RKP.
 */
function pickCategory(Company $company, string $category): void
{
    $cat = KrafBidang::categories()->where('name', $category)->firstOrFail();
    CompanyKraf::create([
        'company_id' => $company->id,
        'bidang_id' => $cat->id,
        'sub_bidang_id' => $cat->subBidangs()->create(['name' => 'Sub '.$category])->id,
        'is_primary' => true,
    ]);
}

beforeEach(fn () => $this->seed(KrafCodeSeeder::class));

it('offers a Tekstil company only Tekstil codes', function () {
    $company = Company::factory()->create(['state' => 'Kedah']);
    pickCategory($company, 'TEKSTIL');

    $page = $this->actingAs(superAdmin(), 'admin')->get(route('admin.companies.edit', $company))->assertOk();

    $page->assertSee('<option value="TBPS"', false)->assertSee('<option value="TST"', false);
    $page->assertDontSee('<option value="RKP"', false)->assertDontSee('<option value="LEH"', false);
});

it('refuses a code from a category the company has not picked', function () {
    $company = Company::factory()->create(['state' => 'Kedah', 'kraf_code' => null]);
    pickCategory($company, 'TEKSTIL');

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.companies.kraf-code.update', $company), ['kraf_code' => 'RKP'])
        ->assertSessionHasErrors('kraf_code');

    $this->put(route('admin.companies.kraf-code.update', $company), ['kraf_code' => 'TBPS'])
        ->assertSessionHasNoErrors();

    expect($company->fresh()->kraf_code)->toBe('TBPS');
});

it('offers codes of every picked category, and every code when none is picked', function () {
    $both = Company::factory()->create(['state' => 'Kedah']);
    pickCategory($both, 'TEKSTIL');
    pickCategory($both, 'HASIL RIMBA');
    $none = Company::factory()->create(['state' => 'Kedah']);

    $this->actingAs(superAdmin(), 'admin')->get(route('admin.companies.edit', $both))
        ->assertSee('<option value="TBPS"', false)->assertSee('<option value="RKP"', false);

    $this->get(route('admin.companies.edit', $none))
        ->assertSee('<option value="TBPS"', false)->assertSee('<option value="RKP"', false);
});
