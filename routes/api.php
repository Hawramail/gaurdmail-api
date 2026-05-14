<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ZohoMailController;
use App\Http\Controllers\OcrController;
use App\Http\Controllers\SiemController;

// ── Public ────────────────────────────────────────────────────────────────────
// Exchange a valid Zoho OAuth token for a Sanctum session token.
Route::post('/auth/login', [AuthController::class, 'login']);

// CORS preflight — must be outside auth middleware
Route::options('/auth/login',                    fn() => response()->json([], 200));
Route::options('/zoho/accounts',                 fn() => response()->json([], 200));
Route::options('/zoho/sendEmailwAttachments',    fn() => response()->json([], 200));
Route::options('/ocr/extract',                   fn() => response()->json([], 200));

// ── Protected (Sanctum token required) ───────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Zoho — user identity now comes from the authenticated session, not a header
    Route::post('/zoho/accounts',              [ZohoMailController::class, 'getAccounts'])
         ->middleware('siem.log');

    Route::post('/zoho/sendEmailwAttachments', [ZohoMailController::class, 'sendEmailwAttachments'])
         ->middleware(['throttle:email-send', 'siem.log']);

    // OCR
    Route::post('/ocr/extract', [OcrController::class, 'extract'])
         ->middleware('siem.log');

    // SIEM dashboard API
    Route::prefix('siem')->group(function () {
        Route::get   ('/stats',                       [SiemController::class, 'stats']);
        Route::post  ('/events',                      [SiemController::class, 'logEvent']);
        Route::patch ('/alerts/{alertId}/acknowledge', [SiemController::class, 'acknowledge']);
        Route::patch ('/alerts/{alertId}/resolve',    [SiemController::class, 'resolve']);
    });
});
