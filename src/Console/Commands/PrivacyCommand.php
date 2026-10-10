<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use ReflectionFunction;
use Symfony\Component\Console\Output\OutputInterface;
use Taldres\Waitlist\Actions\IssueManageLink;
use Taldres\Waitlist\Actions\ResendConfirmation;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Events\ConsentGranted;
use Taldres\Waitlist\Events\ConsentWithdrawn;
use Taldres\Waitlist\Events\EntryConfirmed;
use Taldres\Waitlist\Events\EntryForgotten;
use Taldres\Waitlist\Events\EntrySubscribed;
use Taldres\Waitlist\Events\EntryUnsubscribed;
use Taldres\Waitlist\Events\ManageLinkRequested;
use Taldres\Waitlist\Events\SubscriptionExpired;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Exceptions\MissingWordingException;
use Taldres\Waitlist\Support\PurposeRegistry;
use Taldres\Waitlist\WaitlistManager;
use Taldres\Waitlist\WaitlistServiceProvider;

class PrivacyCommand extends Command
{
    protected $signature = 'waitlist:privacy
        {--project= : Only describe this project}';

    protected $description = 'Describe what the waitlist stores, why, for how long, and where it flows (input for your record of processing, GDPR Art. 30)';

    protected const array EVENTS = [
        EntrySubscribed::class,
        EntryConfirmed::class,
        EntryUnsubscribed::class,
        SubscriptionExpired::class,
        EntryForgotten::class,
        ConsentGranted::class,
        ConsentWithdrawn::class,
        ManageLinkRequested::class,
    ];

    public function handle(PurposeRegistry $registry, ProjectCatalog $catalog): int
    {
        $project = $this->option('project');
        $projects = is_string($project) && $project !== '' ? [$project] : $catalog->projects();

        $lines = [
            '# Waitlist processing record',
            '',
            'Generated from the configuration and the project definitions on '.now()->toDateString().'. Input for your record of processing activities (GDPR Art. 30); review and complete it, it is not legal advice.',
            '',
            'The application operator is responsible for lawful processing and secure deployment. This output is a partial technical inventory, not a compliance assessment or a complete processing record.',
            'The software is provided under the MIT License, including its warranty and liability disclaimer, subject to applicable mandatory law. No GDPR compliance warranty is provided; see https://github.com/Taldres/laravel-waitlist/blob/main/docs/responsibility.md',
            '',
            ...$this->data($catalog, $projects),
            ...$this->purposes($registry, $catalog, $projects),
            ...$this->retention($catalog, $projects),
            ...$this->measures($catalog, $projects),
            ...$this->recipients(),
        ];

        // Raw: wording is stored text, and the console would read tags in it.
        $this->output->writeln(implode(PHP_EOL, $lines), OutputInterface::OUTPUT_RAW);

        // On stderr, so redirecting the record into a file leaves them out of it.
        foreach ($this->warnings($catalog, $projects) as $warning) {
            $this->output->getErrorStyle()->writeln("Warning: {$warning}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $projects
     * @return list<string>
     */
    protected function data(ProjectCatalog $catalog, array $projects): array
    {
        $protection = 'Encrypted with '.$this->encryptedWith();

        $rows = [
            "| Email address | waitlist_entries.email | {$protection}; looked up by a keyed hash |",
            '| Consent per purpose: wording, version, granted and withdrawn at | waitlist_consents | Wording frozen at the moment of consent |',
            '| Lifecycle log: step, purpose, date, reference of the mail your listener sent | waitlist_activity | Stripped to project, list, step, purpose, the status a departure left and date on erasure, so counts survive; one row per step, so not anonymous in a small list |',
        ];

        foreach ($projects as $project) {
            foreach ($catalog->lists($project) as $list) {
                $fields = array_keys($catalog->fields($project, $list));

                if ($fields !== []) {
                    $label = $list === '*' ? 'any other list' : "list {$list}";
                    $rows[] = "| Metadata ({$project}, {$label}): ".implode(', ', $fields)." | waitlist_entries.metadata | {$protection} |";
                }
            }
        }

        $privacy = WaitlistConfig::privacy();

        foreach (['IP address' => $privacy->storeIp, 'User agent' => $privacy->storeUserAgent] as $label => $stored) {
            if ($stored) {
                $rows[] = "| {$label} | waitlist_activity | {$protection} |";
            }
        }

        return [
            '## Personal data',
            '',
            '| Data | Stored in | Protection |',
            '| --- | --- | --- |',
            ...$rows,
            '',
        ];
    }

    /**
     * @param  list<string>  $projects
     * @return list<string>
     */
    protected function purposes(PurposeRegistry $registry, ProjectCatalog $catalog, array $projects): array
    {
        $lines = [
            '## Purposes',
            '',
            '| Project | List | Purpose | Required | Current version | Wording | Double opt-in |',
            '| --- | --- | --- | --- | --- | --- | --- |',
        ];

        foreach ($projects as $project) {
            foreach ($catalog->lists($project) as $list) {
                $label = $list === '*' ? 'any other list' : $list;

                // Typically a stored catalog before its first waitlist:wording;
                // report it rather than fail.
                try {
                    $policy = $registry->policy($project, $list);
                } catch (MissingWordingException $exception) {
                    $lines[] = sprintf('| %s | %s | — | — | — | %s | — |', $project, $label, $exception->getMessage());

                    continue;
                }

                $current = $registry->current($policy);

                foreach ($current as $wording) {
                    $texts = $registry->versions($project, $wording->purpose)[$wording->version];

                    foreach (is_array($texts) ? $texts : ['' => $texts] as $locale => $text) {
                        $lines[] = sprintf(
                            '| %s | %s | %s | %s | %s | %s | %s |',
                            $project,
                            $label,
                            $wording->purpose,
                            $wording->required ? 'yes' : 'no',
                            $locale === '' ? $wording->version : "{$wording->version} ({$locale})",
                            str_replace(["\r\n", "\n", "\r", '|'], [' ', ' ', ' ', '\|'], $text),
                            $policy->doubleOptIn ? 'yes' : 'no',
                        );
                    }
                }

                $sent = array_column($current, 'purpose');

                foreach (array_diff($policy->purposes(), $sent) as $purpose) {
                    $lines[] = sprintf(
                        '| %s | %s | %s | %s | — | %s | %s |',
                        $project,
                        $label,
                        $purpose,
                        $purpose === $policy->primary ? 'yes' : 'no',
                        "None yet: the project's servers send it with the first signup.",
                        $policy->doubleOptIn ? 'yes' : 'no',
                    );
                }
            }
        }

        return [...$lines, '', 'The package records consent choices per purpose, each withdrawable on its own. The operator must determine the lawful basis and assess whether consent is valid; storing a record does not establish this.', ''];
    }

    /**
     * @param  list<string>  $projects
     * @return list<string>
     */
    protected function retention(ProjectCatalog $catalog, array $projects): array
    {
        $period = fn (?int $days, string $text): string => $days !== null
            ? "- {$text}: after {$days} days"
            : "- {$text}: kept until erased";

        $retention = WaitlistConfig::retention();
        $schedule = WaitlistConfig::pruneSchedule();
        [$offering, $without] = $this->manageLinkProjects($catalog, $projects);

        return [
            '## Retention',
            '',
            'Configured periods are technical settings, not statutory periods or legal recommendations. The operator must justify them and monitor cleanup.',
            '',
            $period($retention->pendingDays, 'Unconfirmed signups erased'),
            $period($retention->unsubscribedDays, 'Addresses that left erased'),
            $period($retention->requestMetadataDays, 'IP and user agent cleared from the log'),
            $schedule !== null
                ? "- Applied by waitlist:prune on the schedule `{$schedule}`; Laravel's scheduler must run"
                : '- Not scheduled by the package: run waitlist:prune yourself',
            match (true) {
                $without === [] => '- On request (Art. 17): waitlist:forget, or the person via a manage link sent to their address',
                $offering === [] => '- On request (Art. 17): waitlist:forget',
                default => '- On request (Art. 17): waitlist:forget, or the person via a manage link sent to their address, except in projects without manage links ('.implode(', ', $without).')',
            },
            '- Active confirmed entries and remaining reporting rows have no automatic expiry; the remaining log is not guaranteed anonymous',
            '- Backups, queues, logs, exports and external provider copies require separate retention and erasure handling',
            '',
        ];
    }

    /**
     * @param  list<string>  $projects
     * @return list<string>
     */
    protected function measures(ProjectCatalog $catalog, array $projects): array
    {
        $cooldown = ResendConfirmation::cooldown();
        $cap = ResendConfirmation::maxConfirmations();
        [$offering, $without] = $this->manageLinkProjects($catalog, $projects);

        return [
            '## Technical and organisational measures (Art. 32)',
            '',
            'Implemented by the package:',
            '',
            '- Address, metadata, IP, user agent and mail references encrypted at rest with '.$this->encryptedWith(),
            '- Addresses looked up by an HMAC-SHA256 hash with a subkey of the '.$this->encryptedWith().' key, never by the address itself',
            '- Tokens stored as SHA-256 hashes, the unsubscribe token additionally encrypted; none exported',
            match (true) {
                $without === [] => '- Mails carry an unsubscribe token that can only remove; access to the data and erasure needs a manage link that is mailed to the address on request and expires after '.$this->manageTtl(),
                $offering === [] => '- Mails carry an unsubscribe token that can only remove; no manage links are sent, so access to the data and erasure go through you (waitlist:export, waitlist:forget)',
                default => '- Mails carry an unsubscribe token that can only remove; access to the data and erasure needs a manage link that is mailed to the address on request and expires after '.$this->manageTtl()
                    .'; projects without manage links ('.implode(', ', $without).') handle both through you (waitlist:export, waitlist:forget)',
            },
            '- Confirmation mails limited to one per '.($cooldown !== null ? "{$cooldown} minutes" : 'request')
                .($cap !== null ? " and {$cap} per cycle" : ''),
            ...(($pending = ResendConfirmation::maxPendingPerAddress()) !== null
                ? ["- At most {$pending} confirmation requests per address and day for unconfirmed lists of a project; further ones are held back"]
                : []),
            WaitlistConfig::routesEnabled()
                ? '- Rate limits: signups '.$this->rateLimit(WaitlistConfig::signupLimiter(), WaitlistServiceProvider::SIGNUP_LIMITER, WaitlistConfig::signupPerMinute().' per minute and IP')
                    .', token links '.$this->rateLimit(WaitlistConfig::linksLimiter(), WaitlistServiceProvider::LINKS_LIMITER, WaitlistConfig::linkPerMinute().' per minute and token')
                    .'; GET never changes state'
                : '- No public endpoints (package routes disabled)',
            ...$this->servers(),
            '- Consent records immutable; lifecycle log rows never deleted, only stripped on erasure',
            '- Erasure strips the log: only project, list, step, purpose, the status a departure left and date remain',
            '- No mail sent and no outbound requests made by the package',
            '',
            'Yours to add: access control to the database, its backups and the queue; protection of APP_KEY; bot protection on public forms.',
            '',
        ];
    }

    /**
     * Only where servers can call for a project: they are limited as a whole,
     * and a forwarded visitor address replaces theirs.
     *
     * @return list<string>
     */
    protected function servers(): array
    {
        if (! WaitlistConfig::routesEnabled()) {
            return [];
        }

        $header = WaitlistConfig::clientIpHeader();

        if (! WaitlistConfig::authenticationRequired() && $header === null) {
            return [];
        }

        $lines = [];

        if (WaitlistConfig::signupLimiter() === WaitlistServiceProvider::SIGNUP_LIMITER) {
            $lines[] = '- Rate limits for servers calling for a project: signups '.WaitlistConfig::callerSignupPerMinute().' per minute and server'
                .($header !== null ? ', and '.WaitlistConfig::signupPerMinute().' per minute and visitor' : '');
        }

        if ($header !== null) {
            $lines[] = "- A visitor's address is read from the `{$header}` header of servers calling for a project, never of guests; it is the address limited and, where IP addresses are stored, logged";
        }

        return $lines;
    }

    /**
     * What a launch would trip over, from the same settings the record reads:
     * run it on the production host.
     *
     * @param  list<string>  $projects
     * @return list<string>
     */
    protected function warnings(ProjectCatalog $catalog, array $projects): array
    {
        $warnings = $this->linkWarnings($catalog, $projects);

        if (! WaitlistConfig::routesEnabled()) {
            return $warnings;
        }

        if (config('app.debug') === true) {
            $warnings[] = 'APP_DEBUG is on while the waitlist routes are enabled: error responses show exception messages and traces.';
        }

        try {
            if (WaitlistConfig::guards() !== [null] && ! WaitlistConfig::authenticationRequired()) {
                $warnings[] = 'authentication.guards names guards but authentication.required is off, so guests can call the signup too. Turn it on if only your own servers may.';
            }
        } catch (InvalidConfigurationException $exception) {
            $warnings[] = $exception->getMessage();
        }

        return $warnings;
    }

    /**
     * The links in mails are the pages' patterns, or APP_URL for a page left on
     * the package routes.
     *
     * @param  list<string>  $projects
     * @return list<string>
     */
    protected function linkWarnings(ProjectCatalog $catalog, array $projects): array
    {
        $found = [];

        foreach ($projects as $project) {
            if ($catalog->lists($project) === []) {
                continue;
            }

            foreach ([Page::Confirm, Page::Unsubscribe, Page::Manage] as $page) {
                if ($page === Page::Manage && ! $catalog->manageLinks($project)) {
                    continue;
                }

                $pattern = $catalog->urlPattern($project, $page->value);
                $url = $pattern ?? (WaitlistConfig::routesEnabled() ? config('app.url') : null);
                $problem = is_string($url) ? $this->urlProblem($url) : null;

                if ($problem !== null) {
                    $found[$project][$problem.($pattern === null ? ', from APP_URL' : '')][] = $page->value;
                }
            }
        }

        $warnings = [];

        foreach ($found as $project => $problems) {
            foreach ($problems as $problem => $pages) {
                $warnings[] = 'The '.implode(', ', $pages)." link of project [{$project}] {$problem}, so a link in a mail may not open for the person.";
            }
        }

        return $warnings;
    }

    protected function urlProblem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if ($scheme === null || $host === null) {
            return 'is not an absolute URL';
        }

        if (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || Str::endsWith($host, ['.localhost', '.test'])) {
            return "points at {$scheme}://{$host}";
        }

        return $scheme === 'https' ? null : "does not use https ({$scheme}://{$host})";
    }

    protected function encryptedWith(): string
    {
        $encrypter = app(WaitlistManager::class)->encrypter();

        return $encrypter === app('encrypter') ? 'APP_KEY' : 'a custom encrypter ('.$encrypter::class.')';
    }

    /**
     * @return list<string>
     */
    protected function recipients(): array
    {
        $lines = [
            '## Recipients',
            '',
            'The package sends no mail and makes no outbound requests. The listeners below are possible integration points, not a complete list of recipients. Review API responses, exports, queues, logs, backups and other application data flows separately.',
            '',
        ];

        $listeners = Event::getRawListeners();
        $found = false;

        foreach (self::EVENTS as $event) {
            foreach (Arr::wrap($listeners[$event] ?? []) as $listener) {
                $lines[] = '- '.class_basename($event).': '.$this->describe($listener);
                $found = true;
            }
        }

        if (! $found) {
            $lines[] = '- No listeners registered.';
        }

        return [...$lines, '', 'Wildcard listeners are not included.'];
    }

    protected function describe(mixed $listener): string
    {
        return match (true) {
            is_string($listener) => $listener,
            is_array($listener) && count($listener) === 2 => $this->part($listener[0]).'@'.$this->part($listener[1]),
            $listener instanceof Closure => $this->describeClosure($listener),
            default => get_debug_type($listener),
        };
    }

    protected function part(mixed $part): string
    {
        return match (true) {
            is_object($part) => $part::class,
            is_string($part) => $part,
            default => get_debug_type($part),
        };
    }

    protected function describeClosure(Closure $listener): string
    {
        $reflection = new ReflectionFunction($listener);
        $file = $reflection->getFileName();

        return $file === false
            ? 'Closure'
            : 'Closure in '.Str::chopStart($file, base_path().DIRECTORY_SEPARATOR).':'.$reflection->getStartLine();
    }

    protected function rateLimit(?string $limiter, string $packageLimiter, string $packageLimit): string
    {
        return match (true) {
            $limiter === null => 'not limited by the package',
            $limiter === $packageLimiter => $packageLimit,
            default => "by your [{$limiter}] limiter",
        };
    }

    /**
     * Only projects with lists count: one without takes no signups, like the
     * default project, which exists whether it is defined or not.
     *
     * @param  list<string>  $projects
     * @return array{list<string>, list<string>} with manage links, without
     */
    protected function manageLinkProjects(ProjectCatalog $catalog, array $projects): array
    {
        $offering = [];
        $without = [];

        foreach ($projects as $project) {
            if ($catalog->lists($project) === []) {
                continue;
            }

            if ($catalog->manageLinks($project)) {
                $offering[] = $project;
            } else {
                $without[] = $project;
            }
        }

        return [$offering, $without];
    }

    protected function manageTtl(): string
    {
        $minutes = IssueManageLink::ttl();

        return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
    }
}
