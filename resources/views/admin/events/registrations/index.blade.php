@extends('layouts.admin')

@section('title', 'Draw List')

@section('content')
@include('partials.mac-styles')

<div class="mac-page">
    @include('partials.admin-alerts')

    <div class="mac-breadcrumb mb-2">
        <a href="{{ route('admin.events.index') }}">Events</a> <span class="sep" aria-hidden="true">›</span> {{ $event->title }}
    </div>

    <div class="mac-header mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h2>Draw List</h2>
            <p class="mac-muted mb-0">
                {{ $event->title }} — {{ $event->start_date->format('d M Y') }}.
                The zone checks each company and puts it on this list; HQ verifies it.
                The company is told, and the draw can open, only once HQ has verified every row.
            </p>
            @if($waitingCount)
                <a href="{{ route('admin.events.registrations.index', [$event, 'status' => 'waiting']) }}" class="mac-badge mac-badge-orange mt-2 d-inline-block">
                    {{ $waitingCount }} waiting for HQ
                </a>
            @endif
        </div>

        <div class="d-flex gap-2 flex-wrap">
            @if($undoCount)
                <form method="POST" action="{{ route('admin.events.registrations.undo', $event) }}">
                    @csrf
                    <button type="submit" class="mac-btn-secondary">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Undo last removal ({{ $undoCount }})
                    </button>
                </form>
            @endif

            {{-- Shown even when the zone is locked: the page says why it cannot
                 add, and Edit / Remove there are still asked of the server. --}}
            <a href="{{ route('admin.events.registrations.companies', $event) }}" class="mac-btn-primary">
                <i class="bi bi-plus-lg me-1"></i> Add companies
            </a>
        </div>
    </div>

    <form method="GET" class="mac-filter-bar mb-3">
        <input type="search" name="search" value="{{ request('search') }}"
               class="mac-input" placeholder="Company name" aria-label="Search the draw list">

        <select name="code" class="mac-select" aria-label="Filter by craft code">
            <option value="">All codes</option>
            @foreach($filterCodes as $filterCode)
                <option value="{{ $filterCode }}" @selected(request('code') === $filterCode)>{{ $filterCode }}</option>
            @endforeach
        </select>

        <select name="status" class="mac-select" aria-label="Filter by HQ check">
            <option value="">Any check</option>
            <option value="waiting" @selected(request('status') === 'waiting')>Waiting for HQ</option>
            <option value="returned" @selected(request('status') === 'returned')>Returned by HQ</option>
            <option value="verified" @selected(request('status') === 'verified')>Verified</option>
        </select>

        <select name="missing" class="mac-select" aria-label="Filter by missing details">
            <option value="">Any details</option>
            @foreach($missingFilters as $value => $label)
                <option value="{{ $value }}" @selected(request('missing') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <button type="submit" class="mac-filter-btn">Filter</button>

        @if(request()->hasAny(['search', 'code', 'status', 'missing']))
            <a href="{{ route('admin.events.registrations.index', $event) }}" class="mac-clear-btn">Clear</a>
        @endif

        @if($filtered)
            <span class="mac-badge mac-badge-gray align-self-center" id="filter-count"
                  title="Rows matching the filter, of the rows on this list">
                {{ $registrations->total() }} / {{ $listTotal }}
            </span>
        @endif
    </form>

    <div class="mac-card">
            <div class="table-responsive">
                <table class="mac-table">
                    <thead>
                        <tr>
                            <th style="width:32px;">
                                <input type="checkbox" id="tick-all" aria-label="Select every row">
                            </th>
                            <th>Company</th>
                            <th>Account</th>
                            <th>Payment</th>
                            <th>HQ check</th>
                            <th>Craft code</th>
                            <th>Booth</th>
                            <th>Lot</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($registrations as $registration)
                            <tr>
                                <td data-label="Select">
                                    {{-- Associated with the bulk form BELOW the table by id.
                                         The per-row forms sit inside this table, and a form
                                         inside a form is not a thing a browser will send. --}}
                                    <input type="checkbox" name="registration_id[]" value="{{ $registration->id }}"
                                           class="tick-row" form="bulk-form"
                                           aria-label="Select {{ $registration->company->name }}">
                                </td>
                                <td style="overflow-wrap: anywhere">
                                    <strong>{{ $registration->company->name }}</strong>
                                    <div class="mac-text-sm mac-muted">{{ $registration->company->state }}</div>
                                </td>
                                <td class="mac-text-sm" style="overflow-wrap: anywhere">
                                    @if($registration->user_id)
                                        {{ $registration->company->user->email }}
                                    @else
                                        <span class="mac-badge mac-badge-orange">No account</span>
                                    @endif
                                </td>

                                <td class="mac-text-sm">
                                    {{-- Every company is added by the office, so the officer
                                         records whether it paid, and how: receipt no., date, method. --}}
                                    <form method="POST" action="{{ route('admin.events.registrations.payment-note', [$event, $registration]) }}" class="d-flex gap-1">
                                        @csrf
                                        @method('PUT')
                                        <select name="payment_status" class="mac-select mac-action-btn-field" style="min-width: 100px"
                                                aria-label="Payment status for {{ $registration->company->name }}">
                                            <option value="">— Status —</option>
                                            @foreach(\App\Models\EventRegistration::PAYMENT_STATUSES as $key => $label)
                                                <option value="{{ $key }}" @selected($registration->payment_status === $key)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" name="payment_note" value="{{ $registration->payment_note }}" maxlength="500"
                                               class="mac-form-control mac-action-btn-field" style="min-width: 120px" placeholder="Receipt no., date"
                                               aria-label="Payment note for {{ $registration->company->name }}">
                                        <button type="submit" class="mac-action-btn" title="Save payment"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                    @if(! $registration->payment_status)
                                        <div class="mac-muted mt-1">Payment status not recorded yet.</div>
                                    @elseif($registration->payment_status === 'unpaid')
                                        <span class="mac-badge mac-badge-red mt-1">Not paid</span>
                                    @endif
                                    @unless($registration->payment_note)
                                        <div class="mac-muted mt-1">Added by the office, no payment note yet.</div>
                                    @endunless
                                </td>

                                <td class="mac-text-sm">
                                    @if($registration->verified_at)
                                        <span class="mac-badge mac-badge-green">Verified</span>
                                    @elseif($registration->hq_note)
                                        <span class="mac-badge mac-badge-red">Returned by HQ</span>
                                        <div class="mt-1">{{ $registration->hq_note }}</div>
                                    @else
                                        <span class="mac-badge mac-badge-orange">Waiting for HQ</span>
                                        @if($registration->first_verified_at)
                                            <div class="mac-muted mt-1">Changed since HQ verified it.</div>
                                        @endif
                                    @endif
                                </td>

                                {{-- One row, one small form. The code and size are the
                                     two things the plan is built from, so they are
                                     edited where they are read. From HQ, the button
                                     is the verification: it saves and verifies. --}}
                                @if($codeFrozen)
                                <td>{{ $registration->code ?? '—' }}</td>
                                <td>{{ $registration->plot_size }}</td>
                                @else
                                <td colspan="2">
                                    <form method="POST"
                                          action="{{ route('admin.events.registrations.update', [$event, $registration]) }}"
                                          class="d-flex gap-1">
                                        @csrf
                                        @method('PUT')
                                        <select name="code" class="mac-select mac-action-btn-field" style="min-width: 110px" aria-label="Craft code">
                                            <option value="">— None —</option>
                                            @foreach($codes as $code)
                                                <option value="{{ $code->code }}" @selected($registration->code === $code->code)>
                                                    {{ $code->code }}
                                                </option>
                                            @endforeach
                                            @if($registration->code && ! $codes->contains('code', $registration->code))
                                                {{-- A code withdrawn since it was set. Still shown,
                                                     because the row carries it and hiding it would
                                                     silently blank it on the next save. --}}
                                                <option value="{{ $registration->code }}" selected>
                                                    {{ $registration->code }} (retired)
                                                </option>
                                            @endif
                                        </select>

                                        <select name="plot_size" class="mac-select mac-action-btn-field" style="min-width: 90px" aria-label="Booth">
                                            @foreach($plotSizes as $size)
                                                <option value="{{ $size }}" @selected($registration->plot_size === $size)>
                                                    {{ $size }}
                                                </option>
                                            @endforeach
                                            @if($registration->plot_size && ! in_array($registration->plot_size, $plotSizes, true))
                                                <option value="{{ $registration->plot_size }}" selected>
                                                    {{ $registration->plot_size }}
                                                </option>
                                            @endif
                                        </select>

                                        @if($isHq)
                                            <button type="submit" class="mac-action-btn" title="Verify (saves any change)">
                                                <i class="bi bi-check2-all"></i>
                                            </button>
                                        @else
                                            <button type="submit" class="mac-action-btn" title="Save — HQ verifies it again">
                                                <i class="bi bi-check-lg"></i>
                                            </button>
                                        @endif
                                    </form>
                                </td>
                                @endif

                                <td>
                                    @if($registration->plot)
                                        <span class="mac-badge mac-badge-green text-nowrap">{{ $registration->plot->plot_code }}</span>
                                    @else
                                        <span class="mac-muted mac-text-sm">—</span>
                                    @endif
                                </td>

                                <td class="text-end">
                                    @php($missing = $registration->missingDetails())
                                    @if($missing)
                                        <div class="mb-1">
                                            <span class="mac-badge mac-badge-orange text-nowrap missing-count"
                                                  title="Missing: {{ implode(', ', $missing) }}">
                                                {{ count($missing) }} missing
                                            </span>
                                        </div>
                                    @endif
                                    @if($isHq && ! $codeFrozen)
                                        <details class="mac-pop d-inline-block text-start mb-1">
                                            <summary class="mac-text-sm">Return</summary>
                                            <form method="POST" action="{{ route('admin.events.registrations.return', [$event, $registration]) }}" class="d-flex gap-1 mt-1">
                                                @csrf
                                                <input type="text" name="hq_note" class="mac-form-control mac-action-btn-field" placeholder="What to fix" aria-label="What the zone should fix" required maxlength="1000">
                                                <button type="submit" class="mac-action-btn mac-action-btn-danger" title="Return to the zone">
                                                    <i class="bi bi-arrow-return-left"></i>
                                                </button>
                                            </form>
                                        </details>
                                    @endif
                                    {{-- Told it is in: only HQ takes it out, and says why. --}}
                                    @if($registration->first_verified_at && $isHq)
                                        <details class="mac-pop d-inline-block text-start">
                                            <summary class="mac-text-sm">Remove</summary>
                                            <form method="POST" action="{{ route('admin.events.registrations.destroy', [$event, $registration]) }}" class="d-flex gap-1 mt-1">
                                                @csrf
                                                @method('DELETE')
                                                <input type="text" name="removal_reason" class="mac-form-control mac-action-btn-field" style="min-width: 140px" placeholder="Reason, sent to the company" aria-label="Why {{ $registration->company->name }} is removed" required maxlength="1000">
                                                <button type="submit" class="mac-action-btn mac-action-btn-danger" title="Remove from the draw list">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </details>
                                    @elseif(! $registration->first_verified_at)
                                    <form method="POST"
                                          action="{{ route('admin.events.registrations.destroy', [$event, $registration]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="mac-action-btn mac-action-btn-danger"
                                                title="Remove from the draw list"
                                                data-confirm="Remove {{ $registration->company->name }} from this draw?">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9">
                                    <div class="mac-empty">
                                        <div class="mac-empty-icon"><i class="bi bi-list-check"></i></div>
                                        <p class="mac-empty-text">Nobody is in this draw yet.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
    </div>

    @if($registrations->isNotEmpty())
        <form method="POST" action="{{ route('admin.events.registrations.bulk', $event) }}"
              id="bulk-form" class="mac-card mac-card-padded mt-3">
            @csrf
            <h3 class="mac-card-title">With the ticked rows</h3>

                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="mac-label" for="bulk-action">Action</label>
                        <select id="bulk-action" name="action" class="mac-select" required>
                            @if($isHq)
                            <option value="verify">Verify</option>
                            @unless($codeFrozen)
                            <option value="return">Return to the zone</option>
                            @endunless
                            @endif
                            @unless($codeFrozen)
                            <option value="set_code">Set craft code</option>
                            <option value="set_size">Set booth size</option>
                            @endunless
                            <option value="remove">Remove from the draw</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="mac-label" for="bulk-code">Craft code</label>
                        <select id="bulk-code" name="code" class="mac-select">
                            <option value="">— None —</option>
                            @foreach($codes as $code)
                                <option value="{{ $code->code }}">{{ $code->code }} — {{ $code->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label class="mac-label" for="bulk-size">Booth size</label>
                        <select id="bulk-size" name="plot_size" class="mac-select">
                            @foreach($plotSizes as $size)
                                <option value="{{ $size }}">{{ $size }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-3">
                        <button type="submit" class="mac-btn-primary w-100"
                                data-confirm="Apply this to every ticked row?">
                            Apply
                        </button>
                    </div>
                </div>

            {{-- Each shown only for its own action (the script below); both are
                 in the markup, so the form still works with no script at all. --}}
            @if($isHq)
            <div class="mt-3" data-bulk-for="remove">
                <label class="mac-label" for="bulk-reason">Reason for removing</label>
                <input type="text" id="bulk-reason" name="removal_reason" class="mac-form-control" maxlength="1000"
                       placeholder="Needed for companies already told they are in; sent to them">
            </div>
            @unless($codeFrozen)
            <div class="mt-3" data-bulk-for="return">
                <label class="mac-label" for="bulk-note">Note to the zone</label>
                <input type="text" id="bulk-note" name="hq_note" class="mac-form-control" maxlength="1000"
                       placeholder="What the zone should fix">
            </div>
            @endunless
            @endif
            <p class="mac-muted mac-text-sm mt-2 mb-0">
                A row holding a lot is kept, whatever is ticked. Withdraw the lot first.
            </p>
        </form>
    @endif

    <div class="mt-3">{{ $registrations->links() }}</div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const all = document.getElementById('tick-all');

    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('.tick-row').forEach(box => { box.checked = all.checked; });
        });
    }

    // The reason and the note belong to Remove and Return alone. Hidden for
    // any other action, and required while shown for Return, whose note the
    // server insists on.
    const action = document.getElementById('bulk-action');

    if (action) {
        const sync = function () {
            document.querySelectorAll('[data-bulk-for]').forEach(field => {
                const shown = field.dataset.bulkFor === action.value;
                field.hidden = ! shown;
                field.querySelector('input').required = shown && action.value === 'return';
            });
        };

        action.addEventListener('change', sync);
        sync();
    }
});
</script>
@endpush
@endsection
