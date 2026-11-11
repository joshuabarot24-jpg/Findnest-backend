<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

// Gmail, Yahoo, Hotmail and Outlook only (blocks temp mail). Rejects "+" aliases.
// $currentEmail: on edit, an unchanged email is allowed so the demo accounts can still be saved.
class AllowedEmailProvider implements ValidationRule
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

        if (!preg_match('/^[a-z0-9._-]+@(gmail|yahoo|hotmail|outlook)\.com$/', $email)) {
            $fail('Only Gmail, Yahoo, Hotmail, or Outlook addresses are allowed.');
        }
    }
}
