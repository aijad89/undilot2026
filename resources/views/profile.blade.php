@extends('layouts.frontend')

@section('title', 'Profile')
@section('page-title', 'Profile')

@section('content')
@include('partials.apple-styles')

<div class="ap-page ap-dash">
    <div class="ap-page-header">
        <div>
            <h2 class="ap-page-title">Profile</h2>
            <p class="ap-page-subtitle">Your account and company, as the Kraftangan office keeps them.</p>
        </div>
    </div>

    {{-- One column, two cards down: account, company. No password card:
         participants sign in with community ID + IC and never hold a
         password (owner's decision, 2026-11-17). --}}
    <div class="ap-profile-stack">
        <section class="ap-card ap-card-padded">
            <h2 class="ap-section-title">Your account</h2>
            <dl class="ap-facts">
                <dt>Name</dt>
                <dd>{{ $user->name }}</dd>
                <dt>Community ID</dt>
                {{-- With the IC below, what they sign in with. --}}
                <dd class="ap-tabular">{{ $user->community_id ?: '—' }}</dd>
                <dt>Mobile phone</dt>
                <dd class="ap-tabular">{{ $user->phone ?: '—' }}</dd>
                <dt>IC number</dt>
                {{-- In full: it is the participant's own, on their own page. --}}
                <dd class="ap-tabular">{{ $user->ic_number ?: '—' }}</dd>
                <dt>Address</dt>
                {{-- Line breaks kept as the office typed them. --}}
                <dd style="white-space:pre-line;">{{ $user->address ?: '—' }}</dd>
            </dl>
        </section>

        <section class="ap-card ap-card-padded">
            <h2 class="ap-section-title">Your company</h2>
            @if($company)
                <dl class="ap-facts">
                    <dt>Company</dt>
                    <dd>{{ $company->name }}</dd>
                    <dt>Zone</dt>
                    <dd>{{ $company->state ?: '—' }}</dd>
                    <dt>Usual booth size</dt>
                    <dd class="ap-tabular">{{ $company->plot_size ?: '3x3' }}</dd>
                </dl>
            @else
                <p class="ap-side-note">Your account has no company yet.</p>
            @endif
            <p class="ap-side-note mb-0">The office keeps these details. If any is wrong, contact the Kraftangan office to change it.</p>
        </section>
    </div>
</div>
@endsection
