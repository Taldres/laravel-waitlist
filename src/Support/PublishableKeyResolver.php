<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Http\Request;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\ProjectResolver;
use Taldres\Waitlist\Definitions\ProjectDefinitions;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Throwable;

/**
 * For pages that call the API straight from the browser: the project is the
 * one whose publishableKey() the request sends in the X-Waitlist-Key header.
 * The key is public and authorizes nothing; the useWaitlist gate,
 * waitlist.authentication.required and the project's origins() still decide
 * who may call. A missing or unknown key answers 403, like a project that has
 * no waitlist.
 *
 * Keys come from the projects registered with Waitlist::define(). With a
 * ProjectCatalog of your own, extend this class and override project().
 */
class PublishableKeyResolver implements ProjectResolver
{
    public const string HEADER = 'X-Waitlist-Key';

    public function __construct(
        protected ProjectDefinitions $definitions,
        protected ProjectCatalog $catalog,
    ) {}

    public function resolve(Request $request): string
    {
        $key = $request->headers->get(static::HEADER);
        $project = is_string($key) && $key !== '' ? $this->project($key) : null;

        if ($project === null || ! in_array($project, $this->catalog->projects(), true)) {
            abort(403, 'Unknown waitlist key.');
        }

        return $project;
    }

    /**
     * The project the key belongs to, or null. Every key is compared, in
     * constant time, so two projects given the same key fail rather than one
     * of them winning by the order of the definitions. A broken definition
     * takes no part, so its twin wins until it is fixed; waitlist:check
     * reports both the broken definition and keys that projects share.
     *
     * @throws InvalidConfigurationException
     */
    protected function project(string $key): ?string
    {
        $found = null;

        foreach ($this->definitions->names() as $name) {
            $known = $this->knownKey($name);

            if ($known === null || ! hash_equals($known, $key)) {
                continue;
            }

            if ($found !== null) {
                throw new InvalidConfigurationException("The projects [{$found}] and [{$name}] have the same publishable key.");
            }

            $found = $name;
        }

        return $found;
    }

    /**
     * A definition that throws, whatever it throws, is reported and skipped:
     * one broken project must not stop the others' signups, and its own key
     * finds nothing. Resolved once per request, so reported once per request.
     */
    protected function knownKey(string $project): ?string
    {
        try {
            return $this->definitions->get($project)?->getPublishableKey();
        } catch (InvalidConfigurationException $exception) {
            app(ConfigFallback::class)->report($exception);
        } catch (Throwable $exception) {
            report($exception);
        }

        return null;
    }
}
