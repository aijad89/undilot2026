<?php

use App\Models\Company;
use App\Models\CompanyKraf;
use App\Models\KrafBidang;
use App\Models\KrafPengelasan;
use App\Models\KrafProduk;
use App\Models\KrafSubBidang;

/**
 * Craft Hierarchy → Companies: who picked a category, narrowed by sub-category,
 * classification and product. The filters hold on ONE selection together.
 */
function tekstilWithCompanies(): array
{
    $tekstil = KrafBidang::factory()->create(['name' => 'TEKSTIL']);
    $batik = KrafSubBidang::factory()->create(['bidang_id' => $tekstil->id, 'name' => 'Batik']);
    $songket = KrafSubBidang::factory()->create(['bidang_id' => $tekstil->id, 'name' => 'Songket']);
    $canting = KrafPengelasan::factory()->create(['sub_bidang_id' => $batik->id, 'name' => 'Batik canting']);
    $pakaian = KrafProduk::factory()->create(['sub_bidang_id' => $batik->id, 'name' => 'Apparel']);
    $kain = KrafProduk::factory()->create(['sub_bidang_id' => $songket->id, 'name' => 'Fabric']);

    // Batik, canting, apparel: all on one selection.
    $alpha = Company::factory()->create(['name' => 'ALPHA BATIK']);
    $a = CompanyKraf::factory()->create(['company_id' => $alpha->id, 'bidang_id' => $tekstil->id, 'sub_bidang_id' => $batik->id]);
    $a->pengelasan()->attach($canting);
    $a->produks()->attach($pakaian);

    // Canting on its Batik selection, Fabric on a separate Songket one.
    $beta = Company::factory()->create(['name' => 'BETA TENUN']);
    $b1 = CompanyKraf::factory()->create(['company_id' => $beta->id, 'bidang_id' => $tekstil->id, 'sub_bidang_id' => $batik->id]);
    $b1->pengelasan()->attach($canting);
    $b2 = CompanyKraf::factory()->create(['company_id' => $beta->id, 'bidang_id' => $tekstil->id, 'sub_bidang_id' => $songket->id]);
    $b2->produks()->attach($kain);

    // Another category entirely.
    $rimba = KrafBidang::factory()->create(['name' => 'HASIL RIMBA']);
    $gamma = Company::factory()->create(['name' => 'GAMMA ROTAN']);
    CompanyKraf::factory()->create(['company_id' => $gamma->id, 'bidang_id' => $rimba->id]);

    return compact('tekstil', 'batik', 'songket', 'canting', 'pakaian', 'kain');
}

it('links each category in Craft Hierarchy to its companies', function () {
    $t = tekstilWithCompanies();

    $this->actingAs(superAdmin(), 'admin')->get(route('admin.settings.kraf-hierarchy'))
        ->assertOk()
        ->assertSee(route('admin.settings.kraf-hierarchy.companies', $t['tekstil']), false);
});

it('lists the companies that picked the category, and no others', function () {
    $t = tekstilWithCompanies();

    $this->actingAs(superAdmin(), 'admin')->get(route('admin.settings.kraf-hierarchy.companies', $t['tekstil']))
        ->assertOk()
        ->assertSee('ALPHA BATIK')
        ->assertSee('BETA TENUN')
        ->assertDontSee('GAMMA ROTAN');
});

it('filters by sub-category, classification and product', function () {
    $t = tekstilWithCompanies();
    $url = fn (array $q) => route('admin.settings.kraf-hierarchy.companies', [$t['tekstil'], ...$q]);
    $admin = superAdmin();

    $this->actingAs($admin, 'admin')->get($url(['sub' => $t['songket']->id]))
        ->assertSee('BETA TENUN')->assertDontSee('ALPHA BATIK');

    $this->actingAs($admin, 'admin')->get($url(['pengelasan' => $t['canting']->id]))
        ->assertSee('ALPHA BATIK')->assertSee('BETA TENUN');

    $this->actingAs($admin, 'admin')->get($url(['produk' => $t['pakaian']->id]))
        ->assertSee('ALPHA BATIK')->assertDontSee('BETA TENUN');
});

it('matches the filters on one selection, not across two', function () {
    $t = tekstilWithCompanies();

    // BETA has canting (on Batik) and Fabric (on Songket), never together.
    $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.settings.kraf-hierarchy.companies', [
            $t['tekstil'], 'pengelasan' => $t['canting']->id, 'produk' => $t['kain']->id,
        ]))
        ->assertOk()
        ->assertDontSee('BETA TENUN')
        ->assertSee('No company matches those filters.');
});

it('is for super and HQ only, like the rest of Settings', function () {
    $t = tekstilWithCompanies();

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.settings.kraf-hierarchy.companies', $t['tekstil']))
        ->assertForbidden();
});

it('answers only for a category, not a craft code row', function () {
    $code = KrafBidang::factory()->create(['name' => 'Batik Pelangi', 'code' => 'TBPX']);

    $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.settings.kraf-hierarchy.companies', $code))
        ->assertNotFound();
});

it('resets one company under the category, keeps the company and its other categories', function () {
    $t = tekstilWithCompanies();
    $beta = Company::where('name', 'BETA TENUN')->sole();
    $rimba = KrafBidang::where('name', 'HASIL RIMBA')->sole();
    CompanyKraf::factory()->create(['company_id' => $beta->id, 'bidang_id' => $rimba->id]);

    $this->actingAs(superAdmin(), 'admin')
        ->delete(route('admin.settings.kraf-hierarchy.companies.reset', [$t['tekstil'], $beta]))
        ->assertSessionHas('success');

    expect($beta->krafSelections()->where('bidang_id', $t['tekstil']->id)->exists())->toBeFalse()
        ->and($beta->krafSelections()->where('bidang_id', $rimba->id)->count())->toBe(1)
        ->and(Company::whereKey($beta->id)->exists())->toBeTrue()
        // The classification and product ticks went with their selections.
        ->and(DB::table('company_kraf_pengelasan')->count())->toBe(1)   // ALPHA's only
        ->and(DB::table('company_kraf_produk')->count())->toBe(1);
});

it('resets only what the filters match when resetting all', function () {
    $t = tekstilWithCompanies();

    // Songket only: BETA loses its Songket selection, keeps Batik; ALPHA untouched.
    $this->actingAs(superAdmin(), 'admin')
        ->delete(route('admin.settings.kraf-hierarchy.companies.reset-all', [$t['tekstil'], 'sub' => $t['songket']->id]))
        ->assertRedirect(route('admin.settings.kraf-hierarchy.companies', $t['tekstil']));

    expect(CompanyKraf::where('sub_bidang_id', $t['songket']->id)->exists())->toBeFalse()
        ->and(CompanyKraf::where('sub_bidang_id', $t['batik']->id)->count())->toBe(2);
});

it('resets the whole category when no filter is set, and leaves other categories', function () {
    $t = tekstilWithCompanies();

    $this->actingAs(superAdmin(), 'admin')
        ->delete(route('admin.settings.kraf-hierarchy.companies.reset-all', $t['tekstil']));

    expect(CompanyKraf::where('bidang_id', $t['tekstil']->id)->exists())->toBeFalse()
        ->and(CompanyKraf::count())->toBe(1)          // GAMMA's HASIL RIMBA
        ->and(Company::count())->toBe(3);
});

it('writes an audit line for each selection it removes', function () {
    $t = tekstilWithCompanies();

    $this->actingAs(superAdmin(), 'admin')
        ->delete(route('admin.settings.kraf-hierarchy.companies.reset-all', $t['tekstil']));

    expect(\App\Models\AuditTrail::where('auditable_type', CompanyKraf::class)->where('action', 'deleted')->count())
        ->toBe(3);
});

it('lets no state admin reset anything', function () {
    $t = tekstilWithCompanies();
    $admin = stateAdmin('Johor');

    $this->actingAs($admin, 'admin')
        ->delete(route('admin.settings.kraf-hierarchy.companies.reset-all', $t['tekstil']))
        ->assertForbidden();
    $this->actingAs($admin, 'admin')
        ->delete(route('admin.settings.kraf-hierarchy.companies.reset', [$t['tekstil'], Company::first()]))
        ->assertForbidden();

    expect(CompanyKraf::count())->toBe(4);
});

it('resets the whole category from its card in Craft Hierarchy, and returns to that card', function () {
    $t = tekstilWithCompanies();
    $admin = superAdmin();

    $this->actingAs($admin, 'admin')->get(route('admin.settings.kraf-hierarchy'))
        ->assertSee(route('admin.settings.kraf-hierarchy.companies.reset-all', $t['tekstil']), false);

    $this->actingAs($admin, 'admin')
        ->from(route('admin.settings.kraf-hierarchy'))
        ->delete(route('admin.settings.kraf-hierarchy.companies.reset-all', $t['tekstil']), ['from' => 'hierarchy'])
        ->assertRedirect(route('admin.settings.kraf-hierarchy').'#category-'.$t['tekstil']->id)
        ->assertSessionHas('anchor', 'category-'.$t['tekstil']->id);

    expect(CompanyKraf::where('bidang_id', $t['tekstil']->id)->exists())->toBeFalse()
        ->and(Company::count())->toBe(3);
});

it('shows category names with only the first word capitalised, and stores them unchanged', function () {
    $t = tekstilWithCompanies();
    $admin = superAdmin();

    $this->actingAs($admin, 'admin')->get(route('admin.settings.kraf-hierarchy'))
        ->assertSee('Tekstil')->assertSee('Hasil rimba')->assertDontSee('TEKSTIL');

    $this->actingAs($admin, 'admin')->get(route('admin.settings.kraf-hierarchy.companies', $t['tekstil']))
        ->assertSee('Tekstil: Companies')->assertDontSee('TEKSTIL');

    expect($t['tekstil']->fresh()->name)->toBe('TEKSTIL');
});
