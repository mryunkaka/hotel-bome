<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class EnsureHotelContext
{
    public function handle($request, Closure $next)
    {
        $user = Auth::user();

        // User biasa: pakai hotel_id miliknya
        if ($user?->hotel_id) {
            session(['active_hotel_id' => $user->hotel_id]);
            return $next($request);
        }

        // Super admin: wajib pilih hotel dulu
        if (! session('active_hotel_id')) {
            return redirect()->route('choose-hotel');
        }

        return $next($request);
    }
}
