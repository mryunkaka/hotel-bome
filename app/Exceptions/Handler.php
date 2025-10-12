<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Filament\Facades\Filament;

class Handler extends ExceptionHandler
{
    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e)
    {
        // Jika user memaksa akses halaman admin yang tidak berizin (403 from Gate/Resource),
        // alihkan ke dashboard + set flag untuk notif.
        if ($e instanceof AuthorizationException && $request->is('admin*')) {
            session()->flash('forbidden_to_filament_dashboard', true);
            return redirect()->to(Filament::getHomeUrl());
        }

        return parent::render($request, $e);
    }
}
