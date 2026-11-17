<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Plot;
use App\Models\Poll;
use App\Support\AdminZones;
use Database\Seeders\DemoSeeder;
use Database\Seeders\KrafCodeSeeder;
use Database\Seeders\DemoUserSeeder;

/**
 * The demo seeder plants one state admin and one company per zone, and running
 * it twice adds nothing — so it keeps working as the schema moves.
 */
it('plants one admin and one company per zone, and is safe to run twice', function () {
    $this->seed([KrafCodeSeeder::class, DemoSeeder::class]);
    $this->seed(DemoSeeder::class);

    $zones = count(AdminZones::DEFAULTS);
    // One per zone from DemoUserSeeder; DemoScenarioSeeder adds Johor extras on top.
    $perZone = Company::whereHas('user', fn ($q) => $q->whereIn('community_id', array_map(fn ($z) => DemoUserSeeder::communityIdFor($z), AdminZones::DEFAULTS)));

    expect(Admin::where('role', 'admin')->distinct()->count('state'))->toBe($zones)
        ->and(Admin::where('role', 'admin')->count())->toBe($zones)
        ->and(Company::distinct()->count('state'))->toBe($zones)
        ->and($perZone->clone()->count())->toBe($zones)
        ->and($perZone->clone()->whereNull('kraf_code')->count())->toBe(0)
        ->and($perZone->clone()->whereDoesntHave('krafSelections')->count())->toBe(0)
        ->and(EventRegistration::whereHas('event', fn ($q) => $q->where('title', 'Demo Kraf Fair 2027'))->whereIn('company_id', $perZone->clone()->pluck('id'))->count())->toBe($zones);
});

it('plants open, coming and past events, the past ones with lots and slips, every company entered by the office', function () {
    $this->seed([KrafCodeSeeder::class, DemoSeeder::class]);
    $counts = fn () => [Event::count(), EventRegistration::count(), Plot::count(), Poll::count()];
    $first = $counts();

    $past = Event::where('end_date', '<', now())->get();
    expect($past)->toHaveCount(7)
        ->and($past->every(fn ($e) => $e->status === 'completed' && $e->polling_status === 'completed'))->toBeTrue()
        // Open for registration now: three, and one more coming later.
        ->and(Event::get()->filter(fn ($e) => $e->status === 'open' && $e->polling_status === 'draft'
            && now()->between($e->registration_open_at, $e->registration_close_at))->count())->toBeGreaterThanOrEqual(3)
        ->and(Event::where('registration_open_at', '>', now())->where('status', 'open')->exists())->toBeTrue();

    // Every lot of a past draw belongs to a row on its own event's list, and is
    // confirmed. (The "Uji:" draws hold presets, which are not confirmed yet.)
    $lots = EventRegistration::whereIn('event_id', $past->pluck('id'))->whereNotNull('plot_id')->with('plot')->get();
    expect($lots)->not->toBeEmpty()
        ->and($lots->every(fn ($r) => $r->plot->event_id === $r->event_id && $r->plot->company_id === $r->company_id))->toBeTrue()
        ->and($lots->every(fn ($r) => Poll::where('user_id', $r->user_id)->where('event_id', $r->event_id)->exists()))->toBeTrue();

    // Every row on a past draw was verified — the office enters every company,
    // so there is nothing left pending once the draw has run.
    $rows = EventRegistration::whereIn('event_id', $past->pluck('id'))->get();
    expect($rows)->not->toBeEmpty()
        ->and($rows->every(fn ($r) => $r->verified_at !== null))->toBeTrue();

    // A second run adds nothing.
    $this->seed(DemoSeeder::class);
    expect($counts())->toBe($first);
});
