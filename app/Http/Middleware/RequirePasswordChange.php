<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** İlk şifreyle giren kullanıcı, şifresini değiştirmeden panoya ulaşamaz. */
class RequirePasswordChange
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()->must_change_password) {
            abort_if($request->expectsJson(), 403, 'Önce şifrenizi değiştirin.');

            return redirect()->route('password');
        }

        return $next($request);
    }
}
