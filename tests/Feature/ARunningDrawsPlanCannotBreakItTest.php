<?php

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;

/*
 * K05. The plan stays editable until the first lot is held — that line is the
 * project's (PollingController::planIsFrozen()). But the open checks ran once,
 * at Start Polling. A rewrite or a repaint while the draw is open, before anyone
 * has drawn, could leave a code or a size with no lot to win, and the first
 * participant to press Confirm would be the one to find out.
 *
 * So once the draw has started, a plan change is kept only if the draw could
 * still finish; otherwise the whole change rolls back.
 */

/** An open draw: two RKP companies at 3x3, and exactly two RKP 3x3 lots for them. */
function runningDraw(): array
{
    $rkp = KrafBidang::factory()->code('RKP')->create();
    $event = Event::factory()->create([
        'status' => 'open',
        'polling_status' => 'draft',
        'polling_deadline' => now()->addDays(3),
    ]);

    test()->actingAs(superAdmin(), 'admin')
        ->post(route('admin.polling.store'), runningPlan($event, $rkp, 2))
        ->assertRedirect(route('admin.polling.show', $event));

    EventRegistration::factory()->count(2)->coded('RKP')->create(['event_id' => $event->id]);

    test()->actingAs(superAdmin(), 'admin')
        ->post(route('admin.polling.change-status', $event), ['polling_status' => 'polling'])
        ->assertSessionHasNoErrors();

    expect($event->fresh()->polling_status)->toBe('polling');

    return [$event, $rkp];
}

function runningPlan(Event $event, KrafBidang $code, int $lots): array
{
    return [
        'event_id' => $event->id,
        'polling_start_date' => now()->subHour()->format('Y-m-d\TH:i'),
        'polling_deadline' => now()->addDays(3)->format('Y-m-d\TH:i'),
        'plot_sizes' => [['location' => 'Hall A']],   // every plot coded, no spares
        'bidang_allocations' => [['bidang_id' => $code->id, 'plot_size_index' => 0, 'size' => '3x3', 'plots_count' => $lots]],
    ];
}

it('refuses a plan rewrite that would leave an open draw unable to finish, and keeps the old plan', function () {
    [$event, $rkp] = runningDraw();
    $before = Plot::where('event_id', $event->id)->pluck('plot_code')->sort()->values()->all();

    // One RKP lot for two RKP companies.
    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.polling.update', $event), runningPlan($event, $rkp, 1))
        ->assertSessionHasErrors(['error' => 'Not saved: this draw has already started, and after this change it could not finish. Not enough lots for the booth sizes set on some registrations — RKP 3x3 (need 2, have 1). Allocate more of those sizes, or change the booth size on those registrations.']);

    expect(Plot::where('event_id', $event->id)->pluck('plot_code')->sort()->values()->all())->toBe($before)
        ->and(PlotSize::where('event_id', $event->id)->sole()->quantity)->toBe(2);
});

it('still lets an open draw gain lots, because it can still finish', function () {
    [$event, $rkp] = runningDraw();

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.polling.update', $event), runningPlan($event, $rkp, 3))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.polling.show', $event));

    expect(Plot::where('event_id', $event->id)->count())->toBe(3);
});

it('refuses a repaint that would leave an open draw unable to finish, and keeps the old codes', function () {
    [$event, $rkp] = runningDraw();
    $tbps = KrafBidang::factory()->code('TBPS')->create();
    $plots = Plot::where('event_id', $event->id)->get();

    // Both RKP lots repainted to a code nobody on the list carries.
    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.polling.paint.update', $event), ['paint' => $plots->mapWithKeys(fn ($p) => [$p->id => $tbps->id])->all()])
        ->assertSessionHasErrors('error');

    expect(Plot::where('event_id', $event->id)->pluck('bidang_id')->unique()->all())->toBe([$rkp->id]);
});

it('does not check a draft draw, which Start Polling will check before it opens', function () {
    $rkp = KrafBidang::factory()->code('RKP')->create();
    $event = Event::factory()->create(['polling_status' => 'draft']);
    $admin = superAdmin();

    $this->actingAs($admin, 'admin')->post(route('admin.polling.store'), runningPlan($event, $rkp, 2));
    EventRegistration::factory()->count(2)->coded('RKP')->create(['event_id' => $event->id]);

    $this->actingAs($admin, 'admin')
        ->put(route('admin.polling.update', $event), runningPlan($event, $rkp, 1))
        ->assertSessionHasNoErrors();

    expect(Plot::where('event_id', $event->id)->count())->toBe(1);
});
