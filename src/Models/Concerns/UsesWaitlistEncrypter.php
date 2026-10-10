<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Models\Concerns;

use Illuminate\Contracts\Encryption\Encrypter;
use Taldres\Waitlist\WaitlistManager;

/**
 * Waitlist::encryptUsing() replaces the encrypter for these models only;
 * without it they follow Laravel, Model::encryptUsing() included. Public, as
 * the package calls it from outside and early Laravel 12 releases declare it
 * protected.
 */
trait UsesWaitlistEncrypter
{
    public static function currentEncrypter(): Encrypter
    {
        return app(WaitlistManager::class)->customEncrypter() ?? parent::currentEncrypter();
    }
}
