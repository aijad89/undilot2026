<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * The participant's own profile: their account and company as the office keeps
 * them.
 *
 * The office creates and keeps the account (there is no self sign-up), so the
 * facts here are read-only. There is no password to change: participants sign
 * in with community ID + IC (owner's decision, 2026-11-17), and the account's
 * password is a random one nobody is told.
 */
class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return view('profile', ['user' => $user, 'company' => $user->company]);
    }
}
