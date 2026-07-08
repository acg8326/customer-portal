<?php

use App\Models\User;

test('the stream endpoint blocks a user who is over budget (before streaming)', function () {
    config(['services.anthropic.key' => 'test-key', 'usage.token_limit' => 1000]);

    $user = User::factory()->create();
    $user->token_budget_used = 1000;
    $user->token_budget_started_at = now();
    $user->save();

    $this->actingAs($user)
        ->postJson('/chat/stream', [
            'content' => 'Hello',
            'model' => array_key_first(config('services.anthropic.models')),
        ])
        ->assertStatus(429)
        ->assertJsonStructure(['message', 'usage_limit']);
});

test('the stream endpoint reports when the chat is not configured', function () {
    config(['services.anthropic.key' => null]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/chat/stream', [
            'content' => 'Hello',
            'model' => array_key_first(config('services.anthropic.models')),
        ])
        ->assertStatus(503);
});

test('the stream endpoint validates the model', function () {
    config(['services.anthropic.key' => 'test-key']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/chat/stream', [
            'content' => 'Hello',
            'model' => 'not-a-real-model',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('model');
});

test('the stream endpoint requires authentication', function () {
    $this->postJson('/chat/stream', ['content' => 'hi', 'model' => 'x'])
        ->assertStatus(401);
});
