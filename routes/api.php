<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ZohoMailController;
use App\Http\Controllers\OcrController;  

use App\Http\Controllers\SiemController;

Route::post('/zoho/accounts', [ZohoMailController::class, 'getAccounts'])->middleware('siem.log');
Route::post('/zoho/sendEmailwAttachments', [ZohoMailController::class, 'sendEmailwAttachments'])->middleware('siem.log');
Route::post('/ocr/extract', [OcrController::class, 'extract'])->middleware('siem.log');

Route::options('/zoho/sendEmailwAttachments', function() {
    return response()->json([], 200);
});
Route::options('/zoho/accounts', function() {
    return response()->json([], 200);
});
Route::options('/ocr/extract', function() {
    return response()->json([], 200);
});
Route::prefix('siem')->group(function () {
    Route::get  ('/stats',                      [SiemController::class, 'stats']);
    Route::post ('/events',                     [SiemController::class, 'logEvent']);
    Route::patch('/alerts/{alertId}/acknowledge',[SiemController::class, 'acknowledge']);
    Route::patch('/alerts/{alertId}/resolve',   [SiemController::class, 'resolve']);
});
