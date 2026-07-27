<?php

use App\Services\TokenUsage;

test('billable tokens weight cached prompt tokens to their real cost', function () {
    config(['usage.cache_read_weight' => 0.1, 'usage.cache_write_weight' => 1.25]);

    $usage = new TokenUsage(uncachedInput: 100, cacheRead: 10_000, cacheWrite: 1_000, output: 40);

    // 100 + 1,000 + 1,250 + 40
    expect($usage->billableTokens())->toBe(2_390)
        // ...against 11,140 tokens actually moved.
        ->and($usage->rawTotal())->toBe(11_140)
        ->and($usage->promptTokens())->toBe(11_100);
});

test('weight 1 makes a cache read cost the same as fresh input', function () {
    config(['usage.cache_read_weight' => 1.0, 'usage.cache_write_weight' => 1.0]);

    $usage = new TokenUsage(uncachedInput: 100, cacheRead: 10_000, cacheWrite: 1_000, output: 40);

    expect($usage->billableTokens())->toBe($usage->rawTotal());
});

test('weight 0 makes cache reads free', function () {
    config(['usage.cache_read_weight' => 0.0, 'usage.cache_write_weight' => 1.25]);

    $usage = new TokenUsage(cacheRead: 500_000, output: 10);

    expect($usage->billableTokens())->toBe(10);
});

test('it reads an Anthropic usage payload', function () {
    $usage = TokenUsage::fromArray([
        'input_tokens' => 7,
        'cache_read_input_tokens' => 8,
        'cache_creation_input_tokens' => 9,
        'output_tokens' => 10,
    ]);

    expect($usage->uncachedInput)->toBe(7)
        ->and($usage->cacheRead)->toBe(8)
        ->and($usage->cacheWrite)->toBe(9)
        ->and($usage->output)->toBe(10);
});

test('missing usage fields default to zero', function () {
    $usage = TokenUsage::fromArray([]);

    expect($usage->rawTotal())->toBe(0)
        ->and($usage->billableTokens())->toBe(0);
});

test('usage from several API rounds adds up per class', function () {
    $total = (new TokenUsage(uncachedInput: 10, cacheRead: 20, cacheWrite: 30, output: 40))
        ->plus(new TokenUsage(uncachedInput: 1, cacheRead: 2, cacheWrite: 3, output: 4));

    expect($total->uncachedInput)->toBe(11)
        ->and($total->cacheRead)->toBe(22)
        ->and($total->cacheWrite)->toBe(33)
        ->and($total->output)->toBe(44);
});
