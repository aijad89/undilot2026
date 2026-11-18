<?php

use App\Models\Company;
use App\Models\CompanyKraf;

/**
 * Once a company has craft selections, any one can be made the primary from its
 * row. There is one primary at a time; the button is on the others only.
 */
it('makes another selection the primary, leaving one primary', function () {
    $company = Company::factory()->create(['state' => 'Johor']);
    $first = CompanyKraf::factory()->primary()->create(['company_id' => $company->id]);
    $second = CompanyKraf::factory()->create(['company_id' => $company->id]);
    $admin = superAdmin();

    $this->actingAs($admin, 'admin')->get(route('admin.companies.edit', $company))
        ->assertOk()
        ->assertSee(route('admin.companies.kraf.primary', [$company, $second]), false)
        ->assertDontSee(route('admin.companies.kraf.primary', [$company, $first]), false);

    $this->actingAs($admin, 'admin')
        ->from(route('admin.companies.edit', $company))
        ->put(route('admin.companies.kraf.primary', [$company, $second]))
        ->assertRedirect(route('admin.companies.edit', $company).'#craft-categories')
        ->assertSessionHas('success', 'Primary category changed.');

    expect($second->fresh()->is_primary)->toBeTrue()
        ->and($first->fresh()->is_primary)->toBeFalse()
        ->and($company->krafSelections()->where('is_primary', true)->count())->toBe(1);
});

it('refuses a selection of another company, and a company outside the admin zone', function () {
    $company = Company::factory()->create(['state' => 'Johor']);
    $other = CompanyKraf::factory()->create();

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.companies.kraf.primary', [$company, $other]))
        ->assertNotFound();

    $kedah = Company::factory()->create(['state' => 'Kedah']);
    $selection = CompanyKraf::factory()->create(['company_id' => $kedah->id]);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->put(route('admin.companies.kraf.primary', [$kedah, $selection]))
        ->assertForbidden();

    expect($other->fresh()->is_primary)->toBeFalse()
        ->and($selection->fresh()->is_primary)->toBeFalse();
});
