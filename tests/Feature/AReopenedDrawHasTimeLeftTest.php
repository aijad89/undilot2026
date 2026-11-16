<?php

use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Plot;

/*
 * K06. PollingGuard asks only that a deadline EXISTS. A draw closed after its
 * deadline, then reopened, read "Open" to HQ while every participant was told
 * "The lot draw is not open." — and the deadline could not be moved, because
 * it lived only in the plan form, which locks at the first held lot.
 *
 * Now: reopening past the deadline is refused, and the deadline has its own
 * door that works whatever the plan's lock says.
 */

/** A closed draw past its deadline, one lot held and one company still without. */
function lapsedDraw(): array
{
    $event = Event::factory()->create([
        'status' => 'open',
        'polling_status' => 'closed',
        'polling_start_date' => now()->subDays(3),
        'polling_deadline' => now()->subHour(),
    ]);

    $held = EventRegistration::factory()->coded('RKP')->create(['event_id' => $event->id]);
    $waiting = EventRegistration::factory()->coded('RKP')->create(['event_id' => $event->id]);

    $plot = Plot::factory()->create(['event_id' => $event->id]);
    $plot->update(['is_allocated' => true, 'allocated_to' => $held->user_id, 'company_id' => $held->company_id]);
    $held->update(['plot_id' => $plot->id]);

    Plot::factory()->create(['event_id' => $event->id, 'plot_size_id' => $plot->plot_size_id, 'bidang_id' => $plot->bidang_id]);

    return [$event, $waiting];
}

it('refuses to reopen a draw whose deadline has passed', function () {
    [$event] = lapsedDraw();

    $this->actingAs(superAdmin(), 'admin')
        ->post(route('admin.polling.change-status', $event), ['polling_status' => 'polling'])
        ->assertSessionHasErrors('error');

    expect(session('errors')->first('error'))->toContain('has already passed')
        ->and($event->fresh()->polling_status)->toBe('closed');
});

it('moves the deadline even with lots held, and then the draw reopens', function () {
    [$event] = lapsedDraw();
    $admin = superAdmin();
    $new = now()->addDays(2)->startOfMinute();

    $this->actingAs($admin, 'admin')
        ->put(route('admin.polling.deadline', $event), ['polling_deadline' => $new->format('Y-m-d\TH:i')])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect($event->fresh()->polling_deadline->equalTo($new))->toBeTrue();

    $this->actingAs($admin, 'admin')
        ->post(route('admin.polling.change-status', $event), ['polling_status' => 'polling'])
        ->assertSessionHasNoErrors();

    expect($event->fresh()->polling_status)->toBe('polling');
});

it('refuses a deadline in the past, or before voting opens', function () {
    [$event] = lapsedDraw();
    $admin = superAdmin();
    $was = $event->polling_deadline;

    $this->actingAs($admin, 'admin')
        ->put(route('admin.polling.deadline', $event), ['polling_deadline' => now()->subDay()->format('Y-m-d\TH:i')])
        ->assertSessionHasErrors('polling_deadline');

    $event->update(['polling_start_date' => now()->addDays(5)]);

    $this->actingAs($admin, 'admin')
        ->put(route('admin.polling.deadline', $event), ['polling_deadline' => now()->addDays(2)->format('Y-m-d\TH:i')])
        ->assertSessionHasErrors('polling_deadline');

    expect($event->fresh()->polling_deadline->equalTo($was))->toBeTrue();
});

it('will not move the deadline of a completed or cancelled draw', function (string $status) {
    $event = Event::factory()->create(['polling_status' => $status, 'polling_deadline' => now()->subDay()]);

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.polling.deadline', $event), ['polling_deadline' => now()->addDay()->format('Y-m-d\TH:i')])
        ->assertSessionHasErrors('error');
})->with(['completed', 'cancelled']);

it('keeps the deadline door shut to a state admin', function () {
    [$event] = lapsedDraw();

    $this->actingAs(stateAdmin(), 'admin')
        ->put(route('admin.polling.deadline', $event), ['polling_deadline' => now()->addDay()->format('Y-m-d\TH:i')])
        ->assertForbidden();
});
