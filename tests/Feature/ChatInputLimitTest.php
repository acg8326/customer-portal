<?php

use App\Http\Controllers\ChatController;
use App\Models\User;
use App\Services\OfficeTextExtractor;

test('the message cap is configurable, not hardcoded', function () {
    config(['services.anthropic.max_input_chars' => 500]);

    $this->actingAs(User::factory()->create())
        ->postJson('/chat/stream', [
            'content' => str_repeat('a', 501),
            'model' => array_key_first(config('services.anthropic.models')),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('content');
});

test('a message at the cap is accepted', function () {
    config([
        'services.anthropic.max_input_chars' => 500,
        // No key: the turn fails downstream, which is fine — we only care that
        // it got past validation.
        'services.anthropic.key' => null,
    ]);

    $this->actingAs(User::factory()->create())
        ->postJson('/chat/stream', [
            'content' => str_repeat('a', 500),
            'model' => array_key_first(config('services.anthropic.models')),
        ])
        ->assertStatus(503);
});

test('the send endpoint uses the same cap as the stream endpoint', function () {
    config(['services.anthropic.max_input_chars' => 500]);

    $this->actingAs(User::factory()->create())
        ->postJson('/chat/message', [
            'content' => str_repeat('a', 501),
            'model' => array_key_first(config('services.anthropic.models')),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('content');
});

test('the UI is told the cap and the paste threshold', function () {
    config([
        'services.anthropic.max_input_chars' => 8000,
        'services.anthropic.uploads.paste_to_file_chars' => 4000,
    ]);

    // The composer decides when to capture a paste, so it needs both numbers —
    // and they must come from the same config the validator reads, or the two
    // disagree about where the line is.
    $props = ChatController::uploadsProps();

    expect($props['maxChars'])->toBe(8000)
        ->and($props['pasteToFileChars'])->toBe(4000);
});

test('the paste-to-file threshold can be disabled', function () {
    config(['services.anthropic.uploads.paste_to_file_chars' => 0]);

    expect(ChatController::uploadsProps()['pasteToFileChars'])->toBe(0);
});

test('pasted text arrives as a normal txt attachment the model can read', function () {
    // The composer names captured pastes pasted-text-N.txt; that has to survive
    // the upload validator, or the whole feature 422s at the door.
    expect(config('services.anthropic.uploads.mimes'))->toContain('txt')
        ->and(app(OfficeTextExtractor::class)->supports('text/plain'))
        ->toBeTrue();
});
