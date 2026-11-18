<?php

namespace Database\Seeders;

use App\Http\Controllers\Admin\PollingController;
use App\Models\Admin;
use App\Models\BidangAllocation;
use App\Models\Company;
use App\Models\CompanyKraf;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\KrafSubBidang;
use App\Models\PlotSize;
use App\Models\User;
use App\Support\AdminZones;
use App\Support\PollingGuard;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * DEMO DATA for a hand test of one full-size draw — never on the live server.
 *
 *   php artisan db:seed --class=BatikDrawSeeder
 *
 * Plants 200 batik companies (category TEKSTIL, sub-category Batik), each with
 * a participant account carrying an IC number, spread across every zone; one
 * event, "Pesta Batik Kebangsaan 2026", with a lot plan, its plots, and all 200
 * on the draw list, verified by HQ. The draw is left at DRAFT, ready to open:
 * PollingGuard is asked at the end and must answer nothing.
 *
 * Re-runnable. Everything it plants is on the `batik.test` domain or is that
 * one event, and a second run removes the first run's rows before planting.
 * Passwords are random — sign in through /auto.
 *
 * Craft codes are the coding sheet's three batik codes (TBPS, TBP, TBA), from
 * KrafCodeSeeder; the category and sub-category from DemoSeeder.
 */
class BatikDrawSeeder extends LocalOnlySeeder
{
    /** Every account this seeder makes has a community ID with this prefix. */
    private const ID_PREFIX = 'BATIK-';

    private const EVENT = 'Pesta Batik Kebangsaan 2026';

    /** code => [3x3 count, 3x6 count] — 200 in all. */
    private const ENTRIES = [
        'TBPS' => [70, 10],
        'TBP' => [50, 10],
        'TBA' => [50, 10],
    ];

    /**
     * The plan: [location, size, quantity, [code => plots]]. Each code gets
     * exactly the plots its entries need; the rest of a row is the open pool.
     */
    private const PLAN = [
        ['Dewan Utama', '3x3', 120, ['TBPS' => 70, 'TBP' => 50]],
        ['Dewan Seri', '3x3', 70, ['TBA' => 50]],
        ['Dewan Seri', '3x6', 35, ['TBPS' => 10, 'TBP' => 10, 'TBA' => 10]],
    ];

    /** Birth-place code of a MyKad, by zone. */
    private const BIRTH_STATE = [
        'Johor' => '01', 'Kedah' => '02', 'Langkawi' => '02', 'Kelantan' => '03', 'Melaka' => '04',
        'Negeri Sembilan' => '05', 'Pahang' => '06', 'Pulau Pinang' => '07', 'Perak' => '08',
        'Perlis' => '09', 'Selangor, Kuala Lumpur dan Putrajaya' => '10', 'Terengganu' => '11',
        'Sabah dan Labuan' => '12', 'Sarawak' => '13',
    ];

    private const PREFIX = ['Batik', 'Teratak Batik', 'Warisan Batik', 'Butik Batik', 'Galeri Batik'];

    private const NAME = [
        'Seri Kenanga', 'Cempaka', 'Melur', 'Anggerik', 'Tanjung', 'Mawar', 'Kemboja', 'Teratai',
        'Dahlia', 'Kesidang', 'Bunga Raya', 'Seroja', 'Rafflesia', 'Kiambang', 'Pandan',
        'Selasih', 'Kasturi', 'Delima', 'Sutera', 'Permata',
    ];

    private const SUFFIX = ['Enterprise', 'Trading'];

    public function run(): void
    {
        // No environment check here: LocalOnlySeeder refuses every environment
        // outside DevEnvironment::NAMES before run() is reached.

        $category = KrafBidang::categories()->where('name', 'TEKSTIL')->firstOrFail();
        $batik = KrafSubBidang::where('bidang_id', $category->id)->where('name', 'Batik')->firstOrFail();
        $codes = KrafBidang::codes()->whereIn('code', array_keys(self::ENTRIES))->pluck('id', 'code');

        abort_unless($codes->count() === count(self::ENTRIES), 1, 'Run KrafCodeSeeder first: a batik code is missing.');

        $hq = Admin::where('role', 'hq')->first() ?? Admin::where('role', 'super')->firstOrFail();

        DB::transaction(function () use ($category, $batik, $codes, $hq) {
            $this->clearPreviousRun();

            $companies = $this->companies($category, $batik);
            $event = $this->event();
            $this->plan($event, $codes);
            $this->drawList($event, $companies, $hq);
        });

        $event = Event::where('title', self::EVENT)->first();
        $blocker = PollingGuard::blocker($event);

        $this->command?->info(sprintf(
            '%s: %d companies, %d plots, %d on the draw list. Draw %s.',
            self::EVENT,
            Company::whereHas('user', fn ($q) => $q->where('community_id', 'like', self::ID_PREFIX.'%'))->count(),
            $event->plots()->count(),
            $event->registrations()->count(),
            $blocker ? "NOT ready: {$blocker}" : 'ready to open',
        ));
    }

    /** Registrations first: plots are restricted while an entry points at one. */
    private function clearPreviousRun(): void
    {
        if ($old = Event::where('title', self::EVENT)->first()) {
            $old->registrations()->delete();
            $old->delete();
        }

        $userIds = User::where('community_id', 'like', self::ID_PREFIX.'%')->pluck('id');
        Company::whereIn('user_id', $userIds)->get()->each->delete();
        User::whereIn('id', $userIds)->delete();
    }

    /** @return \Illuminate\Support\Collection<int, Company> in entry order */
    private function companies(KrafBidang $category, KrafSubBidang $batik)
    {
        $faker = Faker::create('ms_MY');
        $faker->seed(2026);

        $zones = AdminZones::all();
        $plan = $this->entryPlan();
        $password = Hash::make(Str::random(40));
        $out = collect();

        foreach ($plan as $i => [$code, $size]) {
            $n = $i + 1;
            $zone = $zones[$i % count($zones)];
            $born = $faker->dateTimeBetween('1960-01-01', '2000-12-31');

            $user = User::create([
                'name' => $faker->name(),
                'password' => $password,
            ]);
            $user->forceFill([
                'community_id' => sprintf('%s%03d', self::ID_PREFIX, $n),
                'ic_number' => sprintf('%s-%s-%04d', $born->format('ymd'), self::BIRTH_STATE[$zone] ?? '14', 5000 + $n),
                'phone' => sprintf('01%d-%07d', $faker->numberBetween(2, 9), $faker->numberBetween(1000000, 9999999)),
                'is_active' => true,
            ])->save();

            $company = Company::create([
                'user_id' => $user->id,
                'name' => $this->companyName($i),
                'state' => $zone,
                'entrepreneur_category' => $n % 10 === 0 ? 'Pemborong' : 'Pengeluar atau Pedagang',
                'is_active' => true,
            ]);
            $company->kraf_code = $code;     // admin-only, not fillable
            $company->plot_size = $size;
            $company->save();

            CompanyKraf::create([
                'company_id' => $company->id,
                'bidang_id' => $category->id,
                'sub_bidang_id' => $batik->id,
                'is_primary' => true,
            ]);

            $out->push($company);
        }

        return $out;
    }

    /** One [code, size] per company, 200 in all, codes interleaved across zones. */
    private function entryPlan(): array
    {
        $plan = [];

        foreach (self::ENTRIES as $code => [$small, $large]) {
            array_push($plan, ...array_fill(0, $small, [$code, '3x3']), ...array_fill(0, $large, [$code, '3x6']));
        }

        // Deterministic shuffle, so each zone gets a mix of codes and sizes.
        mt_srand(2026);
        shuffle($plan);
        mt_srand();

        return $plan;
    }

    /** 5 × 20 × 2 = 200 distinct names, in a fixed order. */
    private function companyName(int $i): string
    {
        return sprintf('%s %s %s',
            self::PREFIX[intdiv($i, count(self::NAME) * count(self::SUFFIX)) % count(self::PREFIX)],
            self::NAME[intdiv($i, count(self::SUFFIX)) % count(self::NAME)],
            self::SUFFIX[$i % count(self::SUFFIX)],
        );
    }

    private function event(): Event
    {
        return Event::create([
            'title' => self::EVENT,
            'description' => 'Pameran dan jualan batik dari seluruh negara. Acara demo untuk ujian cabutan lot.',
            'location' => 'Kompleks Kraf Kuala Lumpur',
            'state' => null,
            'start_date' => now()->addMonth()->startOfDay()->setTime(9, 0),
            'end_date' => now()->addMonth()->addDays(4)->startOfDay()->setTime(22, 0),
            'registration_open_at' => now()->subWeek(),
            'registration_close_at' => now()->addWeeks(2),
            'status' => 'open',
            'polling_status' => 'draft',
            'polling_start_date' => now()->addDay()->startOfDay()->setTime(9, 0),
            'polling_deadline' => now()->addDays(4)->startOfDay()->setTime(17, 0),
            'use_preset' => false,
            'max_participants' => 220,
        ]);
    }

    /** The plan rows, their code allocations, and the plots — built by the app's own generator. */
    private function plan(Event $event, $codes): void
    {
        foreach (self::PLAN as [$location, $size, $quantity, $allocations]) {
            $row = PlotSize::create([
                'event_id' => $event->id, 'location' => $location, 'size' => $size, 'quantity' => $quantity,
            ]);

            foreach ($allocations as $code => $count) {
                BidangAllocation::create([
                    'event_id' => $event->id,
                    'bidang_id' => $codes[$code],
                    'sub_bidang_id' => null,
                    'plot_size_id' => $row->id,
                    'plots_count' => $count,
                ]);
            }
        }

        // Not fillable: PollingController computes it from the plan, never from input.
        $event->forceFill(['total_plots' => array_sum(array_column(self::PLAN, 2))])->save();

        // PollingController::doGeneratePlots() is private; bound and called as
        // itself rather than copied, so these plots are numbered exactly as a
        // plan saved on screen would number them.
        (fn (Event $e) => $this->doGeneratePlots($e))->call(app(PollingController::class), $event);
    }

    /** Every company on the list, code and size from its own, verified by HQ. */
    private function drawList(Event $event, $companies, Admin $hq): void
    {
        foreach ($companies as $n => $company) {
            EventRegistration::create([
                'event_id' => $event->id,
                'company_id' => $company->id,
                'user_id' => $company->user_id,
                'code' => $company->kraf_code,
                'code_updated_at' => now(),
                'plot_size' => $company->plot_size,
                'plot_id' => null,
                'confirmed_participation' => false,
                'confirmed_at' => null,
                'verified_by' => $hq->id,
                'verified_at' => now(),
                'first_verified_at' => now(),
                'hq_note' => null,
                'payment_note' => sprintf('Resit PB-%04d, %s, tunai', $n + 1, now()->subDays(3)->format('j M')),
                'payment_status' => 'paid',
            ]);
        }
    }
}
