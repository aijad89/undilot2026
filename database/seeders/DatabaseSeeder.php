<?php

namespace Database\Seeders;

use App\Support\DevEnvironment;
use Illuminate\Database\Seeder;

/**
 * Not a data seeder — a split.
 *
 * ABOVE THE LINE: what an empty installation needs before anyone can use it.
 * That is one super admin, because every screen that creates an admin is behind
 * the admin login; and the craft coding sheet with its five categories, because
 * no company can be given a craft, and no draw a code, without them.
 *
 * BELOW THE LINE: demo data (DemoSeeder), which must never run on a live box.
 * It runs only in DevEnvironment::NAMES (local, testing, e2e) — an ALLOWLIST,
 * so a server whose APP_ENV is `staging`, mistyped or missing gets reference
 * data only, not demo data. DemoSeeder extends LocalOnlySeeder, which refuses
 * the same environments on its own, including `db:seed --class=DemoSeeder`.
 *
 * Strict mode in AppServiceProvider still keys off isProduction(): that one is
 * about catching bugs, and failing open there costs nothing but a missed warning.
 * Demo data failing open deletes real events, so it asks the stricter question.
 *
 * The reason it exists: in the system this came from, a bare `db:seed` on a live
 * server created nineteen admin logins with a four-digit password. Nothing had
 * refused; the seeder simply did what it was told.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminSeeder::class);

        // The coding sheet and the five craft categories. Re-runnable: codes
        // match on `code`, categories on name.
        $this->call(KrafCodeSeeder::class);

        // Sub-categories, classifications and products under those categories,
        // from the reference file. Adds only, so re-running is safe.
        if (\Illuminate\Support\Facades\Artisan::call('kraf:import-hierarchy', ['file' => database_path('data/hierarki-kraf.csv')]) !== 0) {
            throw new \RuntimeException('Craft hierarchy did not load: '.\Illuminate\Support\Facades\Artisan::output());
        }
        $this->command?->info(trim(\Illuminate\Support\Facades\Artisan::output()));

        if (! DevEnvironment::here()) {
            $this->command?->newLine();
            $this->command?->warn('Environment "'.app()->environment().'": reference data only. No demo data was planted.');

            return;
        }

        // Demo data, behind the guard: sample craft hierarchy, 14 state admins
        // + HQ, 14 participants with companies, two events.
        $this->call(DemoSeeder::class);
    }
}
