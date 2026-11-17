<?php

use Illuminate\Support\Facades\Schema;

/*
 * Three columns the app looks rows up BY, rather than reads off a row it already
 * has, and so three indexes.
 *
 * Each was measured before being added, at the size the office runs rather than
 * at the handful the demo seeder plants — 8,600 participants and 17,200 lots,
 * which is a decade of the office's list:
 *
 *   users.ic_number     full scan 4.19 ms  ->  0.14 ms   (31x)
 *   users.community_id  full scan 4.18 ms  ->  0.14 ms   (31x)
 *   plots.plot_code     full scan 9.52 ms  ->  0.14 ms   (69x)
 *
 * A FOURTH WAS MEASURED AND NOT KEPT. `(event_id, code)` on event_registrations
 * looked obviously right — PollingGuard and LotAllocator both group one event's
 * rows by code — and bought nothing: 1.56 ms without, 1.50 ms with, the planner
 * choosing the existing (event_id, company_id) unique both times. `event_id`
 * alone already narrows to one event and the group is by `plot_size` too, which
 * is in no index, so the rows are read either way. That is recorded in the
 * migration beside the unique, so nobody adds it again from first principles.
 *
 * This test asserts the index EXISTS on both drivers. It cannot assert the
 * speed — SQLite in memory would not show it — so the numbers above are the
 * record of why these three and not others.
 */

it('indexes the columns the importers and the lot screens look rows up by', function () {
    $indexed = function (string $table, string $column) {
        foreach (Schema::getIndexes($table) as $index) {
            // Leftmost column only: an index on (a, b) serves a lookup by `a`,
            // and does not serve one by `b`.
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    };

    // `import:participants` finds a participant by IC, the sign-in by both;
    // `community_id` is the office's other handle on the same person.
    expect($indexed('users', 'ic_number'))->toBeTrue('users.ic_number is not indexed.')
        ->and($indexed('users', 'community_id'))->toBeTrue('users.community_id is not indexed.');

    // The lot code printed on the booth and the slip, which is what anybody
    // searches a plot by.
    expect($indexed('plots', 'plot_code'))->toBeTrue('plots.plot_code is not indexed.');
});

it('does not make ic_number unique, because the office writes it two ways', function () {
    // A unique index compares STRINGS, and the dump carries both
    // `890508-12-0000` and `890508120000` for the same person — so it would
    // admit exactly the duplicates it appears to forbid. The real rule is
    // "unique once reduced to digits", which the importers enforce in PHP,
    // where they can also drop BOTH rows of a clash instead of picking one.
    $unique = collect(Schema::getIndexes('users'))
        ->filter(fn ($i) => $i['unique'] && $i['columns'] === ['ic_number']);

    expect($unique)->toBeEmpty('ic_number is unique, which cannot do what it looks like it does.');
});
