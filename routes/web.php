<?php

use App\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
    Route::get('chat', [ChatController::class, 'index'])->name('chat');
    Route::post('chat/message', [ChatController::class, 'send'])->name('chat.message');
});

require __DIR__.'/settings.php';
