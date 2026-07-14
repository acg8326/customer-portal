<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Image generation + speech via OpenAI-compatible media APIs (Claude doesn't
 * do either). Config-driven (services.media.*): the key defaults to
 * OPENAI_API_KEY, so enabling OpenAI for chat also enables these.
 */
class OpenAiMedia
{
    public static function imageEnabled(): bool
    {
        return filled(config('services.media.image.key'));
    }

    public static function speechEnabled(): bool
    {
        return filled(config('services.media.speech.key'));
    }

    /**
     * Generate one image and return the binary PNG.
     */
    public function generateImage(string $prompt): string
    {
        $cfg = (array) config('services.media.image');

        $response = Http::withToken((string) $cfg['key'])
            ->timeout(180)
            ->post(rtrim((string) $cfg['base_url'], '/').'/images/generations', [
                'model' => (string) $cfg['model'],
                'prompt' => $prompt,
                'n' => 1,
                'size' => (string) $cfg['size'],
                'quality' => (string) $cfg['quality'],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Image API error ('.$response->status().'): '.mb_substr((string) $response->body(), 0, 300));
        }

        $b64 = $response->json('data.0.b64_json');

        if (! is_string($b64) || ($binary = base64_decode($b64, true)) === false || $binary === '') {
            throw new RuntimeException('Image API returned no image data.');
        }

        return $binary;
    }

    /**
     * Speech-to-text: transcribe a recorded audio file.
     */
    public function transcribe(string $absolutePath, string $filename): string
    {
        $cfg = (array) config('services.media.speech');

        $response = Http::withToken((string) $cfg['key'])
            ->timeout(120)
            ->attach('file', (string) file_get_contents($absolutePath), $filename)
            ->post(rtrim((string) $cfg['base_url'], '/').'/audio/transcriptions', [
                'model' => (string) $cfg['stt_model'],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Transcription error ('.$response->status().'): '.mb_substr((string) $response->body(), 0, 300));
        }

        return trim((string) $response->json('text'));
    }

    /**
     * Text-to-speech: returns binary MP3 audio for the given text.
     */
    public function speak(string $text): string
    {
        $cfg = (array) config('services.media.speech');

        $response = Http::withToken((string) $cfg['key'])
            ->timeout(120)
            ->post(rtrim((string) $cfg['base_url'], '/').'/audio/speech', [
                'model' => (string) $cfg['tts_model'],
                'voice' => (string) $cfg['tts_voice'],
                'input' => mb_substr($text, 0, (int) $cfg['max_tts_chars']),
                'response_format' => 'mp3',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Speech API error ('.$response->status().'): '.mb_substr((string) $response->body(), 0, 300));
        }

        return (string) $response->body();
    }
}
