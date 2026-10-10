<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Taldres\Waitlist\Contracts\EmailNormalizer;

class DefaultEmailNormalizer implements EmailNormalizer
{
    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
