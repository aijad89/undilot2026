@extends('layouts.user-auth')

@section('title', 'Login')

@section('content')
<div style="width: 100%;">
    <div class="mb-4">
        <h2 class="fw-bold mb-1">Welcome back</h2>
        <p class="text-muted mb-0" style="font-size: 0.875rem;">Sign in to your account</p>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ $errors->first() }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        {{-- Community ID + IC, no password (LoginRequest). No "Forgot password":
             participants have no email to receive a link. --}}
        <div class="mb-3">
            <label for="community_id" class="form-label fw-semibold">Community ID (ID PKKM)</label>
            <input type="text"
                   id="community_id"
                   name="community_id"
                   class="form-control form-control-lg @error('community_id') is-invalid @enderror"
                   value="{{ old('community_id') }}"
                   autocomplete="username"
                   required autofocus>
            @error('community_id')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="ic_number" class="form-label fw-semibold">IC number</label>
            <input type="text"
                   id="ic_number"
                   name="ic_number"
                   inputmode="numeric"
                   class="form-control form-control-lg @error('ic_number') is-invalid @enderror"
                   placeholder="890508-12-0000"
                   autocomplete="off"
                   required>
            @error('ic_number')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-flex justify-content-between align-items-center mb-4">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="remember_me" name="remember">
                <label class="form-check-label text-muted" for="remember_me">Remember me</label>
            </div>
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg">Sign In</button>
        </div>
    </form>

    {{-- Only where /auto is registered (local, testing, e2e) — never on a server. --}}
    @if(Route::has('dev.auto'))
        <div class="d-grid mt-2">
            <a href="{{ route('dev.auto') }}" class="btn btn-outline-secondary">Quick sign in (dev only)</a>
        </div>
    @endif
</div>
@endsection
