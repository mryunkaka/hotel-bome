<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CardScanController;
use App\Http\Middleware\VerifyCsrfToken;

// stateless endpoints
Route::middleware('api')->group(function () {
    Route::post('card-scan', [CardScanController::class, 'store'])
        ->name('api.card-scan.store')
        ->withoutMiddleware([VerifyCsrfToken::class]);

    Route::get('card-scans/latest', [CardScanController::class, 'latest'])
        ->name('api.card-scans.latest');
});
