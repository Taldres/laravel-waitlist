<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Support;

use Illuminate\Support\Facades\URL;
use Taldres\Waitlist\Contracts\ConfirmationUrlGenerator;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\ConfigKey;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Models\WaitlistEntry;

class DefaultConfirmationUrlGenerator implements ConfirmationUrlGenerator
{
    public function confirmUrl(WaitlistEntry $entry, string $plainToken): ?string
    {
        return $this->resolve($entry, Page::Confirm->value, $plainToken);
    }

    public function unsubscribeUrl(WaitlistEntry $entry, string $plainToken): ?string
    {
        return $this->resolve($entry, Page::Unsubscribe->value, $plainToken);
    }

    public function manageUrl(WaitlistEntry $entry, string $plainToken): ?string
    {
        return $this->resolve($entry, Page::Manage->value, $plainToken);
    }

    public function __construct(
        protected ProjectCatalog $catalog,
    ) {}

    /**
     * Only the project's own pattern is used, never another project's: the
     * package routes serve every project.
     */
    private function resolve(WaitlistEntry $entry, string $action, string $plainToken): ?string
    {
        $pattern = $this->catalog->urlPattern($entry->project, $action);

        if ($pattern !== null) {
            return str_replace('{token}', $plainToken, $pattern);
        }

        if (Setting::enabled(ConfigKey::RoutesEnabled->value)) {
            return self::packageUrl($action, ['token' => $plainToken]);
        }

        return null;
    }

    /**
     * Rooted at APP_URL rather than the request: a link built from the Host
     * header of a forged signup would carry the token to whoever sent it. The
     * package routes are named after the Page they stand in for.
     *
     * @param  string  $action  Page::Confirm, Unsubscribe or Manage, by value
     * @param  array<string, string>  $parameters
     */
    public static function packageUrl(string $action, array $parameters): string
    {
        $root = config('app.url');
        $name = Setting::value(ConfigKey::RoutesName->value);
        $path = URL::route((is_string($name) ? $name : '').$action, $parameters, absolute: false);

        return is_string($root) && $root !== '' ? rtrim($root, '/').$path : URL::to($path);
    }
}
