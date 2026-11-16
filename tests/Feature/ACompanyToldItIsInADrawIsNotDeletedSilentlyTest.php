<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;

/*
 * K07. Deleting a company cascades its draw-list rows away. A zone admin could
 * delete a company HQ had already verified — and TOLD it was in the draw —
 * bypassing the rule that only HQ removes such a row, with a reason, and sends
 * RemovedFromDraw. The company just vanished from a draw it had been promised.
 *
 * Now the delete is refused, for anyone, while the company sits on a draw list
 * it was told about in a draw that can still change. HQ removes the row first
 * (the screen that asks for a reason and notifies), then deletes.
 */

function companyOnAList(string $pollingStatus, bool $told = true): array
{
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => $pollingStatus, 'title' => 'Pesta Ujian']);
    $company = Company::factory()->create(['state' => 'Johor']);

    $factory = EventRegistration::factory()->coded('RKP');
    $registration = ($told ? $factory : $factory->unverified())
        ->create(['event_id' => $event->id, 'company_id' => $company->id]);

    return [$company, $registration];
}

it('refuses a zone admin deleting a company HQ already told it is in a draw', function () {
    [$company, $registration] = companyOnAList('draft');

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->delete(route('admin.companies.destroy', $company))
        ->assertRedirect()
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'told it is in the draw for Pesta Ujian'));

    expect($company->fresh())->not->toBeNull()
        ->and($registration->fresh())->not->toBeNull();
});

it('refuses HQ too, so the removal goes through the screen that gives a reason', function (string $status) {
    [$company] = companyOnAList($status);

    $this->actingAs(superAdmin(), 'admin')
        ->delete(route('admin.companies.destroy', $company))
        ->assertSessionHas('error');

    expect($company->fresh())->not->toBeNull();
})->with(['draft', 'polling', 'closed']);

it('still deletes a company whose row HQ has not yet confirmed to it', function () {
    [$company] = companyOnAList('draft', told: false);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->delete(route('admin.companies.destroy', $company))
        ->assertSessionHas('success');

    expect($company->fresh())->toBeNull();
});

it('still deletes a company whose only told rows are in draws that are over', function (string $status) {
    [$company] = companyOnAList($status);

    $this->actingAs(superAdmin(), 'admin')
        ->delete(route('admin.companies.destroy', $company))
        ->assertSessionHas('success');

    expect($company->fresh())->toBeNull();
})->with(['completed', 'cancelled']);
