<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;
use Illuminate\Support\Facades\DB;

/**
 * DEMO DATA. Three draws that are open right now, with every participant in all
 * three and nobody having confirmed yet — the state you want in front of you to
 * work on the lot reveal, the dashboard stepper or the confirm flow, where the
 * only thing missing is the press of the button.
 *
 * It CLEARS every event first, on purpose: the point is three events and not
 * three more on top of DemoSeeder's own. Registrations go before plots, because
 * `event_registrations.plot_id` carries no ON DELETE clause and the database
 * refuses to drop a plot a draw entry still points at.
 *
 * Every condition PollingGuard asks about is satisfied here, which is the whole
 * reason this is a seeder and not a throwaway script — getting all seven right
 * by hand, twice, is how you end up with a draw whose button refuses and no
 * sign of why:
 *
 *   a polling window, plots, every row verified by HQ, an account behind every
 *   company, a craft code on every row, a plot carrying each of those codes, and
 *   enough free lots at each (code, size) pair.
 *
 * Never on the live server: it extends LocalOnlySeeder, which refuses every
 * APP_ENV but local, testing and e2e — it once had no check of its own, and
 * `db:seed --class` on a server would have wiped every real draw. It is also
 * not called by default at all — it is
 * opt-in with `db:seed --class=DemoThreeEventsSeeder`, because wiping every
 * event is not something a bare `db:seed` should ever do.
 */
class DemoThreeEventsSeeder extends LocalOnlySeeder
{
    /** Three draws, each a different shape of event the office runs. */
    private const EVENTS = [
        ['Hari Kraf Kebangsaan 2026', 'Kompleks Kraf Kuala Lumpur'],
        ['Festival Kraf Johor 2026', 'Angsana Johor Bahru Mall'],
        ['Anugerah Adiguru Kraf Negara 2026', 'Padang Akasia, Shah Alam'],
    ];

    private const LOCATION = 'Dewan Utama';

    public function run(): void
    {
        $companies = Company::with('user')
            ->where('is_active', true)
            ->whereHas('user')
            ->get();

        if ($companies->isEmpty()) {
            $this->command?->warn('No participant companies to seed a draw with. Run DemoSeeder first.');

            return;
        }

        // Codes to fall back on, so a company whose craft code the office never
        // set still carries one: PollingGuard blocks a draw on a single blank.
        $codes = KrafBidang::codes()->active()->orderBy('bil')->get();

        if ($codes->isEmpty()) {
            $this->command?->warn('No craft codes. Run KrafCodeSeeder first.');

            return;
        }

        $this->wipeEvents();

        foreach (self::EVENTS as $i => [$title, $location]) {
            $this->plant($title, $location, $i, $companies, $codes);
        }

        $this->command?->info(
            'Three open draws, '.$companies->count().' participants in each, nobody confirmed yet.'
        );
    }

    /**
     * Registrations first, then plots, then the events themselves.
     *
     * Truncate is no use here: these tables are tied together by foreign keys,
     * and MySQL refuses to truncate the referenced side of one.
     */
    private function wipeEvents(): void
    {
        DB::transaction(function () {
            DB::table('polls')->delete();
            EventRegistration::query()->delete();
            Plot::query()->delete();
            PlotSize::query()->delete();
            Event::query()->delete();
        });
    }

    private function plant(string $title, string $location, int $i, $companies, $codes): void
    {
        // Staggered a day apart so the three are distinguishable on a list that
        // sorts by date, and all three are open NOW.
        $event = Event::create([
            'title' => $title,
            'description' => 'Data demo. Cabutan dibuka, setiap peserta dalam senarai, tiada siapa mengesahkan lagi.',
            'location' => $location,
            'state' => null,
            'start_date' => now()->addWeeks(4 + $i),
            'end_date' => now()->addWeeks(5 + $i),
            'registration_open_at' => now()->subWeeks(2),
            'registration_close_at' => now()->addWeeks(2),
            'auto_open' => false,
            'auto_close' => false,
            'status' => 'open',
            'polling_status' => 'polling',
            'polling_start_date' => now()->subHours(1),
            // PollingGuard's first question. Every participant screen names when
            // the draw closes, so a draw without this cannot open at all.
            'polling_deadline' => now()->addDays(7 + $i),
            'auto_polling_open' => false,
            'auto_polling_close' => false,
            'use_preset' => false,
            'max_participants' => max(50, $companies->count() * 2),
            'banner' => null,
            'colour' => null,
            'total_plots' => 0,
        ]);

        // (location, size) is what a plot_sizes row IS — one row per pair, and the
        // plan is built from the booth sizes the registrations actually carry.
        $sizeRows = [];
        $plotCount = 0;

        foreach ($companies as $n => $company) {
            $code = $company->kraf_code ?: $codes[$n % $codes->count()]->code;
            $size = $company->plot_size ?: '3x3';

            $registration = EventRegistration::create([
                'event_id' => $event->id,
                'company_id' => $company->id,
                'user_id' => $company->user->id,
                'code' => $code,
                'code_updated_at' => now(),
                'plot_size' => $size,
                'plot_id' => null,
                'confirmed_participation' => false,
                'confirmed_at' => null,
                // `reminded_at` is NOT named here, and must not be: the model keeps
                // it out of $fillable because the command that queues the close
                // reminder is the only thing that stamps it. Seeders run inside
                // Model::unguarded(), so naming it would have been written rather
                // than refused — the guard that would catch this anywhere else in
                // the app does not apply in here.
                // HQ has done its check: PollingGuard holds a draw while any row
                // waits, and first_verified_at is what stops the participant
                // being told twice.
                'verified_by' => null,
                'verified_at' => now(),
                'first_verified_at' => now(),
                'hq_note' => null,
                'payment_note' => 'Resit demo '.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT),
                'payment_status' => 'paid',
            ]);

            $sizeRows[$size] ??= PlotSize::create([
                'event_id' => $event->id,
                'size' => $size,
                'quantity' => 0,
                'location' => self::LOCATION,
            ]);

            // One lot per registration at its own (code, size), so the draw has
            // exactly enough to finish and every participant can win.
            $bidang = $codes->firstWhere('code', $code) ?? $codes->first();
            $plotCount++;

            Plot::create([
                'event_id' => $event->id,
                'plot_size_id' => $sizeRows[$size]->id,
                'company_id' => null,
                'allocated_to' => null,
                'bidang_id' => $bidang->id,
                'sub_bidang_id' => null,
                'plot_code' => Plot::codeFor($code, $registration->id),
                'is_allocated' => false,
                'allocation_type' => 'algorithm',
                'is_locked' => false,
                'allocated_at' => null,
            ]);
        }

        // `quantity` is the count of lots standing at that (location, size), and
        // `total_plots` is counted here rather than typed — the same rule the lot
        // plan screen follows.
        foreach ($sizeRows as $size => $row) {
            $row->update([
                'quantity' => Plot::where('event_id', $event->id)
                    ->where('plot_size_id', $row->id)
                    ->count(),
            ]);
        }

        $event->update(['total_plots' => $plotCount]);
    }
}
