<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(
            ['email' => 'required|string|max:255', 'password' => 'required|string|max:255'],
            ['required' => 'E-posta ve şifre gerekli.'],
        );
        $email = Str::lower(trim($data['email']));
        $key = 'login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Çok fazla deneme yapıldı. '.RateLimiter::availableIn($key).' saniye sonra tekrar deneyin.',
            ]);
        }
        if (! Auth::attempt(['email' => $email, 'password' => $data['password']])) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages(['email' => 'E-posta veya şifre hatalı.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect('/');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function showPassword()
    {
        return view('password');
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|string|min:8|max:72|confirmed|different:current_password',
        ], [
            'required' => 'Tüm alanları doldurun.',
            'current_password' => 'Mevcut şifre hatalı.',
            'min' => 'Yeni şifre en az 8 karakter olmalı.',
            'max' => 'Yeni şifre en fazla 72 karakter olabilir.',
            'confirmed' => 'Yeni şifreler birbirini tutmuyor.',
            'different' => 'Yeni şifre eskisinden farklı olmalı.',
        ]);

        // Diğer cihazlardaki oturumlar auth.session sayesinde kapanır.
        $request->user()->update(['password' => $data['password'], 'must_change_password' => false]);
        $request->session()->regenerate();

        return redirect('/');
    }
}
