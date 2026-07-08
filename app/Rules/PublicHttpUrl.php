<?php

namespace App\Rules;

use App\Support\PublicUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a URL is a public http(s) endpoint — not a private, loopback,
 * or reserved address. Guards against SSRF on user-supplied webhook URLs.
 */
class PublicHttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PublicUrl::isPublic($value)) {
            $fail('The :attribute must be a public http(s) URL (private and internal addresses are not allowed).');
        }
    }
}
