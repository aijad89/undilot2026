<?php

use App\Models\Company;
use App\Models\EventRegistration;

/*
 * Beside the payment note, the officer records whether the company has PAID —
 * a choice from EventRegistration::PAYMENT_STATUSES, not free text. It travels
 * through the same form and the same rules as the note: a zone admin's change
 * sends the row back to HQ, and a row with no status counts as missing a detail.
 */

function paymentRow(array $attributes = []): EventRegistration
{
    return EventRegistration::factory()->create($attributes + [
        'company_id' => Company::factory()->create(['state' => 'Johor'])->id,
        'hq_note' => null,
    ]);
}

function paymentUrl(EventRegistration $row): string
{
    return route('admin.events.registrations.payment-note', [$row->event_id, $row]);
}

it('saves the status the officer picks, and shows the choice on the list', function () {
    $row = paymentRow();

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->put(paymentUrl($row), ['payment_status' => 'paid', 'payment_note' => 'Resit 0042'])
        ->assertSessionHas('success', 'Payment saved.');

    expect($row->fresh()->payment_status)->toBe('paid');

    $this->get(route('admin.events.registrations.index', $row->event_id))
        ->assertSee('<option value="paid" selected>Paid</option>', false);
});

it('refuses a status that is not on the list', function () {
    $row = paymentRow(['payment_status' => 'paid']);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->put(paymentUrl($row), ['payment_status' => 'half'])
        ->assertSessionHasErrors('payment_status');

    expect($row->fresh()->payment_status)->toBe('paid');
});

it('sends the row back to HQ when a zone admin changes the status', function () {
    $row = paymentRow(['payment_status' => 'unpaid']);

    expect($row->verified_at)->not->toBeNull();

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->put(paymentUrl($row), ['payment_status' => 'paid', 'payment_note' => $row->payment_note]);

    expect($row->fresh()->verified_at)->toBeNull();
});

it('keeps HQ\'s verification when HQ records the status itself', function () {
    $row = paymentRow();

    $this->actingAs(superAdmin(), 'admin')
        ->put(paymentUrl($row), ['payment_status' => 'paid']);

    expect($row->fresh())
        ->payment_status->toBe('paid')
        ->verified_at->not->toBeNull();
});

it('leaves the status alone when only the note is posted', function () {
    $row = paymentRow(['payment_status' => 'paid']);

    $this->actingAs(superAdmin(), 'admin')
        ->put(paymentUrl($row), ['payment_note' => 'Resit 0043']);

    expect($row->fresh()->payment_status)->toBe('paid');
});
