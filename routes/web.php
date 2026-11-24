<?php

use App\Http\Controllers\BoardController;
use App\Http\Middleware\UseMockUser;
use Illuminate\Support\Facades\Route;

Route::middleware(UseMockUser::class)->controller(BoardController::class)->group(function () {
    Route::get('/', 'index');
    Route::get('/board', 'data');
    Route::get('/history', 'history');
    Route::post('/switch-user/{user}', 'switchUser');

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
    Route::post('/cards/{card}/members/{user}', 'toggleMember');
    Route::post('/cards/{card}/comments', 'storeComment');
    Route::post('/cards/{card}/checklists', 'storeChecklist');

    Route::delete('/checklists/{checklist}', 'destroyChecklist');
    Route::post('/checklists/{checklist}/items', 'storeItem');
    Route::put('/items/{item}', 'updateItem');
    Route::delete('/items/{item}', 'destroyItem');
});
