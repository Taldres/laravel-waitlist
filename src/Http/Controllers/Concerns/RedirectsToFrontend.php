<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Taldres\Waitlist\Config\ConfigFallback;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Support\UnsubscribeToken;

trait RedirectsToFrontend
{
    /**
     * Null falls back to the JSON response: redirects are for browsers
     * following mail links, so JSON clients never get one. Without an entry,
     * as for an unknown token, the default project's pages apply.
     *
     * A catalog that cannot be read answers in JSON too, reported: the step,
     * such as leaving, has often happened by now, and must not end in a 500.
     *
     * @param  string  $outcome  a Page value
     */
    protected function redirectFor(string $outcome, ?WaitlistEntry $entry = null, ?string $token = null, ?string $purpose = null): ?RedirectResponse
    {
        if (request()->wantsJson()) {
            return null;
        }

        $url = ConfigFallback::read(
            fn (): ?string => app(ProjectCatalog::class)->urlPattern($entry->project ?? WaitlistEntry::DEFAULT_PROJECT, $outcome),
            fallback: null,
        );

        if ($url === null) {
            return null;
        }

        $url = str_replace('{token}', $token ?? '', $url);

        return redirect()->away($purpose !== null ? UnsubscribeToken::withPurpose($url, $purpose) : $url);
    }
}
