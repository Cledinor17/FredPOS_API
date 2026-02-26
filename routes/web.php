<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'app' => config('app.name'),
        'type' => 'backend-api',
        'status' => 'ok',
    ]);
});
