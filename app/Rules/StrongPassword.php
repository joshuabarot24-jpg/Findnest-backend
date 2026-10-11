<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Password standard: 8 to 50 characters, at least one uppercase letter,
// one lowercase letter, one number and one special character.
class StrongPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $p = (string) $value;

        if (strlen($p) < 8 || strlen($p) > 50) {
            $fail('Password must be 8 to 50 characters long.');
            return;
        }
        if (!preg_match('/[A-Z]/', $p)) {
            $fail('Password must include at least one uppercase letter.');
            return;
        }
        if (!preg_match('/[a-z]/', $p)) {
            $fail('Password must include at least one lowercase letter.');
            return;
        }
        if (!preg_match('/[0-9]/', $p)) {
            $fail('Password must include at least one number.');
            return;
        }
        if (!preg_match('/[^A-Za-z0-9]/', $p)) {
            $fail('Password must include at least one special character (for example ! @ # $ %).');
        }
    }
}
