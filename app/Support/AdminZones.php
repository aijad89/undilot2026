<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kraftangan's administrative zones — the value stored in `admins.state`,
 * `companies.state` and `events.state`.
 *
 * This list drives access control. A state admin sees a company only when
 * `companies.state` matches their own `admins.state` exactly, as strings. That
 * makes renaming a zone a security operation rather than a cosmetic one: change
 * the label alone and every admin holding the old string loses access to every
 * company still holding it, silently and with no error anywhere.
 *
 * So renaming goes through rename(), which rewrites all three columns inside a
 * transaction, and a zone still in use cannot be deleted at all.
 *
 * Not the same thing as RegistrationStates. A zone is an office boundary, not a
 * place: "Selangor, Kuala Lumpur dan Putrajaya" is one zone covering three
 * territories, and "Langkawi" is a zone but not a state.
 */
class AdminZones extends ManagedList
{
    protected const KEY = 'admin_zones';

    public const DEFAULTS = [
        'Johor',
        'Melaka',
        'Negeri Sembilan',
        'Selangor, Kuala Lumpur dan Putrajaya',
        'Perak',
        'Pulau Pinang',
        'Kedah',
        'Langkawi',
        'Perlis',
        'Kelantan',
        'Pahang',
        'Terengganu',
        'Sabah dan Labuan',
        'Sarawak',
    ];

    /**
     * Every column holding a zone name. Renaming has to touch all of them or
     * the rename silently breaks scoping.
     *
     * @var array<string, string>  table => column
     */
    public const SCOPED_COLUMNS = [
        'admins' => 'state',
        'companies' => 'state',
        'events' => 'state',
    ];

    /**
     * How many rows currently hold this zone, per table.
     *
     * @return array<string, int>
     */
    public static function usage(string $zone): array
    {
        $counts = [];

        foreach (self::SCOPED_COLUMNS as $table => $column) {
            $counts[$table] = DB::table($table)->where($column, $zone)->count();
        }

        return $counts;
    }

    /**
     * usage() for every zone at once, in one query per scoped table.
     *
     * The Dropdown Lists screen shows this beside all fourteen zones, and asking
     * usage() per zone meant three COUNTs each — two of them unindexed string
     * comparisons — on every render.
     *
     * @return array<string, array<string, int>>
     */
    public static function usageForAll(): array
    {
        $counts = [];

        foreach (self::SCOPED_COLUMNS as $table => $column) {
            $counts[$table] = DB::table($table)
                ->select($column, DB::raw('count(*) as n'))
                ->groupBy($column)
                ->pluck('n', $column);
        }

        $usage = [];

        foreach (self::all() as $zone) {
            foreach (self::SCOPED_COLUMNS as $table => $column) {
                $usage[$zone][$table] = (int) ($counts[$table][$zone] ?? 0);
            }
        }

        return $usage;
    }

    public static function isInUse(string $zone): bool
    {
        return array_sum(self::usage($zone)) > 0;
    }

    /**
     * Rename a zone and carry every row holding it across, atomically.
     *
     * REFUSES A NAME ANOTHER ZONE ALREADY HAS, ignoring letter case. Renaming
     * "Perlis" to "Kedah" used to move every Perlis row under Kedah — two zones
     * merged, each zone admin seeing the other's companies, no warning. Case is
     * ignored because MySQL compares these columns case-insensitively, so
     * "kedah" would land on Kedah's rows just the same. Changing only the case
     * of the zone's OWN name is allowed.
     *
     * @return int rows updated across all tables
     *
     * @throws ValidationException when `$to` is another zone's name
     */
    public static function rename(string $from, string $to): int
    {
        $to = trim($to);

        if ($from === $to || $to === '') {
            return 0;
        }

        $clash = collect(self::all())
            ->first(fn ($zone) => $zone !== $from && mb_strtolower($zone) === mb_strtolower($to));

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'to' => "There is already a zone called \"{$clash}\". Renaming \"{$from}\" to it would merge the two zones. Choose another name.",
            ]);
        }

        return DB::transaction(function () use ($from, $to) {
            $updated = 0;

            foreach (self::SCOPED_COLUMNS as $table => $column) {
                $updated += DB::table($table)->where($column, $from)->update([$column => $to]);
            }

            $list = self::all();
            $index = array_search($from, $list, true);

            if ($index !== false) {
                $list[$index] = $to;
                self::set($list);
            }

            return $updated;
        });
    }
}
