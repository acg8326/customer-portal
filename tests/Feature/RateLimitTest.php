<?php

use App\Models\User;

test('chat search is throttled with a friendly message once the limit is hit', function () {
    config(['ratelimits.search' => 2]);

    $user = User::factory()->create();

    // Under the limit — allowed.
    $this->actingAs($user)->getJson('/chat/search?q=hi')->assertOk();
    $this->actingAs($user)->getJson('/chat/search?q=hi')->assertOk();

    // Over the limit — blocked with a clear message and a Retry-After header.
    $res = $this->actingAs($user)->getJson('/chat/search?q=hi');
    $res->assertStatus(429)
        ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_contains($m, 'bit fast'))->etc());

    expect($res->headers->has('Retry-After'))->toBeTrue();
});

test('the throttle is per-user, so one user cannot exhaust another', function () {
    config(['ratelimits.search' => 1]);

    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->actingAs($a)->getJson('/chat/search?q=x')->assertOk();
    $this->actingAs($a)->getJson('/chat/search?q=x')->assertStatus(429);

    // A different user still has their full allowance.
    $this->actingAs($b)->getJson('/chat/search?q=x')->assertOk();
});

test('the n8n test endpoint is throttled', function () {
    config(['ratelimits.integration_test' => 1]);

    $user = User::factory()->create();

    $this->actingAs($user)->post('/integrations/n8n/test')->assertRedirect();
    $this->actingAs($user)->post('/integrations/n8n/test')->assertStatus(429);
});
