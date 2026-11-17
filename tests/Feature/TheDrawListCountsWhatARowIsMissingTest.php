<?php

use App\Models\Company;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\User;

/*
 * The draw list counts, in each row's Actions column, how many of the four
 * details the office fills in are still empty — payment status, payment note,
 * craft code, booth size — so a zone sees which rows are not ready for HQ. "1 missing",
 * "2 missing"; nothing when the row is complete. The badge's title names them.
 */

function rowMissing(Event $event, array $details): EventRegistration
{
    return EventRegistration::factory()->create($details + [
        'event_id' => $event->id,
        'company_id' => Company::factory()->create(['user_id' => User::factory()->create()->id, 'state' => 'Johor'])->id,
        'payment_status' => 'paid',
        'payment_note' => 'Resit 0042',
        'code' => 'TBPS',
        'plot_size' => '3x3',
    ]);
}

it('names the model\'s missing details in the order the list shows them', function () {
    $event = Event::factory()->create();

    expect(rowMissing($event, [])->missingDetails())->toBe([])
        ->and(rowMissing($event, ['payment_status' => null])->missingDetails())->toBe(['payment status'])
        ->and(rowMissing($event, ['payment_note' => null])->missingDetails())->toBe(['payment note'])
        ->and(rowMissing($event, ['payment_note' => '', 'code' => null])->missingDetails())->toBe(['payment note', 'craft code'])
        ->and(rowMissing($event, ['payment_note' => null, 'code' => '', 'plot_size' => null])->missingDetails())
        ->toBe(['payment note', 'craft code', 'booth size']);
});

it('shows 1 or 2 missing on a row, and nothing on a complete one', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $complete = rowMissing($event, []);
    $noCode = rowMissing($event, ['code' => null]);
    $noPaymentNoCode = rowMissing($event, ['payment_note' => null, 'code' => null]);

    $html = $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.events.registrations.index', $event))
        ->assertOk()
        ->getContent();

    // Each row's badge, read from its company name to the end of its row.
    $badges = [];

    foreach ([$complete, $noCode, $noPaymentNoCode] as $r) {
        $row = substr($html, strpos($html, e($r->company->name)));
        $row = substr($row, 0, strpos($row, '</tr>'));
        $badges[$r->company->name] = preg_match('/missing-count"\s+title="([^"]*)">\s*(.*?)\s*</s', $row, $m)
            ? [$m[2], html_entity_decode($m[1])]
            : null;
    }

    expect($badges[$complete->company->name])->toBeNull()
        ->and($badges[$noCode->company->name])->toBe(['1 missing', 'Missing: craft code'])
        ->and($badges[$noPaymentNoCode->company->name])->toBe(['2 missing', 'Missing: payment note, craft code']);
});

/* ── The "Any details" filter ─────────────────────────────────────────── */

/** Company names on the draw list page for $query, in page order. */
function listedFor($test, Event $event, array $query): array
{
    $html = $test->get(route('admin.events.registrations.index', [$event, ...$query]))->assertOk()->getContent();

    // Each row's tick box names its company. The header's tick-all box is
    // "Select every row", so only the row boxes (class tick-row) are read.
    preg_match_all('/class="tick-row"[^>]*aria-label="Select ([^"]+)"/', $html, $m);

    return array_map(fn ($name) => html_entity_decode($name, ENT_QUOTES | ENT_HTML5), $m[1]);
}

it('filters to the rows missing any detail, empty strings included', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $complete = rowMissing($event, []);
    $noNote = rowMissing($event, ['payment_note' => '']);
    $noCode = rowMissing($event, ['code' => null]);

    $this->actingAs(superAdmin(), 'admin');

    $names = listedFor($this, $event, ['missing' => 'any']);

    expect($names)->toContain($noNote->company->name, $noCode->company->name)
        ->and(in_array($complete->company->name, $names, true))->toBeFalse();

    // On its own it counts as a filter: "2 / 3" beside Clear.
    $html = $this->get(route('admin.events.registrations.index', [$event, 'missing' => 'any']))->getContent();
    expect(preg_match('/id="filter-count"[^>]*>\s*2 \/ 3\s*</', $html))->toBe(1);
});

it('filters to the rows missing one named detail', function (string $detail, array $empty) {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $hit = rowMissing($event, $empty);
    $other = rowMissing($event, $detail === 'code' ? ['payment_note' => null] : ['code' => null]);

    $this->actingAs(superAdmin(), 'admin');

    $names = listedFor($this, $event, ['missing' => $detail]);

    expect($names)->toBe([$hit->company->name])
        ->and(in_array($other->company->name, $names, true))->toBeFalse();
})->with([
    'payment status' => ['payment_status', ['payment_status' => null]],
    'payment note' => ['payment_note', ['payment_note' => null]],
    'craft code' => ['code', ['code' => '']],
    'booth size' => ['plot_size', ['plot_size' => null]],
]);

it('combines with the HQ-check filter, and is counted in "x / y"', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $waitingNoNote = rowMissing($event, ['payment_note' => null, 'verified_at' => null]);
    rowMissing($event, ['payment_note' => null]);            // verified, no note
    rowMissing($event, ['verified_at' => null]);             // waiting, complete

    $html = $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.events.registrations.index', [$event, 'status' => 'waiting', 'missing' => 'payment_note']))
        ->getContent();

    // e(): a faker name can carry an apostrophe ("O'Kon Ltd"), which the page escapes.
    expect($html)->toContain(e($waitingNoNote->company->name))
        ->and(preg_match('/id="filter-count"[^>]*>\s*1 \/ 3\s*</', $html))->toBe(1);
});

it('ignores a value it does not offer, rather than erroring', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    $complete = rowMissing($event, []);

    $this->actingAs(superAdmin(), 'admin');

    expect(listedFor($this, $event, ['missing' => 'companies.name']))->toBe([$complete->company->name]);
});

it('offers the five choices, keeping the applied one selected', function () {
    $event = Event::factory()->create(['status' => 'open', 'polling_status' => 'draft']);
    rowMissing($event, []);

    $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.events.registrations.index', [$event, 'missing' => 'code']))
        ->assertSeeInOrder(['Any details', 'Missing a detail', 'No payment status', 'No payment note', 'No craft code', 'No booth size'])
        ->assertSee('<option value="code" selected>No craft code</option>', false);
});
