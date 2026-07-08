<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\McpServerController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations');
    Route::post('integrations/n8n', [IntegrationController::class, 'connectN8n'])
        ->middleware('throttle:integrations')
        ->name('integrations.n8n.connect');
    Route::post('integrations/n8n/test', [IntegrationController::class, 'testN8n'])
        ->middleware('throttle:integration-test')
        ->name('integrations.n8n.test');
    Route::delete('integrations/{provider}', [IntegrationController::class, 'disconnect'])
        ->middleware('throttle:integrations')
        ->name('integrations.disconnect');

    // MCP servers (native tool connections).
    Route::post('integrations/mcp', [McpServerController::class, 'store'])
        ->middleware('throttle:integrations')
        ->name('integrations.mcp.store');
    Route::patch('integrations/mcp/{mcpServer}', [McpServerController::class, 'update'])
        ->middleware('throttle:integrations')
        ->name('integrations.mcp.update');
    Route::delete('integrations/mcp/{mcpServer}', [McpServerController::class, 'destroy'])
        ->middleware('throttle:integrations')
        ->name('integrations.mcp.destroy');

    Route::get('chat', [ChatController::class, 'index'])->name('chat');
    Route::get('chat/search', [ChatController::class, 'search'])
        ->middleware('throttle:search')
        ->name('chat.search');
    Route::post('chat/message', [ChatController::class, 'send'])
        ->middleware('throttle:chat')
        ->name('chat.message');
    Route::post('chat/stream', [ChatController::class, 'stream'])
        ->middleware('throttle:chat')
        ->name('chat.stream');
    Route::get('chat/conversations/{conversation}', [ChatController::class, 'show'])->name('chat.show');
    Route::delete('chat/conversations/{conversation}', [ChatController::class, 'destroy'])->name('chat.destroy');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
});

require __DIR__.'/settings.php';
