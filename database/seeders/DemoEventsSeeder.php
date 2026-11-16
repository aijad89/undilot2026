<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;
use App\Models\Poll;

/**
 * DEMO DATA — never on the live server: it extends LocalOnlySeeder, which
 * refuses every APP_ENV but local, testing and e2e. Plant it alone with
 * `db:seed --class=DemoEventsSeeder`.
 *
 * Fills the participant's screens with something to look at: events open for
 * registration, one coming soon, one already in a draw list, and past events
 * that the demo companies took part in, each with its lots and slips, entered
 * by the office the way every company is.
 *
 * Re-runnable and additive: an event is matched on its title, and one that
 * already exists is left exactly as it is, children and all. Nothing is ever
 * deleted or changed.
 */
class DemoEventsSeeder extends LocalOnlySeeder
{
    /** title => [location, zone, months from now it starts] */
    private const OPEN = [
        'Karnival Kraf Johor 2027' => ['Persada Johor, Johor Bahru', 'Johor', 2],
        'Ekspo Kraf Borneo 2027' => ['Pusat Konvensyen Borneo, Kuching', 'Sarawak', 3],
        'Pesta Batik & Songket Terengganu 2027' => ['Dataran Syahbandar, Kuala Terengganu', 'Terengganu', 3],
    ];

    /** title => [location, zone, months ago it ended] */
    private const PAST = [
        'Hari Kraf Kebangsaan 2026' => ['Kompleks Kraf Kuala Lumpur', 'Selangor, Kuala Lumpur dan Putrajaya', 2],
        'Pesta Kraf Pantai Timur 2026' => ['Dataran Tok Janggut, Kota Bharu', 'Kelantan', 5],
        'Ekspo Kraf Melaka 2026' => ['MITC, Ayer Keroh', 'Melaka', 8],
        'Karnival Kraf Sabah 2025' => ['Pusat Pameran SICC, Kota Kinabalu', 'Sabah', 13],
        'Hari Kraf Kebangsaan 2025' => ['Kompleks Kraf Kuala Lumpur', 'Selangor, Kuala Lumpur dan Putrajaya', 14],
        'Pesta Kraf Utara 2025' => ['Dataran Alor Setar, Alor Setar', 'Kedah', 17],
        'Ekspo Kraf Pahang 2025' => ['Dataran Kuantan, Kuantan', 'Pahang', 20],
    ];

    public function run(): void
    {
        $faker = fake('ms_MY');
        $companies = Company::whereHas('user', fn ($q) => $q->where('email', 'like', '%@demo.test'))
            ->whereNotNull('kraf_code')
            ->with('user')
            ->get();

        foreach (self::OPEN as $title => [$location, $zone, $months]) {
            $this->event($title, $location, $zone, [
                'start_date' => now()->addMonths($months)->startOfDay()->setTime(10, 0),
                'end_date' => now()->addMonths($months)->addDays(4)->startOfDay()->setTime(22, 0),
                'registration_open_at' => now()->subDays(5),
                'registration_close_at' => now()->addWeeks(3 + $months),
                'status' => 'open',
                'polling_status' => 'draft',
            ], $faker);
        }

        $this->event('Festival Seni Kraf Perak 2027', 'Dataran Ipoh, Ipoh', 'Perak', [
            'description' => 'Registration opens next month.',
            'start_date' => now()->addMonths(5)->startOfDay()->setTime(10, 0),
            'end_date' => now()->addMonths(5)->addDays(3)->startOfDay()->setTime(22, 0),
            'registration_open_at' => now()->addMonth(),
            'registration_close_at' => now()->addMonths(3),
            'status' => 'open',
            'polling_status' => 'draft',
        ], $faker);

        // Already in a draw list: the dashboard shows it as a draw waiting to open.
        $drawn = $this->event('Pameran Kraf Negeri Sembilan 2027', 'Dataran Seremban, Seremban', 'Negeri Sembilan', [
            'start_date' => now()->addMonths(2)->addWeeks(2)->startOfDay()->setTime(10, 0),
            'end_date' => now()->addMonths(2)->addWeeks(2)->addDays(3)->startOfDay()->setTime(22, 0),
            'registration_open_at' => now()->subMonth(),
            'registration_close_at' => now()->subDay(),
            'status' => 'open',
            'polling_status' => 'draft',
            'polling_start_date' => now()->addWeeks(2)->setTime(9, 0),
            'polling_deadline' => now()->addWeeks(3)->setTime(17, 0),
        ], $faker);
        if ($drawn?->wasRecentlyCreated) {
            foreach ($companies as $company) {
                EventRegistration::create($this->entry($drawn, $company));
            }
        }

        foreach (self::PAST as $title => [$location, $zone, $monthsAgo]) {
            $end = now()->subMonths($monthsAgo)->startOfDay()->setTime(22, 0);
            $event = $this->event($title, $location, $zone, [
                'start_date' => $end->clone()->subDays(4)->setTime(10, 0),
                'end_date' => $end,
                'registration_open_at' => $end->clone()->subMonths(3),
                'registration_close_at' => $end->clone()->subMonths(1),
                'status' => 'completed',
                'polling_status' => 'completed',
                'polling_start_date' => $end->clone()->subWeeks(3)->setTime(9, 0),
                'polling_deadline' => $end->clone()->subWeeks(2)->setTime(17, 0),
            ], $faker);

            if ($event?->wasRecentlyCreated) {
                $this->pastDraw($event, $companies, $faker);
            }
        }
    }

    /** One event by title; an existing one is returned untouched. */
    private function event(string $title, string $location, string $zone, array $attributes, $faker): Event
    {
        return Event::firstOrCreate(['title' => $title], $attributes + [
            'description' => $faker->randomElement([
                'Craft makers from across the country, with demonstrations and sales.',
                'A fair of traditional and modern craft, open to the public every day.',
                'Batik, songket, pottery, woodcarving and more, under one roof.',
            ]),
            'location' => $location,
            'state' => $zone,
            'polling_start_date' => null,
            'polling_deadline' => null,
            'max_participants' => 100,
        ]);
    }

    /** A draw list row for a company, at its own code and booth size. */
    private function entry(Event $event, Company $company): array
    {
        return [
            'event_id' => $event->id,
            'company_id' => $company->id,
            'user_id' => $company->user_id,
            'code' => $company->kraf_code,
            'code_updated_at' => now(),
            'plot_size' => $company->plot_size ?? '3x3',
            'verified_at' => now(),
            'first_verified_at' => now(),
        ];
    }

    /**
     * A draw that has run: most companies on the list, each with a lot and a
     * confirmation (one in every few with none).
     */
    private function pastDraw(Event $event, $companies, $faker): void
    {
        $taking = $companies->shuffle()->take(max(1, (int) round($companies->count() * 0.8)));
        $sizes = [];
        $n = 0;

        foreach ($taking as $company) {
            $size = $company->plot_size ?? '3x3';
            $sizes[$size] ??= PlotSize::create(['event_id' => $event->id, 'size' => $size, 'quantity' => 0, 'location' => 'Dewan Utama']);
            $sizes[$size]->increment('quantity');

            $registration = EventRegistration::create($this->entry($event, $company) + [
                'confirmed_participation' => true,
                'confirmed_at' => $event->polling_deadline->clone()->subDays(rand(1, 6)),
            ]);

            // One in five never got a lot (withdrew before the fair).
            if ($faker->boolean(80)) {
                $n++;
                $plot = Plot::create([
                    'event_id' => $event->id,
                    'plot_size_id' => $sizes[$size]->id,
                    'company_id' => $company->id,
                    'allocated_to' => $company->user_id,
                    'bidang_id' => KrafBidang::codes()->where('code', $company->kraf_code)->value('id'),
                    'plot_code' => chr(65 + intdiv($n - 1, 9)).'-'.$company->kraf_code.(($n - 1) % 9 + 1),
                    'is_allocated' => true,
                    'allocation_type' => 'algorithm',
                    'allocated_at' => $registration->confirmed_at,
                ]);
                $registration->update(['plot_id' => $plot->id]);
                Poll::firstOrCreate(['user_id' => $company->user_id, 'event_id' => $event->id], ['code' => $company->kraf_code]);
            }
        }
    }
}
