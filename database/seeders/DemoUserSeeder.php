<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyKraf;
use App\Models\KrafSubBidang;
use App\Models\User;
use App\Support\AdminZones;
use App\Support\EntrepreneurCategories;
use Illuminate\Support\Str;

/**
 * DEMO: one participant per zone (14), each owning one company in that zone,
 * with a craft code and one craft selection (its first classification and
 * product ticked) when DemoHierarchySeeder has run first.
 *
 * Community IDs are `KOM-0001`… in AdminZones order; they sign in with that
 * ID and their IC (or via /auto). Re-runnable: matched on community ID.
 */
class DemoUserSeeder extends LocalOnlySeeder
{
    /**
     * [company name, craft code, IC], one per zone in AdminZones order.
     *
     * The IC is written the way a Malaysian IC is actually written — birth date,
     * then the place-of-birth code, then four digits — and the place code is the
     * real one for that zone (Johor 01, Kedah 02, Sarawak 13…). Screens that
     * print an IC beside a name read wrong when every demo row carries the same
     * repeated digits, and a draw list of fourteen identical ICs hides exactly
     * the formatting bugs these rows exist to expose.
     *
     * Spelled out rather than generated, because a random IC changes on every
     * reseed and nothing that references one in a note or a test stays true.
     */
    private const COMPANIES = [
        ['Batik Warisan Johor', 'TBPS', '750312-01-5123'],
        ['Tenun Melaka Enterprise', 'TTN', '681105-04-5234'],
        ['Ukiran Kayu Seri Menanti', 'RKH', '820724-05-5345'],
        ['Seramik Klang Studio', 'TTK', '790218-10-5456'],
        ['Labu Sayong Perak', 'TTK', '660930-08-5567'],
        ['Songket Pulau Pinang', 'TST', '880416-07-5678'],
        ['Anyaman Mengkuang Kedah', 'RMH', '710607-02-5789'],
        ['Mutiara Langkawi Craft', 'AMH', '851122-02-5890'],
        ['Tekat Perlis Indah', 'TTH', '731003-09-5901'],
        ['Perak Tembaga Kelantan', 'LEH', '690815-03-5012'],
        ['Rotan Pahang Jaya', 'RBH', '900529-06-5123'],
        ['Batik Lukis Terengganu', 'TBPS', '770211-11-5234'],
        ['Manik Sabah Heritage', 'AMH', '830708-12-5345'],
        ['Pua Kumbu Sarawak', 'TTN', '741219-13-5456'],
    ];

    /** The demo participant's community ID for a zone: KOM-0001 is Johor. */
    public static function communityIdFor(string $zone): string
    {
        $index = array_search($zone, AdminZones::DEFAULTS, true);

        if ($index === false) {
            throw new \InvalidArgumentException("No demo participant for zone {$zone}.");
        }

        return sprintf('KOM-%04d', $index + 1);
    }

    public function run(): void
    {
        $subs = KrafSubBidang::with(['pengelasan', 'produks'])->orderBy('id')->get();

        foreach (AdminZones::DEFAULTS as $i => $zone) {
            [$name, $code, $ic] = self::COMPANIES[$i];

            $user = User::firstOrCreate(['community_id' => self::communityIdFor($zone)], [
                'name' => "Owner {$name}",
                'password' => Str::random(40),
                'phone' => '01'.random_int(10000000, 99999999),
                'ic_number' => $ic,
                'is_active' => true,
            ]);

            // Fills the blank on a re-run over rows seeded before the IC existed,
            // without stomping one somebody edited by hand.
            if (blank($user->ic_number)) {
                $user->ic_number = $ic;
                $user->save();
            }

            $company = Company::firstOrCreate(['user_id' => $user->id], [
                'name' => $name,
                'state' => $zone,
                'entrepreneur_category' => EntrepreneurCategories::DEFAULTS[1],
                'is_active' => true,
            ]);
            $company->kraf_code = $code; // not fillable: admin only
            $company->plot_size = $code === 'RKH' ? '3x6' : '3x3';
            $company->save();

            if ($subs->isNotEmpty() && ! $company->krafSelections()->exists()) {
                $sub = $subs[$i % $subs->count()];
                $selection = CompanyKraf::create([
                    'company_id' => $company->id,
                    'bidang_id' => $sub->bidang_id,
                    'sub_bidang_id' => $sub->id,
                    'is_primary' => true,
                ]);
                $selection->pengelasan()->attach($sub->pengelasan->take(1)->pluck('id'));
                $selection->produks()->attach($sub->produks->take(1)->pluck('id'));
            }
        }
    }
}
