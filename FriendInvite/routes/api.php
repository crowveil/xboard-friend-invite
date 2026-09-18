<?php

use Illuminate\Support\Facades\Route;
use Plugin\FriendInvite\Controllers\AdminController;
use Plugin\FriendInvite\Controllers\TelegramController;

Route::post('api/v1/friend-invite/telegram', [TelegramController::class, 'webhook']);
Route::prefix('api/v1/friend-invite/admin')->middleware('admin')->group(function () {
    Route::get('session', [AdminController::class, 'session']);
    Route::get('state', [AdminController::class, 'state']);
    Route::post('settings', [AdminController::class, 'save']);
    Route::post('create', [AdminController::class, 'create']);
    Route::post('revoke', [AdminController::class, 'revoke']);
    Route::post('renew', [AdminController::class, 'renew']);
    Route::post('close', [AdminController::class, 'close']);
    Route::post('debug', [AdminController::class, 'debug']);
    Route::get('export', [AdminController::class, 'export']);
    Route::post('telegram/connect', [AdminController::class, 'connect']);
    Route::post('telegram/pair', [AdminController::class, 'pair']);
    Route::post('telegram/unpair', [AdminController::class, 'unpair']);
    Route::post('telegram/disconnect', [AdminController::class, 'disconnect']);
});
