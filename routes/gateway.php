<?php

use App\Http\Controllers\GatewayController;
use Illuminate\Support\Facades\Route;

/*
 * LLM gateway — an Anthropic-compatible surface at /llm/v1 that developers
 * point Claude Code at (ANTHROPIC_BASE_URL=<APP_URL>/llm). Token-authenticated
 * (no web session / CSRF); see the GatewayAuth middleware and config
 * services.anthropic.gateway.
 */
Route::post('messages', [GatewayController::class, 'messages'])->name('gateway.messages');
Route::post('messages/count_tokens', [GatewayController::class, 'countTokens'])->name('gateway.count-tokens');
