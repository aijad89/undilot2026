<?php

use App\Models\Admin;
use App\Models\User;
use App\Support\AdminZones;
use Illuminate\Support\Facades\DB;

/**
 * Renaming a zone is a scoping operation, not a label change.
 *
 * A state admin reaches a company only when `companies.state` equals their own
 * `admins.state` as a string. So a rename that edits the list and leaves the
 * columns behind cuts every admin holding the old spelling off from every row
 * still holding it — no error, no 404, just an empty screen. These hold
 * AdminZones::rename() to moving all three columns with the list.
 */
it('carries admins, companies and events across when a zone is renamed', function () {
    $admin = Admin::factory()->create(['state' => 'Langkawi']);

    DB::table('companies')->insert([
        'user_id' => User::factory()->create()->id,
        'name' => 'Kraf Langkawi',
        'state' => 'Langkawi',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('events')->insert([
        'title' => 'Pesta Kraf',
        'location' => 'Kuah',
        'state' => 'Langkawi',
        'start_date' => now(),
        'end_date' => now()->addDay(),
        'registration_open_at' => now(),
        'registration_close_at' => now()->addDay(),
        'max_participants' => 10,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(AdminZones::rename('Langkawi', 'Langkawi dan Kedah Utara'))->toBe(3);

    expect($admin->fresh()->state)->toBe('Langkawi dan Kedah Utara')
        ->and(DB::table('companies')->where('state', 'Langkawi dan Kedah Utara')->count())->toBe(1)
        ->and(DB::table('events')->where('state', 'Langkawi dan Kedah Utara')->count())->toBe(1)
        ->and(AdminZones::all())->toContain('Langkawi dan Kedah Utara')
        ->and(AdminZones::all())->not->toContain('Langkawi');
});

it('reports a zone as in use while any of the three columns still holds it', function () {
    expect(AdminZones::isInUse('Perlis'))->toBeFalse();

    Admin::factory()->create(['state' => 'Perlis']);

    expect(AdminZones::isInUse('Perlis'))->toBeTrue()
        ->and(AdminZones::usageForAll()['Perlis'])
        ->toBe(['admins' => 1, 'companies' => 0, 'events' => 0]);
});

/*
 * K08. Renaming "Perlis" to "Kedah" used to move every Perlis row under Kedah:
 * two zones merged, each zone admin seeing the other's companies, no warning.
 */
it('refuses to rename a zone onto another zone, in any letter case, and moves nothing', function (string $to) {
    $perlis = Admin::factory()->create(['role' => 'admin', 'state' => 'Perlis']);
    $kedah = Admin::factory()->create(['role' => 'admin', 'state' => 'Kedah']);

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.settings.zones.rename'), ['from' => 'Perlis', 'to' => $to])
        ->assertSessionHasErrors(['to' => 'There is already a zone called "Kedah". Renaming "Perlis" to it would merge the two zones. Choose another name.']);

    expect($perlis->fresh()->state)->toBe('Perlis')
        ->and($kedah->fresh()->state)->toBe('Kedah')
        ->and(AdminZones::all())->toContain('Perlis', 'Kedah');
})->with(['Kedah', 'kedah', ' KEDAH ']);

it('still lets a zone change only the letter case of its own name', function () {
    $perlis = Admin::factory()->create(['role' => 'admin', 'state' => 'Perlis']);

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.settings.zones.rename'), ['from' => 'Perlis', 'to' => 'PERLIS'])
        ->assertSessionHasNoErrors();

    expect($perlis->fresh()->state)->toBe('PERLIS')
        ->and(AdminZones::all())->toContain('PERLIS')
        ->and(AdminZones::all())->not->toContain('Perlis');
});
