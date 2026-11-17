{{--
    Locations, with their bidang allocations nested inside each one.

    The create wizard's second page and the edit form are the same form over the
    same columns, so they are the same file. They used to be two, and the two
    drifted: ten identical JavaScript functions and the whole nested markup,
    maintained twice, which is how the edit page ended up with a normaliser the
    create page never got.

    Expects:
      $event          — the Event being edited, or null while creating
      $groupedBidangs — from PollingController::groupedBidangs()

    A card here is a LOCATION. Its plots are its craft code allocations (code,
    size, plots) plus optional SPARE lines (size, plots) — plots with no code,
    the open pool a company draws from when its own code has run out. The
    location's totals are counted from those two and never typed: a typed
    "booth sizes here" section used to repeat what the allocations already said
    (removed 2026-11-17, owner's decision).

    Each (location, size) is one plot_sizes row — what a plot carries, and what
    a registration's booth size is matched against. Its quantity is the sum of
    the allocations and spares at that size.

    An allocation belongs to one location, not to the event, so it is nested
    under its location rather than listed flat. The link travels in a hidden
    plot_size_index, which reindexPlotSizes() keeps in step as rows move.
--}}
@php
    $event = $event ?? null;

    // One shape for all three sources — old input after a bounce, what is on
    // file when editing, and a single starter card when neither applies — so the
    // card below is written once instead of once per case.
    // 'spares' keeps its keys: they are the plot_sizes[i][spares][j] numbering.
    // On file, a pair's spares are what its allocations do not claim.
    $locationsToRender = old('plot_sizes')
        ? collect(old('plot_sizes'))->map(fn ($s) => [
            'location' => $s['location'] ?? '',
            'spares' => $s['spares'] ?? [],
        ])->values()
        : ($event?->plotSizes
            ->groupBy(fn ($s) => (string) $s->location)
            ->map(fn ($rows, $loc) => [
                'location' => $loc,
                'spares' => $rows
                    ->map(fn ($r) => [
                        'size' => (string) $r->size,
                        'quantity' => $r->quantity - $event->bidangAllocations->where('plot_size_id', $r->id)->sum('plots_count'),
                    ])
                    ->filter(fn ($line) => $line['quantity'] > 0)
                    ->values()->all(),
            ])
            ->values() ?? collect());

    if ($locationsToRender->isEmpty()) {
        $locationsToRender = collect([['location' => 'A', 'spares' => []]]);
    }

    $locationKeys = $locationsToRender->map(fn ($l) => (string) $l['location'])->all();

    // Old input still wins over what is stored.
    $allocationsToRender = old('bidang_allocations');
    if ($allocationsToRender === null) {
        $allocationsToRender = [];
        foreach ($event?->bidangAllocations ?? [] as $alloc) {
            $index = array_search((string) $alloc->plotSize?->location, $locationKeys, true);
            if ($index === false) continue;   // its location was deleted
            $allocationsToRender[] = [
                'bidang_id'       => $alloc->bidang_id,
                'plot_size_index' => $index,
                'size'            => $alloc->plotSize?->size,
                'plots_count'     => $alloc->plots_count,
            ];
        }
    }

    // preserveKeys: the outer key IS the bidang_allocations[n] index. update()
    // matches an existing allocation by its own id, so the numbering only has
    // to stay unique across the form.
    $allocationsBySize = collect($allocationsToRender)->groupBy('plot_size_index', true);

    // The sizes this form offers: Setting › Dropdown Lists, plus any size an
    // allocation already carries. The size has to match the spelling on the
    // registrations in the draw for the reference card to pair the two up, so it is
    // a list rather than a free field — but a plan already laid out in a size
    // since dropped from Settings must not lose it on the next save.
    $sizeOptions = collect(\App\Support\PlotSizes::all())
        ->merge(collect($allocationsToRender)->pluck('size'))
        ->merge($locationsToRender->pluck('spares')->flatten(1)->pluck('size'))
        ->map(fn ($s) => trim((string) $s))
        ->filter()
        ->unique()
        ->values();
@endphp

<div id="plotSizesContainer">
@foreach($locationsToRender as $psIndex => $loc)
    @php
        $allocsForSize = $allocationsBySize[$psIndex] ?? collect();
    @endphp
    <div class="plot-size-row mb-3" data-size-idx="{{ $psIndex }}" style="border:1px solid var(--ui-border);border-radius:12px;overflow:hidden;">
        <div class="p-3 px-4" style="background:var(--ui-surface-2);">
            <div class="row g-2 align-items-end">
                <div class="col">
                    <label class="mac-label">Location <span class="text-danger">*</span></label>
                    <input type="text" name="plot_sizes[{{ $psIndex }}][location]" class="mac-form-control plot-location" value="{{ $loc['location'] }}" placeholder="e.g., A" required>
                </div>
                <div class="col-auto d-flex align-items-end">
                    {{-- Counted by the script from the codes and spares below. --}}
                    <span class="mac-muted mac-text-sm me-2" style="line-height:38px;">Total: <strong class="location-total">0</strong> plots</span>
                    <button type="button" class="mac-action-btn-danger mac-action-btn-field remove-size" aria-label="Remove this location" title="Remove location" @if($locationsToRender->count() <= 1) disabled @endif><i class="bi bi-trash" aria-hidden="true"></i></button>
                </div>
            </div>
        </div>
        <div class="bidang-sub-container p-3" style="border-top:1px solid var(--ui-sep);background:var(--ui-surface-2);">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <small class="fw-semibold" style="color:var(--ui-text-2);">Craft Code Allocations</small>
                    {{-- Filled by the script: what is left at each size. --}}
                    <span class="size-remaining-badge mac-badge mac-badge-gray"></span>
                </div>
                <button type="button" class="add-bidang-btn mac-btn-secondary" style="font-size:0.8125rem;padding:4px 10px;">
                    <i class="bi bi-plus"></i> Add Craft Code
                </button>
            </div>
            <div class="bidang-rows-container">
                @foreach($allocsForSize as $baIdx => $alloc)
                    @php
                        // The Category select is not submitted, so which one to
                        // preselect is read back off the code that is saved.
                        $cat = $groupedBidangs->search(
                            fn ($group) => $group->contains('id', (int) ($alloc['bidang_id'] ?? 0))
                        ) ?: null;
                    @endphp
                    <div class="bidang-allocation-row mb-2 p-2" style="background:var(--ui-surface);border-radius:8px;border:1px solid var(--ui-border);">
                        <input type="hidden" name="bidang_allocations[{{ $baIdx }}][plot_size_index]" value="{{ $psIndex }}" class="bidang-size-index">
                        {{-- The allocation id used to be posted here, for savePlan()'s
                             isset($alloc['id']) arm. That arm never ran — no rule names the key,
                             so validate() strips it — and it was deleted together with this field
                             on 2026-08-27 (finding 26). Reviving either without the other opens a
                             cross-event write; the reason is in savePlan(). --}}
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="mac-label">Category <span class="text-danger">*</span></label>
                                {{-- Not submitted: it only narrows the Code list beside it. --}}
                                <select class="mac-select main-category-select" required>
                                    <option value="">-- Category --</option>
                                    @foreach($groupedBidangs->keys() as $category)
                                        <option value="{{ $category }}" {{ $cat === $category ? 'selected' : '' }}>{{ $category }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="mac-label">Code <span class="text-danger">*</span></label>
                                <select name="bidang_allocations[{{ $baIdx }}][bidang_id]" class="mac-select bidang-select" required>
                                    <option value="">-- Code --</option>
                                    @if($cat)
                                        @foreach($groupedBidangs[$cat] as $bidang)
                                            <option value="{{ $bidang['id'] }}" {{ ($alloc['bidang_id'] ?? '') == $bidang['id'] ? 'selected' : '' }}>
                                                [{{ $bidang['code'] }}] {{ $bidang['pecahan_bidang'] ?? $bidang['name'] }}
                                            </option>
                                        @endforeach
                                    @endif
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="mac-label">Size <span class="text-danger">*</span></label>
                                {{-- Any size in Settings: picking it here is what puts that size in the location. --}}
                                <select name="bidang_allocations[{{ $baIdx }}][size]" class="mac-select bidang-size" required>
                                    @foreach($sizeOptions as $option)
                                        <option value="{{ $option }}" {{ ($alloc['size'] ?? '') === $option ? 'selected' : '' }}>{{ $option }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="mac-label">Plots <span class="text-danger">*</span> <span class="mac-muted" style="font-weight:400;">auto</span></label>
                                <input type="number" name="bidang_allocations[{{ $baIdx }}][plots_count]" class="mac-form-control plots-count" value="{{ $alloc['plots_count'] ?? 1 }}" required min="1" title="Filled from the companies in the draw holding this code — override it if the plan says otherwise.">
                            </div>
                            <div class="col-md-1 d-flex align-items-end">
                                <button type="button" class="mac-action-btn-danger mac-action-btn-field remove-bidang" aria-label="Remove this bidang allocation" title="Remove bidang allocation"><i class="bi bi-trash" aria-hidden="true"></i></button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Optional. Plots with no code: the open pool at that size, drawn by a
             company whose own code has run out. None is a valid answer. --}}
        <div class="spare-container p-3" style="border-top:1px solid var(--ui-sep);">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <small class="fw-semibold" style="color:var(--ui-text-2);">
                    Spare plots <span class="mac-muted" style="font-weight:400;">— no code, optional</span>
                </small>
                <button type="button" class="add-spare-btn mac-btn-secondary" style="font-size:0.8125rem;padding:4px 10px;">
                    <i class="bi bi-plus"></i> Add spare plots
                </button>
            </div>
            <div class="spare-lines">
                @foreach($loc['spares'] as $lineIdx => $line)
                    <div class="spare-line d-flex align-items-center gap-2 mb-2">
                        <select name="plot_sizes[{{ $psIndex }}][spares][{{ $lineIdx }}][size]" class="mac-select spare-size" required aria-label="Spare plot size" style="max-width:140px;">
                            @foreach($sizeOptions as $option)
                                <option value="{{ $option }}" @selected(trim((string) ($line['size'] ?? '')) === $option)>{{ $option }}</option>
                            @endforeach
                        </select>
                        <span class="mac-muted">×</span>
                        <input type="number" name="plot_sizes[{{ $psIndex }}][spares][{{ $lineIdx }}][quantity]" class="mac-form-control spare-quantity" value="{{ $line['quantity'] ?? 1 }}" required min="1" aria-label="Spare plots of this size" style="max-width:110px;">
                        <span class="mac-muted mac-text-sm">plots</span>
                        <button type="button" class="mac-action-btn mac-action-btn-danger remove-spare" aria-label="Remove these spare plots" title="Remove"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endforeach
</div>{{-- /plotSizesContainer --}}

<div class="alert alert-info mt-3">
    <i class="bi bi-info-circle"></i>
    <strong>Total plots:</strong> <span id="calculatedTotal">0</span> &nbsp;|&nbsp;
    <strong>With a craft code:</strong> <span id="allocationTotal">0</span> &nbsp;|&nbsp;
    <strong>Spare:</strong> <span id="spareTotal">0</span>
</div>
