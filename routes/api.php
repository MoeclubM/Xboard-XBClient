<?php

use Illuminate\Support\Facades\Route;
use Plugin\Xbclient\Controllers\RewardController;

Route::prefix('api/v1/admob')->group(function () {
    Route::prefix('user')->middleware('user')->group(function () {
        Route::get('/config', [RewardController::class, 'config']);
        Route::post('/plan-payment', [RewardController::class, 'planPayment']);
    });

    Route::prefix('web')->group(function () {
        Route::get('/plan-payment', [RewardController::class, 'planPaymentBridge']);
    });

    Route::prefix('google')->group(function () {
        Route::get('/reward/ssv', [RewardController::class, 'ssv']);
    });
});
