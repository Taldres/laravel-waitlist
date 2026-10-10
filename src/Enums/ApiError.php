<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

/**
 * The `error` of a refusal the package's routes answer themselves, so a client
 * can tell it from one that came from elsewhere: a 404 without it means no
 * such route, or the useWaitlist gate.
 */
enum ApiError: string
{
    case InvalidToken = 'invalid_token';
    case ExpiredToken = 'expired_token';
    case UnknownList = 'unknown_list';
    case NotSubscribed = 'not_subscribed';
    case ListUnavailable = 'list_unavailable';
    case ManageLinksDisabled = 'manage_links_disabled';
    case SpamCheckFailed = 'spam_check_failed';
}
