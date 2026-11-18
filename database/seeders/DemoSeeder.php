<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use Illuminate\Support\Facades\Artisan;

/**
 * DEMO DATA — never on the live server (it extends LocalOnlySeeder, which
 * refuses every APP_ENV but local, testing and e2e). Plant it on purpose with
 * `db:seed --class=DemoSeeder`.
 *
 * Plants, in order: the craft hierarchy (database/data), 14 state admins + 1 HQ, 14
 * participants with a company each, and two events — one with its draw list
 * filled from those companies; then DemoEventsSeeder's open, coming and past
 * events; then DemoScenarioSeeder's "Uji: …" events, one per state the manual
 * test sheet needs. Re-runnable: everything is matched by name, username or community ID first.
 */
class DemoSeeder extends LocalOnlySeeder
{
    public function run(): void
    {
        $this->hierarchy();
        $this->call([DemoAdminSeeder::class, DemoUserSeeder::class]);
        $this->events();
        $this->call(DemoEventsSeeder::class);
        $this->call(DemoScenarioSeeder::class);
    }

    /**
     * The real craft hierarchy, not a sample: DemoUserSeeder hands each demo
     * company one of its sub-categories. Adds only; DatabaseSeeder has usually
     * loaded it already.
     */
    private function hierarchy(): void
    {
        Artisan::call('kraf:import-hierarchy', ['file' => database_path('data/hierarki-kraf.csv')]);
    }

    private function events(): void
    {
        $fair = Event::firstOrCreate(['title' => 'Demo Kraf Fair 2027'], [
            'description' => 'Demo event with a filled draw list.',
            'location' => 'MATIC, Kuala Lumpur',
            'state' => 'Selangor, Kuala Lumpur dan Putrajaya',
            'start_date' => now()->addMonths(2)->setTime(10, 0),
            'end_date' => now()->addMonths(2)->addDays(4)->setTime(22, 0),
            'registration_open_at' => now()->subWeek(),
            'registration_close_at' => now()->addMonth(),
            'status' => 'open',
            'polling_status' => 'draft',
            'polling_start_date' => now()->addWeeks(3)->setTime(9, 0),
            'polling_deadline' => now()->addWeeks(4)->setTime(17, 0),
            'max_participants' => 100,
        ]);

        // Filled once, when the event is new: a re-run would otherwise add every
        // company planted since, such as DemoScenarioSeeder's Johor extras.
        foreach ($fair->wasRecentlyCreated ? Company::whereNotNull('kraf_code')->get() : [] as $company) {
            EventRegistration::firstOrCreate(
                ['event_id' => $fair->id, 'company_id' => $company->id],
                ['user_id' => $company->user_id, 'code' => $company->kraf_code, 'plot_size' => $company->plot_size ?? '3x3', 'verified_at' => now(), 'first_verified_at' => now()],
            );
        }

        Event::firstOrCreate(['title' => 'Demo Draft Expo'], [
            'description' => 'Demo draft event — hidden from state admins.',
            'location' => 'Dewan Demo, Ipoh',
            'state' => 'Perak',
            'start_date' => now()->addMonths(4)->setTime(10, 0),
            'end_date' => now()->addMonths(4)->addDays(2)->setTime(22, 0),
            'registration_open_at' => now()->addMonth(),
            'registration_close_at' => now()->addMonths(3),
            'status' => 'draft',
            'polling_status' => 'draft',
            'max_participants' => 50,
        ]);
    }
}
