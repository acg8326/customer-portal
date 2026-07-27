<?php

use App\Models\Conversation;
use App\Models\RequestLog;
use App\Models\User;

// Seed one conversation with known token counts for the math assertions.
function seedCostConversation(User $user): Conversation
{
    $c = new Conversation;
    $c->user_id = $user->id;
    $c->title = 'Cost test';
    $c->model = 'claude-opus-4-8';
    $c->prompt_tokens = 1_000_000;      // uncached input
    $c->completion_tokens = 200_000;
    $c->cache_read_tokens = 3_000_000;  // served from cache
    $c->cache_write_tokens = 1_000_000; // written to cache
    $c->save();

    return $c;
}

test('super admin sees the cost & efficiency card with correct math', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    seedCostConversation($superAdmin);

    $this->actingAs($superAdmin)
        ->get('/analytics')
        ->assertInertia(fn ($page) => $page
            ->component('Analytics')
            ->has('costEfficiency', fn ($ce) => $ce
                // opus-4-8 at $5/$25 per MTok: 1M uncached (5.00) + 3M reads
                // at 0.1x (1.50) + 1M writes at 1.25x (6.25) + 0.2M output
                // (5.00) = 17.75
                ->where('total_usd', 17.75)
                // 3M reads / (3M + 1M writes + 1M uncached) = 0.6
                ->where('cache.hit_rate', 0.6)
                // 3M reads would have cost 15.00; paid 1.50 → saved 13.50
                ->where('cache.saved_usd', 13.5)
                ->where('cache.read_tokens', 3_000_000)
                ->where('models.0.model', 'claude-opus-4-8')
                ->where('models.0.provider', 'Anthropic (Claude)')
                // input column shows the whole prompt: uncached + read + write
                ->where('models.0.input_tokens', 5_000_000)
                ->where('models.0.cost', 17.75)
                ->etc()
            )
        );
});

test('admins and members do not get cost data', function () {
    $admin = User::factory()->admin()->create();
    seedCostConversation($admin);

    // Analytics (where cost data lives) is super-admin only — a plain admin
    // or member is blocked at the route, never sees the page at all.
    $this->actingAs($admin)->get('/analytics')->assertStatus(403);
    $this->actingAs(User::factory()->create())->get('/analytics')->assertStatus(403);
});

test('gateway traffic counts toward cost and cache efficiency', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    // Two Claude Code turns through the gateway: a big cached prefix each time.
    // Nothing in `conversations` — the gateway keeps no transcript.
    foreach (range(1, 2) as $i) {
        RequestLog::create([
            'user_id' => $superAdmin->id,
            'surface' => 'gateway',
            'model' => 'claude-opus-4-8',
            'input_tokens' => 500_000,
            'cache_read_tokens' => 1_000_000,
            'cache_write_tokens' => 500_000,
            'output_tokens' => 100_000,
            'status' => 200,
            'latency_ms' => 1_200,
        ]);
    }

    $this->actingAs($superAdmin)
        ->get('/analytics')
        ->assertInertia(fn ($page) => $page
            ->has('costEfficiency', fn ($ce) => $ce
                // 1M uncached (5.00) + 2M reads at 0.1x (1.00) + 1M writes at
                // 1.25x (6.25) + 0.2M output (5.00) = 17.25
                ->where('total_usd', 17.25)
                // 2M reads / (2M + 1M writes + 1M uncached) = 0.5
                ->where('cache.hit_rate', 0.5)
                ->where('cache.read_tokens', 2_000_000)
                ->where('cache.write_tokens', 1_000_000)
                // 2M reads would have cost 10.00; paid 1.00 → saved 9.00
                ->where('cache.saved_usd', 9)
                ->where('models.0.model', 'claude-opus-4-8')
                ->etc()
            )
        );
});

test('chat and gateway usage of one model land on the same row', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    seedCostConversation($superAdmin);

    RequestLog::create([
        'user_id' => $superAdmin->id,
        'surface' => 'gateway',
        'model' => 'claude-opus-4-8',
        'input_tokens' => 1_000_000,
        'cache_read_tokens' => 1_000_000,
        'cache_write_tokens' => 0,
        'output_tokens' => 0,
        'status' => 200,
        'latency_ms' => 900,
    ]);

    // A chat row is ALSO written to request_logs; reading it here as well would
    // double-count it against the conversation it belongs to.
    RequestLog::create([
        'user_id' => $superAdmin->id,
        'surface' => 'chat',
        'model' => 'claude-opus-4-8',
        'input_tokens' => 999_999_999,
        'cache_read_tokens' => 999_999_999,
        'cache_write_tokens' => 999_999_999,
        'output_tokens' => 999_999_999,
        'status' => 200,
        'latency_ms' => 100,
    ]);

    $this->actingAs($superAdmin)
        ->get('/analytics')
        ->assertInertia(fn ($page) => $page
            ->has('costEfficiency', fn ($ce) => $ce
                // One row, not two: the conversation's 17.75 plus the gateway's
                // 1M uncached (5.00) + 1M reads at 0.1x (0.50).
                ->has('models', 1)
                ->where('total_usd', 23.25)
                ->where('cache.read_tokens', 4_000_000)
                ->etc()
            )
        );
});

test('LLM_PRICES-style config override changes the estimate', function () {
    config(['services.llm_pricing.models' => ['claude-opus-4-8' => [10.0, 50.0]]]);

    $superAdmin = User::factory()->superAdmin()->create();
    seedCostConversation($superAdmin);

    $this->actingAs($superAdmin)
        ->get('/analytics')
        ->assertInertia(fn ($page) => $page
            // Doubled prices → doubled estimate.
            ->where('costEfficiency.total_usd', 35.5)
        );
});
