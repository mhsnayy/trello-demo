<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Demo: şifreli giriş yok, kullanıcı seçilmediyse yönetici olarak devam edilir. */
class UseMockUser
{
    public function handle(Request $request, Closure $next)
    {
        if (! Auth::check()) {
            Auth::login(User::where('is_admin', true)->firstOrFail());
        }

        return $next($request);
    }
}
