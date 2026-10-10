<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

/**
 * A project's frontend pages, as ProjectDefinition::urls() names them and
 * ProjectCatalog::urlPattern() is asked for them by value.
 */
enum Page: string
{
    case Confirm = 'confirm';
    case Unsubscribe = 'unsubscribe';
    case Manage = 'manage';
    case Confirmed = 'confirmed';
    case Expired = 'expired';
    case Invalid = 'invalid';
    case Unsubscribed = 'unsubscribed';
    case Erased = 'erased';

    /**
     * Where the links in mails point; the pattern needs a {token} placeholder.
     * The others are where a browser lands after posting to the package.
     */
    public function isMailLink(): bool
    {
        return match ($this) {
            self::Confirm, self::Unsubscribe, self::Manage => true,
            default => false,
        };
    }
}
