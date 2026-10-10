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
     * Only POST changes state: mail scanners follow every link, and a GET that
     * unsubscribes would take people off the list without a click. The same
     * goes for HEAD, which Laravel answers on GET routes.
     */
    public function __invoke(Request $request, string $token, WaitlistManager $waitlist): WaitlistEntryResource|JsonResponse|RedirectResponse
    {
        $purpose = $request->query('purpose');
        $purpose = is_string($purpose) && $purpose !== '' ? $purpose : null;

        // A malformed purpose must not widen a withdrawal into leaving the list.
        // Checked by presence: Laravel turns an empty one into null.
        if ($purpose === null && $request->query->has('purpose')) {
            return new JsonResponse(['message' => 'The purpose must be a purpose name.'], 422);
        }

        if (! $request->isMethod('POST')) {
            $entry = $waitlist->findByUnsubscribeToken($token);

            if ($entry === null) {
                return $this->redirectFor(Page::Invalid->value)
                    ?? new JsonResponse(['message' => 'Invalid token.', 'error' => ApiError::InvalidToken->value], 404);
            }

            return $this->redirectFor(Page::Unsubscribe->value, $entry, $token, $purpose) ?? new WaitlistEntryResource($entry);
        }

        // RFC 8058: a one-click request must not be answered with a redirect.
        $oneClick = $request->input('List-Unsubscribe') === 'One-Click';

        try {
            $entry = $purpose !== null
                ? $waitlist->withdrawConsent($token, $purpose, RequestContext::fromRequest($request))
                : $waitlist->unsubscribe($token, RequestContext::fromRequest($request));
        } catch (InvalidTokenException) {
            return ($oneClick ? null : $this->redirectFor(Page::Invalid->value))
                ?? new JsonResponse(['message' => 'Invalid token.', 'error' => ApiError::InvalidToken->value], 404);
        }

        return ($oneClick ? null : $this->redirectFor(Page::Unsubscribed->value, $entry)) ?? new WaitlistEntryResource($entry);
    }
}
