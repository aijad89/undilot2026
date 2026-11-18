<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;

/*
 * NO REGISTRATION ROUTE, NO EMAIL VERIFICATION, AND NO PASSWORD RESET.
 *
 * Accounts are created by an admin from the Company screen — one company, one
 * account — so there is no self-service sign-up to verify an address for.
 *
 * Participants sign in with community ID + IC and hold no password, and the app
 * sends no email at all (owner's decision, 2026-11-17), so the forgot-password
 * and reset-password routes were removed with their controllers and views.
 * Admins reset each other's passwords from the admin screens.
 *
 * That makes `is_active` the switch that matters rather than `email_verified_at`,
 * and EnforceUserIsActive turns switching it off into an immediate logout rather
 * than a session that quietly keeps working.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware(['auth', 'user.active'])->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

/*
 * ONE WARNING FOR WHOEVER ADDS A CHANGE-PASSWORD ROUTE HERE LATER, because the
 * mistake it describes shipped once and took six months to surface.
 *
 * A ROUTE NAME THAT RESOLVES BUT DOES NOT WORK IS WORSE THAN NO NAME AT ALL.
 *
 * In the system this repo came from, `auth.php` declared
 *
 *     Route::put('password', [PasswordController::class, 'update'])->name('password.update');
 *
 * behind `auth`, and the GUEST reset-password form pointed at
 * `route('password.update')`. The name resolved, so there was no
 * RouteNotFoundException at render, the page looked right, and it reviewed
 * clean. It failed only on submit — as a 405, in production, to somebody trying
 * to recover an account they were locked out of. A POST from a guest matches
 * nothing, Laravel's checkForAlternateVerbs() finds the PUT, and the request
 * dies as MethodNotAllowedHttpException. Nothing upstream of the submit can see
 * it coming.
 *
 * With no such name declared, the same mistake throws at render on the first
 * page load instead.
 */
