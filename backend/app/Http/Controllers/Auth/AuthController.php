<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    // ── Member Login ──────────────────────────────────────────────────────────

    public function showLogin()
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('member.portal');
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required',
            'password' => 'required'
        ]);

        $credentials = [
            'email' => $request->username,
            'password' => $request->password
        ];

        // Also allow login with email or username
        $member = Member::where('username', $request->username)
            ->orWhere('email', $request->username)
            ->first();

        // Wrap Hash::check — Laravel 11 throws RuntimeException on null/invalid hash
        $authenticated = false;
        if ($member) {
            try {
                $authenticated = Hash::check($request->password, $member->password);
            } catch (\Throwable $e) {
                // Fallback: plaintext match untuk data legacy
                $authenticated = ($member->password === $request->password);
            }
        }

        if (!$authenticated) {
            return back()->withErrors([
                'username' => 'Kredensial tidak valid.',
            ])->withInput($request->only('username'));
        }

        // Login via guard web (member)
        Auth::guard('web')->login($member, $request->boolean('remember'));
        $request->session()->regenerate();

        // Redirect admin ke halaman admin dashboard secara otomatis
        $adminRoles = ['super_admin', 'admin_kelas', 'admin_member', 'admin_konten'];
        if (in_array($member->role, $adminRoles)) {
            // Kita loginkan juga di guard admin agar sesi admin aktif
            Auth::guard('admin')->login($member, $request->boolean('remember'));
            return redirect()->route('admin.dashboard');
        }

        return redirect()->intended(route('member.portal'));
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    // ── Member Register ───────────────────────────────────────────────────────

    public function showRegister()
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('member.portal');
        }
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $request->validate([
            'full_name'   => 'required|string|max:255',
            'username'    => 'required|string|max:50|unique:members,username',
            'phone'       => 'required|string|max:20',
            'institution' => 'nullable|string|max:255',
            'password'    => 'required|string|min:8|confirmed',
            'photo'       => 'nullable|image|max:2048',
        ], [
            'full_name.required'  => 'Nama lengkap wajib diisi.',
            'username.required'   => 'Username wajib diisi.',
            'username.unique'     => 'Username sudah digunakan.',
            'phone.required'      => 'Nomor HP wajib diisi.',
            'password.required'   => 'Kata sandi wajib diisi.',
            'password.min'        => 'Kata sandi minimal 8 karakter.',
            'password.confirmed'  => 'Konfirmasi kata sandi tidak cocok.',
        ]);

        $photoBase64 = null;
        if ($request->hasFile('photo')) {
            $photoBase64 = base64_encode(file_get_contents($request->file('photo')->getRealPath()));
        }

        $member = Member::create([
            'member_id'   => Member::generateMemberId(),
            'full_name'   => $request->full_name,
            'username'    => $request->username,
            'phone'       => $request->phone,
            'institution' => $request->institution ?? '',
            'password'    => Hash::make($request->password),
            'photo_base64' => $photoBase64,
        ]);

        Auth::guard('web')->login($member);
        $request->session()->regenerate();

        return redirect()->route('member.portal')
            ->with('success', 'Selamat datang, ' . $member->full_name . '!');
    }
}
