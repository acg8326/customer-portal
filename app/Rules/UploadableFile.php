<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Accept an upload whose EXTENSION is allowed and whose sniffed content is
 * consistent with it.
 *
 * Laravel's `mimes:` rule sniffs the bytes and maps them back to an extension,
 * which is right for binaries and wrong for text: PHP's finfo reports a .txt of
 * C as `text/x-c`, of PHP as `text/x-php`, and of JSON as `application/json` —
 * none of which map to "txt". So `mimes:txt` rejected every code, log or config
 * paste, which is most of what anyone attaches a .txt for. Pasted text arrives
 * here as `pasted-text-N.txt`, so the whole feature failed on its main case.
 *
 * The relaxation is narrow and only for text extensions: the extension must be
 * on the allowlist, and the sniffed type must still be a text family. A binary
 * renamed to .txt is rejected as before, and non-text extensions keep the exact
 * strict `mimes:` behaviour.
 */
class UploadableFile implements ValidationRule
{
    /** Extensions whose content is text, whatever finfo decides to call it. */
    private const TEXT_EXTENSIONS = ['txt', 'md', 'csv'];

    /**
     * Sniffed types that are genuinely text. `text/*` covers text/plain and
     * every text/x-<language>; the rest are text formats finfo reports under
     * application/*.
     */
    private const TEXT_MIMES = [
        'application/json',
        'application/xml',
        'application/javascript',
        'application/x-httpd-php',
        'application/csv',
        'inode/x-empty',
    ];

    /**
     * @param  list<string>  $allowed  Allowed extensions (from ANTHROPIC_UPLOADS_MIMES).
     */
    public function __construct(private readonly array $allowed) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The :attribute failed to upload.')->translate();

            return;
        }

        $extension = mb_strtolower($value->getClientOriginalExtension());

        if (! in_array($extension, $this->allowed, true)) {
            $fail('The :attribute must be a file of type: '.implode(', ', $this->allowed).'.')->translate();

            return;
        }

        $mime = mb_strtolower((string) ($value->getMimeType() ?? ''));

        if (in_array($extension, self::TEXT_EXTENSIONS, true)) {
            if (! str_starts_with($mime, 'text/') && ! in_array($mime, self::TEXT_MIMES, true)) {
                $fail('The :attribute does not look like a text file.')->translate();
            }

            return;
        }

        // Non-text extensions keep the strict content check: the sniffed type
        // must map back to the extension the file claims.
        if ($value->guessExtension() !== null && $this->normalize($value->guessExtension()) !== $this->normalize($extension)) {
            $fail('The :attribute does not match its file type.')->translate();
        }
    }

    /**
     * Fold interchangeable extensions so a real JPEG named .jpg isn't rejected
     * because finfo prefers "jpeg".
     */
    private function normalize(string $extension): string
    {
        return match (mb_strtolower($extension)) {
            'jpeg' => 'jpg',
            default => mb_strtolower($extension),
        };
    }
}
