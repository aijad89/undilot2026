<?php

use App\Models\Company;
use App\Models\Event;
use App\Support\DevEnvironment;
use Database\Seeders\BatikDrawSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DemoThreeEventsSeeder;
use Database\Seeders\LocalOnlySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Demo seeders refuse every environment outside DevEnvironment::NAMES, and so
 * do the commands that wipe the whole schema.
 *
 * The case that made this matter: DemoThreeEventsSeeder deletes EVERY event,
 * draw list, lot and Confirm record before planting. It had no environment
 * check of its own, so `db:seed --class=DemoThreeEventsSeeder` on the live
 * server would have wiped every real draw result, with no audit row (it
 * deletes in bulk, which fires no model events).
 *
 * Production, staging and a mistyped name are all tried, because the guard is
 * an allowlist: anything it does not recognise is refused, not just the exact
 * string "production".
 */

/** Seeders that plant what an empty installation needs; they run anywhere. */
const REFERENCE_SEEDERS = ['AdminSeeder', 'KrafCodeSeeder', 'DatabaseSeeder', 'LocalOnlySeeder'];

/** What someone on the server would type: production asks "Are you sure?" without --force. */
function seedForced(string $seeder): int
{
    return Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
}

afterEach(function () {
    app()['env'] = 'testing';
    DB::prohibitDestructiveCommands(false);
});

it('makes every seeder that is not reference data extend LocalOnlySeeder', function () {
    $unguarded = collect(glob(database_path('seeders/*.php')))
        ->map(fn ($path) => basename($path, '.php'))
        ->reject(fn ($name) => in_array($name, REFERENCE_SEEDERS, true))
        ->reject(fn ($name) => is_subclass_of("Database\\Seeders\\{$name}", LocalOnlySeeder::class))
        ->values()
        ->all();

    // A new demo seeder must extend LocalOnlySeeder; a new reference seeder
    // must be added to REFERENCE_SEEDERS above, on purpose.
    expect($unguarded)->toBe([]);
});

it('refuses to run DemoThreeEventsSeeder outside dev, and deletes nothing', function (string $env) {
    Company::factory()->create();
    $event = Event::factory()->create();

    app()['env'] = $env;

    expect(fn () => seedForced(DemoThreeEventsSeeder::class))
        ->toThrow(RuntimeException::class, 'runs only when APP_ENV is one of');

    expect(Event::count())->toBe(1)
        ->and(Event::find($event->id))->not->toBeNull();
})->with(['production', 'staging', 'prodution']);

it('refuses BatikDrawSeeder and DemoSeeder outside dev too', function (string $seeder) {
    app()['env'] = 'staging';

    expect(fn () => seedForced($seeder))->toThrow(RuntimeException::class, 'runs only when APP_ENV is one of');

    expect(Company::count())->toBe(0);
})->with([BatikDrawSeeder::class, DemoSeeder::class]);

it('refuses migrate:fresh and db:wipe outside dev, and the data stays', function (string $env, string $command) {
    $event = Event::factory()->create();

    app()['env'] = $env;
    DevEnvironment::lockDestructiveCommands();

    expect(Artisan::call($command, ['--force' => true]))->toBe(1)
        ->and(Event::find($event->id))->not->toBeNull();
})->with(['production', 'staging'])->with(['migrate:fresh', 'db:wipe']);

it('leaves the destructive commands unlocked in local, testing and e2e', function (string $env) {
    app()['env'] = $env;

    expect(DevEnvironment::here())->toBeTrue();
})->with(DevEnvironment::NAMES);
