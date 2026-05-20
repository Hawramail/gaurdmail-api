<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| This file defines all HTTP API routes for the MailGuard application.
|
| Public routes (no token needed):
|   POST /auth/login                        — exchange a Zoho OAuth token for a Sanctum session token
|   OPTIONS /*                              — CORS preflight responses, kept outside auth middleware
|
| Protected routes (Bearer token via auth:sanctum required):
|   POST   /auth/logout                     — AuthController        — deletes the Sanctum token (logout)
|   POST   /zoho/accounts                   — ZohoMailController    — gets the Zoho mailbox info
|   POST   /zoho/sendEmailwAttachments      — ZohoMailController    — sends an email with attachments
|   POST   /ocr/extract                     — OcrController         — runs OCR on an uploaded file
|   GET    /siem/stats                      — SiemController        — dashboard summary numbers
|   POST   /siem/events                     — SiemController        — manually log a SIEM event
|   PATCH  /siem/alerts/{id}/acknowledge    — SiemController        — marks an alert as acknowledged
|   PATCH  /siem/alerts/{id}/resolve        — SiemController        — marks an alert as resolved
|
*/

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

    // POST /auth/logout — AuthController — deletes the Sanctum token (logout)
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // POST /zoho/accounts — ZohoMailController — gets the Zoho mailbox info
    // User identity comes from the authenticated session, not a header.
    Route::post('/zoho/accounts',              [ZohoMailController::class, 'getAccounts'])
         ->middleware('siem.log');

    // POST /zoho/sendEmailwAttachments — ZohoMailController — sends the email with attachments
    // Rate-limited via the 'email-send' throttle config.
    Route::post('/zoho/sendEmailwAttachments', [ZohoMailController::class, 'sendEmailwAttachments'])
         ->middleware(['throttle:email-send', 'siem.log']);

    // POST /ocr/extract — OcrController — runs OCR on an uploaded file
    Route::post('/ocr/extract', [OcrController::class, 'extract'])
         ->middleware('siem.log');

    // SIEM dashboard API
    Route::prefix('siem')->group(function () {
        // GET  /siem/stats                      — SiemController — dashboard summary numbers
        Route::get   ('/stats',                       [SiemController::class, 'stats']);

        // POST /siem/events                     — SiemController — manually log a SIEM event
        Route::post  ('/events',                      [SiemController::class, 'logEvent']);

        // PATCH /siem/alerts/{id}/acknowledge   — SiemController — marks an alert as acknowledged
        Route::patch ('/alerts/{alertId}/acknowledge', [SiemController::class, 'acknowledge']);

        // PATCH /siem/alerts/{id}/resolve       — SiemController — marks an alert as resolved
        Route::patch ('/alerts/{alertId}/resolve',    [SiemController::class, 'resolve']);
    });
});
