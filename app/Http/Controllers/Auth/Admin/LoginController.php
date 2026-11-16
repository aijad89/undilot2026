<?php

namespace App\Http\Controllers\Auth\Admin;

use App\Http\Requests\Auth\AdminLoginRequest;
use App\Support\IntendedUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        return view('admin.login');
    }

    public function login(AdminLoginRequest $request)
    {
        $request->authenticate();

        $request->session()->regenerate();

        // Not intended(): the participant guard writes the same session key, so
        // a page bounced from the participant side is ignored here (K09).
        return IntendedUrl::redirect($request, admin: true, fallback: route('admin.dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }
}
