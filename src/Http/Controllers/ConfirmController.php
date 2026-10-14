<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Exceptions\ExpiredTokenException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Http\Controllers\Concerns\RedirectsToFrontend;
use Taldres\Waitlist\Http\Resources\WaitlistEntryResource;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\WaitlistManager;

class ConfirmController
{
    use RedirectsToFrontend;

    /**
     * Only the POST confirms: link scanners follow GET and probe with HEAD,
     * which Laravel answers on every GET route, so a GET only reports.
     */
    public function show(string $token, WaitlistManager $waitlist): WaitlistEntryResource|JsonResponse|RedirectResponse
    {
        $subscription = $waitlist->findByConfirmToken($token);
        $entry = $this->owner($token, $waitlist);

        if ($subscription === null || $entry === null || ! $subscription->isOpen()) {
            return $this->redirectFor(Page::Invalid->value, $entry)
                ?? ApiError::InvalidToken->response();
        }

        if ((! $subscription->isConfirmed() || $subscription->awaitsReconfirmation()) && $subscription->hasExpiredToken()) {
            return $this->redirectFor(Page::Expired->value, $entry)
                ?? ApiError::ExpiredToken->response('This confirmation link has expired.');
        }

        return $this->redirectFor(Page::Confirm->value, $entry, $token) ?? new WaitlistEntryResource($entry);
    }

    public function store(Request $request, string $token, WaitlistManager $waitlist): WaitlistEntryResource|JsonResponse|RedirectResponse
    {
        try {
            $entry = $waitlist->confirm($token, RequestContext::fromRequest($request));
        } catch (ExpiredTokenException) {
            return $this->redirectFor(Page::Expired->value, $this->owner($token, $waitlist))
                ?? ApiError::ExpiredToken->response('This confirmation link has expired.');
        } catch (InvalidTokenException) {
            return $this->redirectFor(Page::Invalid->value, $this->owner($token, $waitlist))
                ?? ApiError::InvalidToken->response();
        }

        return $this->redirectFor(Page::Confirmed->value, $entry) ?? new WaitlistEntryResource($entry);
    }

    protected function owner(string $token, WaitlistManager $waitlist): ?WaitlistEntry
    {
        /** @var WaitlistEntry|null */
        return $waitlist->findByConfirmToken($token)?->entry()->first();
    }
}
