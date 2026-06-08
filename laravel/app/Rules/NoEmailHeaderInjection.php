<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoEmailHeaderInjection implements ValidationRule
{
    public static function isSafe(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/[\r\n\x00-\x1F\x7F]/', $value) !== 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isSafe($value)) {
            $fail('validation.email')->translate();
        }
    }
}
