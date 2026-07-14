<?php

use App\Models\FeedbackEntry;
use App\Models\User;
use App\Services\ModelCatalog;

test('the chat page lists claude plus the other providers, locked when keyless', function () {
    config([
        'services.anthropic.key' => 'test-key',
        'services.llm_providers.openai.key' => null,
        'services.llm_providers.deepseek.key' => 'sk-x',
    ]);

    $providers = collect(app(ModelCatalog::class)->providers());

    expect($providers->firstWhere('key', 'anthropic')['available'])->toBeTrue()
        ->and($providers->firstWhere('key', 'openai')['available'])->toBeFalse()
        ->and($providers->firstWhere('key', 'deepseek')['available'])->toBeTrue()
        // Claude is always listed first — it's the full-featured provider.
        ->and($providers->first()['key'])->toBe('anthropic');

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/chat')
        ->assertInertia(fn ($page) => $page
            ->has('providers')
            ->where('providers.0.key', 'anthropic'));
});

test('models from a keyless provider are rejected on send', function () {
    config([
        'services.anthropic.key' => 'test-key',
        'services.llm_providers.openai.key' => null,
    ]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/chat/stream', [
            'content' => 'Hello',
            'model' => 'gpt-5.5',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('model');
});

test('a configured provider model passes validation and hits its (failing) API without touching claude paths', function () {
    config([
        'services.anthropic.key' => 'test-key',
        'services.llm_providers.openai.key' => 'sk-test',
        'services.llm_providers.openai.base_url' => 'http://127.0.0.1:1', // unreachable on purpose
    ]);

    $user = User::factory()->create();

    // The provider call fails inside the stream (unreachable host) — the
    // point is validation passed and the turn persisted like any other.
    $this->actingAs($user)
        ->postJson('/chat/stream', [
            'content' => 'Hello',
            'model' => 'gpt-5.5',
        ])
        ->assertOk();

    expect($user->conversations()->count())->toBe(1);
});

test('a locked provider can be requested from the admin via api_request feedback', function () {
    $user = User::factory()->create(['name' => 'Maria']);

    $this->actingAs($user)
        ->post('/feedback', [
            'type' => 'api_request',
            'message' => "Please enable OpenAI for the chat — I'd like to use GPT-5.5 (gpt-5.5).",
        ])
        ->assertRedirect();

    expect(FeedbackEntry::sole()->type)->toBe('api_request');

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('feedback.entries.0.type', 'api_request')
            ->where('feedback.entries.0.user', 'Maria'));
});

test('per-provider model lists are env-overridable with hints', function () {
    config([
        'services.llm_providers.openai.models_env' => 'gpt-9:GPT-9|Future flagship,gpt-9-mini',
        'services.llm_providers.openai.key' => 'sk-x',
    ]);

    $openai = collect(app(ModelCatalog::class)->providers())->firstWhere('key', 'openai');

    expect($openai['models'])->toBe([
        ['value' => 'gpt-9', 'label' => 'GPT-9', 'hint' => 'Future flagship'],
        ['value' => 'gpt-9-mini', 'label' => 'gpt-9-mini', 'hint' => ''],
    ]);
});
