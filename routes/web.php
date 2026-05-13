<?php

use Illuminate\Support\Facades\Route;

Route::get('/test', function () {
    return response()->json([
        'message' => 'Laravel connected to Quasar',
        'status' => 'ok'
    ]);
});

Route::post('/send-email', function (\Illuminate\Http\Request $request) {
    return response()->json([
        'message' => 'Request received successfully',
        'data' => $request->all()
    ]);
});