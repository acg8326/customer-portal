<?php

namespace App\Services;

use App\Models\User;

/**
 * What this portal can do for a given user, and what it currently can't.
 *
 * The assistant used to be told only about the tools it HAD. Anything else was
 * invisible to it, so a question needing an unconnected source came back as
 * "I don't know" or a guess — the user was never told that the capability
 * exists and is one click away. This inventory is the missing half: the
 * sources they could connect, and the abilities switched off for this turn
 * with the reason and the fix.
 *
 * Everything here is derived from real config and real connection rows, never
 * a hardcoded list, so it can't drift from what the Integrations page offers.
 */
final class CapabilityInventory
{
    public function __construct(
        private readonly ComposioService $composio,
        private readonly NetsuiteService $netsuite,
    ) {}

    /**
     * Data sources this portal is configured to offer that the user has not
     * connected yet. Only genuinely connectable ones: a toolkit with no auth
     * config never appears (offering it would send them to a dead button).
     *
     * @return list<array{name: string, key: string, pending: bool}>
     */
    public function connectable(User $user): array
    {
        $out = [];

        if ($this->composio->enabled()) {
            $statuses = $user->composioConnections()->pluck('status', 'toolkit');

            foreach ($this->composio->toolkits() as $key => $meta) {
                $status = (string) ($statuses[$key] ?? '');

                if ($status === 'active') {
                    continue;
                }

                $out[] = [
                    'name' => $meta['name'],
                    'key' => (string) $key,
                    // Started but never finished — worth saying so, since
                    // "connect it" is the wrong advice for a half-done link.
                    'pending' => $status !== '',
                ];
            }
        }

        if ($this->netsuite->enabled() && ! $this->netsuite->enabledFor($user)) {
            $out[] = [
                'name' => 'NetSuite',
                'key' => 'netsuite',
                // A row that exists but isn't active means they started the
                // connection and it never completed — telling them to "connect
                // NetSuite" would be wrong advice.
                'pending' => $user->netsuiteConnections()->exists(),
            ];
        }

        return $out;
    }

    /**
     * Abilities that exist in this portal but are unavailable for THIS turn,
     * each with the reason and what the user can do about it. The reason
     * matters more than the fact: "I can't see images" is useless, "attachments
     * are off in private chats — turn Private off and resend" is actionable.
     *
     * @return list<array{name: string, why: string}>
     */
    public function offThisTurn(bool $claudeModel, bool $privateMode, bool $webSearchOn): array
    {
        $out = [];

        if (! $claudeModel) {
            $out[] = [
                'name' => 'Attachments, connected tools, web search and extended thinking',
                'why' => 'the selected model is not a Claude model, and these are Claude-only'
                    .' here. Switching the model picker back to a Claude model enables them.',
            ];

            // Everything below is a subset of the above on this turn.
            return $out;
        }

        if ($privateMode) {
            $out[] = [
                'name' => 'Attachments and connected app tools',
                'why' => 'this is a Private chat, which stores nothing — turning Private'
                    .' off and resending makes them available.',
            ];
        }

        if (! $webSearchOn) {
            $out[] = [
                'name' => 'Web search and page fetching',
                'why' => 'the Web search toggle (the globe in the composer) is off for this'
                    .' message.',
            ];
        }

        if (! (bool) config('services.anthropic.uploads.enabled', true)) {
            $out[] = [
                'name' => 'File and image uploads',
                'why' => 'uploads are disabled for this portal — an admin controls this.',
            ];
        }

        return $out;
    }

    /**
     * Abilities the user has that live OUTSIDE the chat request, so the model
     * would otherwise never learn they exist and would wrongly refuse.
     *
     * @return list<string>
     */
    public function outOfBand(): array
    {
        $out = [];

        if (OpenAiMedia::imageEnabled()) {
            $out[] = 'Generate images from a description — the user clicks the image'
                .' button in the composer, which routes the prompt to '
                .(string) config('services.media.image.provider_name', 'an image model')
                .'. You cannot call it yourself: tell them to use that button rather'
                .' than saying you are unable to make images.';
        }

        if (OpenAiMedia::speechEnabled()) {
            $out[] = 'Dictate a message with the microphone button, and hear any reply'
                .' read aloud with its speaker button.';
        }

        if ((bool) config('services.anthropic.uploads.enabled', true)) {
            $out[] = 'Attach images, PDFs and Office files with the paperclip, or paste'
                .' an image straight into the composer.';
        }

        return $out;
    }
}
