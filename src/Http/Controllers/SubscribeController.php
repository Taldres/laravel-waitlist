<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Enums\ApiError;
use Taldres\Waitlist\Exceptions\InvalidEmailException;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Exceptions\WordingConflictException;
use Taldres\Waitlist\Http\Requests\SubscribeRequest;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\WaitlistManager;

class SubscribeController
{
    /**
     * Responds 202 with an identical body for new and existing entries, so
     * addresses cannot be enumerated. Lists and wording are public (GET
     * /purposes), so a bad list or purpose is named in the 422.
     */
    public function __invoke(SubscribeRequest $request, WaitlistManager $waitlist, SpamProtector $protector): JsonResponse
    {
        $project = $request->project();

        if (! $protector->passes($request)) {
            return new JsonResponse(['message' => 'Spam check failed.', 'error' => ApiError::SpamCheckFailed->value], 422);
        }

        $input = $request->safe();

        /** @var array<string, string|array{version: string, locale?: string|null, hash?: string, text?: string}> $purposes */
        $purposes = $input->array('purposes');

        /** @var array<string, mixed> $metadata */
        $metadata = $input->array('metadata');

        try {
            $waitlist->project($project)->for($request->effectiveList())->add(
                email: $input->string('email')->value(),
                purposes: $purposes,
                metadata: $metadata,
                context: RequestContext::fromRequest($request),
            );
        } catch (UnknownWaitlistException) {
            throw $request->reject('list', 'The selected list is not available.');
        } catch (UnknownPurposeException|WordingConflictException|MissingConsentException $exception) {
            throw $request->reject('purposes', $exception->getMessage());
        } catch (InvalidEmailException) {
            throw $request->reject('email', 'The email field must be a valid email address.');
        }

        return new JsonResponse(['message' => 'Subscribed.'], 202);
    }
}
