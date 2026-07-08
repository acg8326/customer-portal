<?php

use App\Jobs\DispatchN8nEvent;
use App\Models\User;
use App\Services\N8nDispatcher;
use Illuminate\Support\Facades\Http;

test('the job posts the event to the connected webhook', function () {
    Http::fake(['*' => Http::response('ok', 200)]);

    $user = User::factory()->create();
    $user->integrations()->create([
        'provider' => 'n8n',
        'config' => ['webhook_url' => 'https://8.8.8.8/webhook/abc', 'secret' => 'shh'],
        'connected_at' => now(),
    ]);

    (new DispatchN8nEvent($user->id, 'chat.completed', ['conversation_id' => 7]))
        ->handle(app(N8nDispatcher::class));

    Http::assertSent(fn ($request) => $request->url() === 'https://8.8.8.8/webhook/abc'
        && $request['event'] === 'chat.completed'
        && $request['data']['conversation_id'] === 7);
});

test('the job does nothing when the user has no n8n connection', function () {
    Http::fake();

    $user = User::factory()->create();

    (new DispatchN8nEvent($user->id, 'chat.completed', []))
        ->handle(app(N8nDispatcher::class));

    Http::assertNothingSent();
});

test('the job throws on a 5xx so the queue retries', function () {
    Http::fake(['*' => Http::response('boom', 503)]);

    $user = User::factory()->create();
    $user->integrations()->create([
        'provider' => 'n8n',
        'config' => ['webhook_url' => 'https://8.8.8.8/webhook/abc'],
        'connected_at' => now(),
    ]);

    expect(fn () => (new DispatchN8nEvent($user->id, 'chat.completed', []))
        ->handle(app(N8nDispatcher::class)))
        ->toThrow(RuntimeException::class);
});

test('the job does not retry on a 4xx (n8n rejected it)', function () {
    Http::fake(['*' => Http::response('nope', 422)]);

    $user = User::factory()->create();
    $user->integrations()->create([
        'provider' => 'n8n',
        'config' => ['webhook_url' => 'https://8.8.8.8/webhook/abc'],
        'connected_at' => now(),
    ]);

    // Returns without throwing → no retry.
    (new DispatchN8nEvent($user->id, 'chat.completed', []))
        ->handle(app(N8nDispatcher::class));

    Http::assertSentCount(1);
});
