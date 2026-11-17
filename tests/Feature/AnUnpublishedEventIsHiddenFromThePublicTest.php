<?php

use App\Models\Admin;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;

/*
 * K10. Only Draft was hidden from the public site; an event the office set to
 * Unpublished (`status = closed`) stayed listed on / and its page still opened.
 *
 * Now Unpublished is gone from the list for everyone, and its page is a 404 to
 * visitors and participants. A signed-in admin can still open the page, with a
 * banner saying it is hidden. A participant's OWN draw is untouched: dashboard
 * and lot page keep working, so nobody already in a draw is locked out of it.
 */

function eventWithStatus(string $status, array $extra = []): Event
{
    return Event::factory()->create($extra + [
        'status' => $status,
        'title' => "Acara {$status}",
        'start_date' => now()->addWeek(),
        'end_date' => now()->addWeeks(2),
    ]);
}

it('lists published events and hides unpublished and draft ones', function () {
    eventWithStatus('open');
    eventWithStatus('closed');
    eventWithStatus('draft');

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Acara open')
        ->assertDontSee('Acara closed')
        ->assertDontSee('Acara draft');
});

it('hides an unpublished event from the list for a signed-in admin too', function () {
    eventWithStatus('closed');

    $this->actingAs(superAdmin(), 'admin')->get(route('home'))->assertDontSee('Acara closed');
});

it('answers 404 for an unpublished event page to a visitor and to a participant', function () {
    $event = eventWithStatus('closed');

    $this->get(route('events.show', $event))->assertNotFound();

    $this->actingAs(Company::factory()->create()->user, 'web')
        ->get(route('events.show', $event))->assertNotFound();
});

it('lets a signed-in admin open an unpublished event page, with a banner', function () {
    $event = eventWithStatus('closed');

    $this->actingAs(superAdmin(), 'admin')
        ->get(route('events.show', $event))
        ->assertOk()
        ->assertSee('Acara closed')
        ->assertSee('This event is hidden from the public site');
});

it('still shows a published event to everyone, without the banner', function () {
    $event = eventWithStatus('open');

    $this->get(route('events.show', $event))
        ->assertOk()
        ->assertDontSee('This event is hidden from the public site');
});

it('keeps a draft a 404 even for an admin, as before', function () {
    $this->actingAs(superAdmin(), 'admin')
        ->get(route('events.show', eventWithStatus('draft')))->assertNotFound();
});

it('does not lock a participant out of their own draw when the event is unpublished', function () {
    $event = eventWithStatus('closed', [
        'polling_status' => 'polling',
        'polling_start_date' => now()->subDay(),
        'polling_deadline' => now()->addDay(),
    ]);
    $entry = EventRegistration::factory()->coded('RKP')->create(['event_id' => $event->id]);
    $user = $entry->company->user;

    $this->actingAs($user, 'web')->get(route('dashboard'))->assertOk()->assertSee('Acara closed');
    $this->actingAs($user, 'web')->get(route('zvote.index', $event))->assertOk();
});
