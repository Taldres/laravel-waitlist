<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Enums;

use Illuminate\Http\JsonResponse;

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
    case CountDisabled = 'count_disabled';
    case PositionDisabled = 'position_disabled';
    case SpamCheckFailed = 'spam_check_failed';

    public function status(): int
    {
        return match ($this) {
            self::ExpiredToken => 410,
            self::NotSubscribed, self::ListUnavailable => 409,
            self::SpamCheckFailed => 422,
            default => 404,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::InvalidToken => 'Invalid token.',
            self::ExpiredToken => 'This link has expired.',
            self::UnknownList => 'Not found.',
            self::NotSubscribed => 'Not subscribed.',
            self::ListUnavailable => 'This list is no longer available.',
            self::ManageLinksDisabled => 'This project offers no manage links.',
            self::CountDisabled => 'This list does not publish its count.',
            self::PositionDisabled => 'This list does not publish positions.',
            self::SpamCheckFailed => 'Spam check failed.',
        };
    }

    public function response(?string $message = null): JsonResponse
    {
        return new JsonResponse(['message' => $message ?? $this->message(), 'error' => $this->value], $this->status());
    }
}
