<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoardController;
use App\Http\Middleware\RequirePasswordChange;
use Illuminate\Support\Facades\Route;

Route::controller(AuthController::class)->group(function () {
    Route::get('/login', 'showLogin')->middleware('guest')->name('login');
    Route::post('/login', 'login')->middleware('guest');

    Route::middleware(['auth', 'auth.session'])->group(function () {
        Route::post('/logout', 'logout');
        Route::get('/password', 'showPassword')->name('password');
        Route::post('/password', 'updatePassword')->middleware('throttle:10,1');
    });
});

Route::middleware(['auth', 'auth.session', RequirePasswordChange::class])->controller(BoardController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/board', 'data');
    Route::get('/history', 'history');

    Route::post('/lists', 'storeList');
    Route::put('/lists/{list}', 'updateList');
    Route::delete('/lists/{list}', 'destroyList');
    Route::post('/lists/{list}/move', 'moveList');
    Route::post('/lists/{list}/cards', 'storeCard');

    Route::put('/cards/{card}', 'updateCard');
    Route::delete('/cards/{card}', 'destroyCard');
    Route::post('/cards/{card}/restore', 'restoreCard')->withTrashed();
    Route::get('/cards/{card}/history', 'history')->withTrashed();
    Route::post('/cards/{card}/move', 'moveCard');
    Route::post('/cards/{card}/complete', 'completeCard');
    Route::put('/cards/{card}/people/{user}', 'setRole');
    Route::post('/cards/{card}/comments', 'storeComment');
    Route::post('/cards/{card}/checklists', 'storeChecklist');

    Route::delete('/checklists/{checklist}', 'destroyChecklist');
    Route::post('/checklists/{checklist}/items', 'storeItem');
    Route::put('/items/{item}', 'updateItem');
    Route::delete('/items/{item}', 'destroyItem');
});
