<?php

use App\Models\AuditTrail;
use App\Models\BidangAllocation;
use App\Models\Event;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;

/*
 * "Generate plots from this plan" — the button on a draw's page when it has a
 * plan and no plots yet. It shares doGeneratePlots() with saving a plan
 * (TheLotPlanBuildsWhatItSaysTest covers that door); this covers the button's
 * own door: what it builds, when it refuses, and who may press it.
 *
 * WHAT IT BUILDS, per plan row (a location at a size):
 *   - each craft allocation under it becomes that many plots carrying the code,
 *     so a registration of that code can win one;
 *   - what is left of the row's count becomes uncoded plots — the open pool at
 *     that size — labelled with the size, not a category;
 *   - labels number per location and code, and every label is distinct: the
 *     label is printed on the stall and on the participant's slip.
 */

function plannedEvent(array $rows): Event
{
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft', 'state' => null]);

    foreach ($rows as [$location, $size, $quantity, $codes]) {
        $plotSize = PlotSize::create([
            'event_id' => $event->id, 'location' => $location, 'size' => $size, 'quantity' => $quantity,
        ]);

        foreach ($codes as $code => $count) {
            BidangAllocation::create([
                'event_id' => $event->id,
                'bidang_id' => KrafBidang::codes()->where('code', $code)->firstOr(
                    fn () => KrafBidang::factory()->code($code)->create()
                )->id,
                'sub_bidang_id' => null,
                'plot_size_id' => $plotSize->id,
                'plots_count' => $count,
            ]);
        }
    }

    return $event;
}

function generate(Event $event)
{
    return test()->post(route('admin.polling.generate-plots', $event));
}

it('builds coded plots for each allocation and the open pool from the rest', function () {
    $event = plannedEvent([
        ['Dewan A', '3x3', 5, ['TBPS' => 2, 'RKP' => 1]],
        ['Dewan A', '3x6', 2, []],
    ]);

    $this->actingAs(superAdmin(), 'admin');
    generate($event)->assertSessionHas('success', '7 plots generated.');

    $plots = $event->plots()->with('bidang', 'plotSize')->orderBy('id')->get();

    expect($plots->map(fn ($p) => [$p->plot_code, $p->bidang?->code, $p->plotSize->size])->all())->toBe([
        ['TBPS01', 'TBPS', '3x3'],
        ['TBPS02', 'TBPS', '3x3'],
        ['RKP01', 'RKP', '3x3'],
        ['3X3-01', null, '3x3'],
        ['3X3-02', null, '3x3'],
        ['3X6-01', null, '3x6'],
        ['3X6-02', null, '3x6'],
    ]);

    expect($plots->every(fn ($p) => ! $p->is_allocated && $p->company_id === null))->toBeTrue();
});

it('numbers one code across sizes at the same location, so no two stalls share a label', function () {
    $event = plannedEvent([
        ['Dewan A', '3x3', 2, ['RKP' => 2]],
        ['Dewan A', '3x6', 2, ['RKP' => 2]],
    ]);

    $this->actingAs(superAdmin(), 'admin');
    generate($event);

    expect($event->plots()->orderBy('id')->pluck('plot_code')->all())
        ->toBe(['RKP01', 'RKP02', 'RKP03', 'RKP04']);
});

it('gives every plot a distinct label even when two locations print the same', function () {
    // "Dewan A" and "DEWAN A." are two rows to the plan and one label to
    // Plot::codeFor(), which upper-cases and drops punctuation.
    $event = plannedEvent([
        ['Dewan A', '3x3', 2, ['RKP' => 2]],
        ['DEWAN A.', '3x6', 2, ['RKP' => 2]],
    ]);

    $this->actingAs(superAdmin(), 'admin');
    generate($event);

    $codes = $event->plots()->pluck('plot_code');

    expect($codes)->toHaveCount(4)
        ->and($codes->unique()->count())->toBe(4);
});

it('writes one audit row for the whole batch, since the inserts fire no model events', function () {
    $event = plannedEvent([['Dewan A', '3x3', 3, ['TBPS' => 2]]]);

    $this->actingAs(superAdmin(), 'admin');
    generate($event);

    $row = AuditTrail::where('action', 'plots_generated')->sole();

    expect($row->auditable_id)->toBe($event->id)
        ->and($row->new_values['plots'])->toBe(3)
        // toEqual: MySQL's JSON type reorders keys.
        ->and($row->new_values['per_code'])->toEqual(['TBPS' => 2, '3X3' => 1]);
});

it('refuses when the event already has plots, and builds nothing twice', function () {
    $event = plannedEvent([['Dewan A', '3x3', 3, ['TBPS' => 2]]]);

    $this->actingAs(superAdmin(), 'admin');
    generate($event);
    generate($event)->assertSessionHasErrors('error');

    expect($event->plots()->count())->toBe(3);
});

it('refuses when there is no plan to build from', function () {
    $event = plannedEvent([]);

    $this->actingAs(superAdmin(), 'admin');
    generate($event)->assertSessionHasErrors('error');

    expect($event->plots()->count())->toBe(0);
});

it('is HQ\'s and super\'s to press, not a state admin\'s', function () {
    $event = plannedEvent([['Dewan A', '3x3', 3, []]]);

    $this->actingAs(stateAdmin('Johor'), 'admin');
    generate($event)->assertForbidden();

    expect($event->plots()->count())->toBe(0);
});

it('counts one code on from one location to the next, so TBPS02 is one plot', function () {
    // The office's format carries no location (TBPS02), so two halls holding
    // TBPS cannot both start from 01.
    $event = plannedEvent([
        ['Dewan A', '3x3', 2, ['TBPS' => 2]],
        ['Dewan B', '3x3', 2, ['TBPS' => 2]],
    ]);

    $this->actingAs(superAdmin(), 'admin');
    generate($event);

    expect($event->plots()->orderBy('id')->pluck('plot_code')->all())
        ->toBe(['TBPS01', 'TBPS02', 'TBPS03', 'TBPS04']);
});

it('pads the number to two digits, and hyphens a size so its digits stay apart', function () {
    expect(Plot::codeFor('TBPS', 2))->toBe('TBPS02')
        ->and(Plot::codeFor('tbps', 10))->toBe('TBPS10')
        ->and(Plot::codeFor('TBPS', 100))->toBe('TBPS100')
        ->and(Plot::codeFor('3x3', 4))->toBe('3X3-04')
        ->and(Plot::codeFor(null, 1))->toBe('PLOT01');
});
