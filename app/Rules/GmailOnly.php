<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Accepts only @gmail.com addresses (blocks temp mail). Rejects "+" aliases.
// $currentEmail: on edit, an unchanged email is allowed so non-Gmail system accounts can still be saved.
class GmailOnly implements ValidationRule
{
    public function __construct(private ?string $currentEmail = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = strtolower(trim((string) $value));

        if ($this->currentEmail !== null && $email === strtolower(trim($this->currentEmail))) {
            return;
        }

        if (!preg_match('/^[a-z0-9._]+@gmail\.com$/', $email)) {
            $fail('Only Gmail addresses (@gmail.com) are allowed.');
        }
    }
}
