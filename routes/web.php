<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\ChatExportController;
use App\Http\Controllers\ComposioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\McpServerController;
use App\Http\Controllers\NetsuiteController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::patch('dashboard/usage-settings', [DashboardController::class, 'updateUsageSettings'])
        ->name('dashboard.usage-settings');

    // User management — admins only (no public registration).
    Route::middleware('admin')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users');
        Route::post('users', [UserController::class, 'store'])
            ->middleware('throttle:integrations')
            ->name('users.store');
        Route::delete('users/{user}', [UserController::class, 'destroy'])
            ->middleware('throttle:integrations')
            ->name('users.destroy');
    });

    Route::get('integrations', [IntegrationController::class, 'index'])->name('integrations');
    Route::post('integrations/webhook/{provider}', [IntegrationController::class, 'connectWebhook'])
        ->middleware('throttle:integrations')
        ->name('integrations.webhook.connect');
    Route::post('integrations/webhook/{provider}/test', [IntegrationController::class, 'testWebhook'])
        ->middleware('throttle:integration-test')
        ->name('integrations.webhook.test');

    // NetSuite — native Token-Based Auth (TBA) connection, not Composio.
    // Registered before the generic {provider} disconnect so DELETE
    // /integrations/netsuite hits the NetSuite controller, not the webhook one.
    Route::post('integrations/netsuite/connect', [NetsuiteController::class, 'connect'])
        ->middleware('throttle:integrations')
        ->name('integrations.netsuite.connect');
    Route::post('integrations/netsuite/test', [NetsuiteController::class, 'test'])
        ->middleware('throttle:integration-test')
        ->name('integrations.netsuite.test');
    Route::get('integrations/netsuite/callback', [NetsuiteController::class, 'callback'])
        ->name('integrations.netsuite.callback');
    Route::delete('integrations/netsuite', [NetsuiteController::class, 'disconnect'])
        ->middleware('throttle:integrations')
        ->name('integrations.netsuite.disconnect');

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

    // MCP OAuth: one-click connect (redirects to the server's auth page) + callback.
    Route::get('integrations/mcp/oauth/callback', [McpServerController::class, 'oauthCallback'])
        ->name('integrations.mcp.oauth.callback');
    Route::get('integrations/mcp/catalog/{key}/connect', [McpServerController::class, 'catalogConnect'])
        ->middleware('throttle:integrations')
        ->name('integrations.mcp.catalog.connect');
    Route::get('integrations/mcp/{mcpServer}/oauth/connect', [McpServerController::class, 'oauthConnect'])
        ->middleware('throttle:integrations')
        ->name('integrations.mcp.oauth.connect');

    // Composio — per-user tool connections (Slack, …) via a hosted gateway.
    Route::get('integrations/composio/{toolkit}/connect', [ComposioController::class, 'connect'])
        ->middleware('throttle:integrations')
        ->name('integrations.composio.connect');
    // Bring-your-own-OAuth toolkits (e.g. NetSuite) submit credentials here.
    Route::post('integrations/composio/{toolkit}/connect', [ComposioController::class, 'connectWithCredentials'])
        ->middleware('throttle:integrations')
        ->name('integrations.composio.connect.credentials');
    Route::get('integrations/composio/{toolkit}/callback', [ComposioController::class, 'callback'])
        ->name('integrations.composio.callback');
    Route::delete('integrations/composio/{toolkit}', [ComposioController::class, 'disconnect'])
        ->middleware('throttle:integrations')
        ->name('integrations.composio.disconnect');

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
    Route::post('chat/export/pdf', [ChatExportController::class, 'pdf'])
        ->middleware('throttle:chat')
        ->name('chat.export.pdf');
    Route::post('chat/export/docx', [ChatExportController::class, 'docx'])
        ->middleware('throttle:chat')
        ->name('chat.export.docx');
    Route::post('chat/export/sheet', [ChatExportController::class, 'sheet'])
        ->middleware('throttle:chat')
        ->name('chat.export.sheet');
    Route::get('chat/conversations/{conversation}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('chat/messages/{message}/feedback', [ChatController::class, 'feedback'])
        ->middleware('throttle:search')
        ->name('chat.feedback');
    Route::post('chat/conversations/{conversation}/star', [ChatController::class, 'star'])
        ->middleware('throttle:search')
        ->name('chat.star');
    Route::post('chat/conversations/{conversation}/compact', [ChatController::class, 'compact'])
        ->middleware('throttle:chat')
        ->name('chat.compact');
    Route::post('chat/conversations/{conversation}/tools/decision', [ChatController::class, 'toolDecision'])
        ->middleware('throttle:chat')
        ->name('chat.tools.decision');
    Route::delete('chat/conversations/{conversation}', [ChatController::class, 'destroy'])->name('chat.destroy');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
});

require __DIR__.'/settings.php';
