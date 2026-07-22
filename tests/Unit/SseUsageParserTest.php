<?php

use App\Services\SseUsageParser;

test('it sums input (incl. cache) and the final cumulative output', function () {
    $parser = new SseUsageParser;

    $parser->push("event: message_start\n");
    $parser->push('data: '.json_encode([
        'type' => 'message_start',
        'message' => ['usage' => [
            'input_tokens' => 100,
            'cache_read_input_tokens' => 20,
            'cache_creation_input_tokens' => 5,
            'output_tokens' => 1,
        ]],
    ])."\n\n");

    $parser->push('data: '.json_encode([
        'type' => 'message_delta',
        'usage' => ['output_tokens' => 30],
    ])."\n\n");
    // A later delta supersedes the earlier one (cumulative).
    $parser->push('data: '.json_encode([
        'type' => 'message_delta',
        'usage' => ['output_tokens' => 55],
    ])."\n\n");

    // 100 + 20 + 5 input, 55 output.
    expect($parser->total())->toBe(180);
});

test('it handles chunk boundaries splitting a line mid-JSON', function () {
    $parser = new SseUsageParser;

    $frame = 'data: '.json_encode([
        'type' => 'message_start',
        'message' => ['usage' => ['input_tokens' => 42, 'output_tokens' => 0]],
    ])."\n\n";

    // Feed it one byte at a time — the line must still parse once complete.
    foreach (str_split($frame) as $byte) {
        $parser->push($byte);
    }

    $parser->push('data: '.json_encode([
        'type' => 'message_delta',
        'usage' => ['output_tokens' => 8],
    ])."\n\n");

    expect($parser->total())->toBe(50);
});

test('non-usage frames and [DONE] are ignored', function () {
    $parser = new SseUsageParser;

    $parser->push("event: ping\ndata: {\"type\":\"ping\"}\n\n");
    $parser->push("data: [DONE]\n\n");

    expect($parser->total())->toBe(0);
});
