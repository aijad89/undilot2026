@extends('layouts.admin')

@section('title', $category->display_name.' Companies')

@section('content')
@include('partials.mac-styles')

<div class="mac-page">
    @include('partials.admin-alerts')

    <div class="mac-header mb-4">
        <h2>{{ $category->display_name }}: Companies</h2>
        <div class="mac-breadcrumb">
            <a href="{{ route('admin.dashboard') }}">Dashboard</a> <span class="sep" aria-hidden="true">›</span>
            Setting <span class="sep" aria-hidden="true">›</span>
            <a href="{{ route('admin.settings.kraf-hierarchy') }}#category-{{ $category->id }}">Craft Hierarchy</a>
            <span class="sep" aria-hidden="true">›</span> {{ $category->display_name }}
        </div>
        <p class="mac-muted mb-0">
            Companies that picked {{ $category->display_name }}. Filters apply to one selection together:
            a sub-category, and a classification and product ticked under it.
        </p>
    </div>

    {{-- Classifications and products are grouped by their sub-category, so the
         same name under two sub-categories can be told apart. --}}
    <form method="GET" class="mac-filter-bar mb-3">
        <select name="sub" class="mac-select" aria-label="Filter by sub-category">
            <option value="">All sub-categories</option>
            @foreach($category->subBidangs as $sub)
                <option value="{{ $sub->id }}" @selected((string) ($filters['sub'] ?? '') === (string) $sub->id)>{{ $sub->name }}</option>
            @endforeach
        </select>

        <select name="pengelasan" class="mac-select" aria-label="Filter by classification">
            <option value="">All classifications</option>
            @foreach($category->subBidangs as $sub)
                @if($sub->pengelasan->isNotEmpty())
                    <optgroup label="{{ $sub->name }}">
                        @foreach($sub->pengelasan as $pengelasan)
                            <option value="{{ $pengelasan->id }}" @selected((string) ($filters['pengelasan'] ?? '') === (string) $pengelasan->id)>{{ $pengelasan->name }}</option>
                        @endforeach
                    </optgroup>
                @endif
            @endforeach
        </select>

        <select name="produk" class="mac-select" aria-label="Filter by product">
            <option value="">All products</option>
            @foreach($category->subBidangs as $sub)
                @if($sub->produks->isNotEmpty())
                    <optgroup label="{{ $sub->name }}">
                        @foreach($sub->produks as $produk)
                            <option value="{{ $produk->id }}" @selected((string) ($filters['produk'] ?? '') === (string) $produk->id)>{{ $produk->name }}</option>
                        @endforeach
                    </optgroup>
                @endif
            @endforeach
        </select>

        <button type="submit" class="mac-filter-btn">Filter</button>

        @if(array_filter($filters))
            <a href="{{ route('admin.settings.kraf-hierarchy.companies', $category) }}" class="mac-clear-btn">Clear</a>
        @endif
    </form>

    {{-- Clears what the filters above match, on every page of the list, not just this one. --}}
    @if($companies->total() > 0)
        <form method="POST" action="{{ route('admin.settings.kraf-hierarchy.companies.reset-all', [$category, ...array_filter($filters)]) }}"
              class="mb-3 text-end">
            @csrf
            @method('DELETE')
            <button type="submit" class="mac-btn-secondary"
                    data-confirm="{{ array_filter($filters)
                        ? "Remove the matching {$category->display_name} craft selections from all {$companies->total()} companies listed? The companies stay."
                        : "Remove every {$category->display_name} craft selection from all {$companies->total()} companies? The companies stay." }}">
                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>
                Reset all {{ $companies->total() }} {{ \Illuminate\Support\Str::plural('company', $companies->total()) }}
            </button>
        </form>
    @endif

    <div class="mac-card">
        <div class="table-responsive">
            <table class="mac-table">
                <thead>
                    <tr>
                        <th class="text-end">No.</th>
                        <th>Company</th>
                        <th>Owner</th>
                        <th>Zone</th>
                        <th>Sub-category</th>
                        <th>Classification</th>
                        <th>Product</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($companies as $company)
                        <tr>
                            <td class="text-end mac-muted">{{ $companies->firstItem() + $loop->index }}</td>
                            <td>
                                <strong>{{ $company->name }}</strong>
                                @unless($company->is_active)
                                    <div><span class="mac-badge mac-badge-gray">Switched off</span></div>
                                @endunless
                            </td>
                            <td>{{ $company->user->name }}</td>
                            <td class="mac-text-sm">{{ $company->state ?? '—' }}</td>
                            {{-- One line per selection under this category, lined up across the three columns. --}}
                            <td class="mac-text-sm">
                                @foreach($company->krafSelections as $selection)
                                    <div>{{ $selection->subBidang?->name }}</div>
                                @endforeach
                            </td>
                            <td class="mac-text-sm">
                                @foreach($company->krafSelections as $selection)
                                    <div>{{ $selection->pengelasan->pluck('name')->join(', ') ?: '—' }}</div>
                                @endforeach
                            </td>
                            <td class="mac-text-sm">
                                @foreach($company->krafSelections as $selection)
                                    <div>{{ $selection->produks->pluck('name')->join(', ') ?: '—' }}</div>
                                @endforeach
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.companies.edit', $company) }}" class="mac-action-btn" title="Edit company">
                                    <i class="bi bi-pencil"></i>
                                </a>

                                <form method="POST" action="{{ route('admin.settings.kraf-hierarchy.companies.reset', [$category, $company]) }}"
                                      class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="mac-action-btn mac-action-btn-danger"
                                            title="Reset {{ $category->display_name }} for this company"
                                            data-confirm="Remove every {{ $category->display_name }} craft selection from &quot;{{ $company->name }}&quot;? The company stays.">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8">
                                <div class="mac-empty">
                                    <div class="mac-empty-icon"><i class="bi bi-building"></i></div>
                                    <p class="mac-empty-text">
                                        @if(array_filter($filters))
                                            No company matches those filters.
                                        @else
                                            No company has picked {{ $category->display_name }} yet.
                                        @endif
                                    </p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $companies->links() }}</div>
</div>
@endsection
