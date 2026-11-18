<?php

use App\Models\Company;
use App\Models\CompanyKraf;
use App\Models\KrafBidang;
use Database\Seeders\KrafCodeSeeder;

/**
 * HASIL LOGAM is stored one row per sub-category. The company's craft table
 * names the category once, on the first of its rows, and keeps its rows together.
 */
it('names HASIL LOGAM once over its sub-category rows, kept together', function () {
    $this->seed(KrafCodeSeeder::class);
    $logam = KrafBidang::categories()->where('name', 'HASIL LOGAM')->firstOrFail();
    $tekstil = KrafBidang::categories()->where('name', 'TEKSTIL')->firstOrFail();
    $company = Company::factory()->create(['state' => 'Kedah']);

    $row = fn ($cat, $sub, $primary = false) => CompanyKraf::create([
        'company_id' => $company->id, 'bidang_id' => $cat->id, 'is_primary' => $primary,
        'sub_bidang_id' => $cat->subBidangs()->create(['name' => $sub])->id,
    ]);
    $row($logam, 'Perak', true);
    $row($tekstil, 'Batik');
    $row($logam, 'Tembaga'); // added later, after a Tekstil row

    $html = $this->actingAs(superAdmin(), 'admin')->get(route('admin.companies.edit', $company))
        ->assertOk()->getContent();
    $table = substr($html, strpos($html, 'id="craft-categories"'), 6000);

    expect(substr_count($table, 'HASIL LOGAM'))->toBe(1)
        ->and(strpos($table, 'Tembaga'))->toBeLessThan(strpos($table, 'Batik'));
});
