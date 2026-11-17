<?php

use App\Models\BidangAllocation;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\KrafBidang;
use App\Models\Plot;
use App\Models\PlotSize;

/**
 * The plot painter: codes clicked onto plots instead of typed as counts. The
 * plots it leaves must be exactly what the typed plan would have built — same
 * numbering, same allocations — or the draw and every listing read it wrong.
 */
function paintBoard(): array
{
    $event = Event::factory()->create();
    $size = PlotSize::factory()->create(['event_id' => $event->id, 'location' => 'Hall A', 'size' => '3x3', 'quantity' => 3]);

    $plots = collect([1, 2, 3])->map(fn ($n) => Plot::factory()->on($size)->create(['plot_code' => "HALL A-3X3{$n}"]));

    return [$event, $size, $plots];
}

it('paints codes, renumbers the plots by them, and rebuilds the allocations', function () {
    [$event, $size, $plots] = paintBoard();
    $rkp = KrafBidang::factory()->code('RKP')->create();

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.polling.paint.update', $event), ['paint' => [
            $plots[0]->id => $rkp->id,
            $plots[1]->id => $rkp->id,
            $plots[2]->id => '',
        ]])
        ->assertRedirect(route('admin.polling.paint', $event));

    expect(Plot::where('bidang_id', $rkp->id)->pluck('plot_code')->sort()->values()->all())->toBe(['RKP01', 'RKP02'])
        ->and($plots[2]->fresh()->plot_code)->toBe('3X3-01')
        ->and(BidangAllocation::sole()->only(['bidang_id', 'plot_size_id', 'plots_count']))
            ->toBe(['bidang_id' => $rkp->id, 'plot_size_id' => $size->id, 'plots_count' => 2]);
});

it('refuses a craft category as paint, because no registration can match it', function () {
    [$event, , $plots] = paintBoard();
    $category = KrafBidang::factory()->create();

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.polling.paint.update', $event), ['paint' => [$plots[0]->id => $category->id]])
        ->assertSessionHasErrors('paint');

    expect($plots[0]->fresh()->bidang_id)->toBeNull();
});

it('leaves a plot from another event alone, whatever is posted for it', function () {
    [$event, , $plots] = paintBoard();
    $rkp = KrafBidang::factory()->code('RKP')->create();
    $foreign = Plot::factory()->create(['plot_code' => 'ELSEWHERE-1']);

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.polling.paint.update', $event), ['paint' => [
            $plots[0]->id => $rkp->id,
            $foreign->id => $rkp->id,
        ]]);

    expect($foreign->fresh()->bidang_id)->toBeNull()
        ->and($foreign->fresh()->plot_code)->toBe('ELSEWHERE-1');
});

it('locks the board once a lot is held', function () {
    [$event, , $plots] = paintBoard();
    $rkp = KrafBidang::factory()->code('RKP')->create();
    $plots[2]->update(['is_allocated' => true]);

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.polling.paint.update', $event), ['paint' => [$plots[0]->id => $rkp->id]])
        ->assertSessionHasErrors('error');

    expect($plots[0]->fresh()->bidang_id)->toBeNull();
});

it('offers the codes the draw holds, with how many hold each, and is super/hq only', function () {
    [$event] = paintBoard();
    KrafBidang::factory()->code('RKP')->create(['name' => 'HASIL RIMBA']);
    KrafBidang::factory()->code('TBPS')->create();
    EventRegistration::factory()->count(2)->coded('RKP')->create(['event_id' => $event->id]);

    $this->actingAs(superAdmin(), 'admin')
        ->get(route('admin.polling.paint', $event))
        ->assertOk()
        ->assertSee('data-code="RKP"', false)
        ->assertSee('0 / 2')
        ->assertDontSee('data-code="TBPS"', false);

    $this->actingAs(stateAdmin('Johor'), 'admin')
        ->get(route('admin.polling.paint', $event))
        ->assertForbidden();
});

it('numbers a code painted in two halls on from one to the next, as the typed plan does', function () {
    $event = Event::factory()->create();
    $hallA = PlotSize::factory()->create(['event_id' => $event->id, 'location' => 'Hall A', 'size' => '3x3', 'quantity' => 2]);
    $hallB = PlotSize::factory()->create(['event_id' => $event->id, 'location' => 'Hall B', 'size' => '3x3', 'quantity' => 2]);
    $a = collect([1, 2])->map(fn ($n) => Plot::factory()->on($hallA)->create(['plot_code' => "3X3-0{$n}"]));
    $b = collect([3, 4])->map(fn ($n) => Plot::factory()->on($hallB)->create(['plot_code' => "3X3-0{$n}"]));
    $rkp = KrafBidang::factory()->code('RKP')->create();

    $this->actingAs(superAdmin(), 'admin')
        ->put(route('admin.polling.paint.update', $event), ['paint' => [
            $a[0]->id => $rkp->id, $a[1]->id => $rkp->id,
            $b[0]->id => $rkp->id, $b[1]->id => $rkp->id,
        ]]);

    expect([$a[0]->fresh()->plot_code, $a[1]->fresh()->plot_code, $b[0]->fresh()->plot_code, $b[1]->fresh()->plot_code])
        ->toBe(['RKP01', 'RKP02', 'RKP03', 'RKP04']);
});
