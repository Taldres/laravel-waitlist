<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Http\Controllers\Concerns\RedirectsToFrontend;
use Taldres\Waitlist\Http\Resources\WaitlistEntryResource;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\WaitlistManager;

class UnsubscribeController
{
    use RedirectsToFrontend;

    /**
     * Only the POST changes state: mail scanners follow every link, and a GET
     * that unsubscribes would take people off the list without a click. The
     * same goes for HEAD, which Laravel answers on GET routes.
     */
    public function show(Request $request, string $token, WaitlistManager $waitlist): WaitlistEntryResource|JsonResponse|RedirectResponse
    {
        if ($this->malformedPurpose($request)) {
            return $this->malformedPurposeResponse();
        }

        $entry = $waitlist->findByUnsubscribeToken($token);

        if ($entry === null) {
            return $this->redirectFor(Page::Invalid->value)
                ?? ApiError::InvalidToken->response();
        }

        return $this->redirectFor(Page::Unsubscribe->value, $entry, $token, $this->purpose($request)) ?? new WaitlistEntryResource($entry);
    }

    public function store(Request $request, string $token, WaitlistManager $waitlist): WaitlistEntryResource|JsonResponse|RedirectResponse
    {
        if ($this->malformedPurpose($request)) {
            return $this->malformedPurposeResponse();
        }

        $purpose = $this->purpose($request);

        // RFC 8058: a one-click request must not be answered with a redirect.
        $oneClick = $request->input('List-Unsubscribe') === 'One-Click';

        try {
            $entry = $purpose !== null
                ? $waitlist->withdrawConsent($token, $purpose, RequestContext::fromRequest($request))
                : $waitlist->unsubscribe($token, RequestContext::fromRequest($request));
        } catch (InvalidTokenException) {
            return ($oneClick ? null : $this->redirectFor(Page::Invalid->value))
                ?? ApiError::InvalidToken->response();
        }

        return ($oneClick ? null : $this->redirectFor(Page::Unsubscribed->value, $entry)) ?? new WaitlistEntryResource($entry);
    }

    protected function purpose(Request $request): ?string
    {
        $purpose = $request->query('purpose');

        return is_string($purpose) && $purpose !== '' ? $purpose : null;
    }

    /**
     * A malformed purpose must not widen a withdrawal into leaving the list.
     * Checked by presence: Laravel turns an empty one into null.
     */
    protected function malformedPurpose(Request $request): bool
    {
        return $this->purpose($request) === null && $request->query->has('purpose');
    }

    protected function malformedPurposeResponse(): JsonResponse
    {
        return new JsonResponse(['message' => 'The purpose must be a purpose name.'], 422);
    }
}
