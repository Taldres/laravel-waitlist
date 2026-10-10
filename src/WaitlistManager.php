<?php

declare(strict_types=1);

namespace Taldres\Waitlist;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use Taldres\Waitlist\Actions\ConfirmEntry;
use Taldres\Waitlist\Actions\GetUnsubscribeToken;
use Taldres\Waitlist\Actions\GrantConsent;
use Taldres\Waitlist\Actions\IssueManageLink;
use Taldres\Waitlist\Actions\RecordActivity;
use Taldres\Waitlist\Actions\RequestManageLink;
use Taldres\Waitlist\Actions\UnsubscribeEntry;
use Taldres\Waitlist\Actions\WithdrawConsent;
use Taldres\Waitlist\Auth\WaitlistCaller;
use Taldres\Waitlist\Config\WaitlistConfig;
use Taldres\Waitlist\Contracts\ProjectCatalog;
use Taldres\Waitlist\Contracts\SpamProtector;
use Taldres\Waitlist\Definitions\ProjectDefinition;
use Taldres\Waitlist\Definitions\ProjectDefinitions;
use Taldres\Waitlist\Enums\ActivityType;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Exceptions\ExpiredTokenException;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;
use Taldres\Waitlist\Exceptions\InvalidEmailException;
use Taldres\Waitlist\Exceptions\InvalidTokenException;
use Taldres\Waitlist\Exceptions\ManageLinksDisabledException;
use Taldres\Waitlist\Exceptions\MissingConsentException;
use Taldres\Waitlist\Exceptions\UnknownProjectException;
use Taldres\Waitlist\Exceptions\UnknownPurposeException;
use Taldres\Waitlist\Exceptions\UnknownWaitlistException;
use Taldres\Waitlist\Exceptions\WordingConflictException;
use Taldres\Waitlist\Models\WaitlistEntry;
use Taldres\Waitlist\Models\WaitlistSubscription;
use Taldres\Waitlist\Reporting\WaitlistReport;
use Taldres\Waitlist\Support\ClosureSpamProtector;
use Taldres\Waitlist\Support\DefaultConfirmationUrlGenerator;
use Taldres\Waitlist\Support\ManageLink;
use Taldres\Waitlist\Support\PersonalData;
use Taldres\Waitlist\Support\PurposeWording;
use Taldres\Waitlist\Support\Recipient;
use Taldres\Waitlist\Support\RequestContext;
use Taldres\Waitlist\Support\ResolvesModel;
use Taldres\Waitlist\Support\SubscribeResult;
use Taldres\Waitlist\Support\SubscriptionLifecycle;
use Taldres\Waitlist\Support\UnsubscribeToken;

class WaitlistManager
{
    use Macroable;
    use ResolvesModel;

    /**
     * @var (Closure(Request): bool)|null
     */
    protected ?Closure $spamCheck = null;

    protected ?Encrypter $encrypter = null;

    /**
     * Replaces Laravel's encrypter for the waitlist's data. Its keys also make
     * the lookup hash, so register it in boot(), before anything touches the
     * waitlist.
     */
    public function encryptUsing(?Encrypter $encrypter): static
    {
        $this->encrypter = $encrypter;

        return $this;
    }

    public function encrypter(): Encrypter
    {
        return $this->encrypter ?? WaitlistEntry::currentEncrypter();
    }

    public function customEncrypter(): ?Encrypter
    {
        return $this->encrypter;
    }

    /**
     * Replaces the configured spam protector on the signup and on a manage link
     * requested by address; null restores waitlist.spam_protector.
     *
     * @param  (Closure(Request): bool)|null  $callback
     */
    public function verifySpamUsing(?Closure $callback): static
    {
        $this->spamCheck = $callback;

        return $this;
    }

    /**
     * @internal
     */
    public function spamProtector(): ?SpamProtector
    {
        return $this->spamCheck === null ? null : new ClosureSpamProtector($this->spamCheck);
    }

    /**
     * Describes a project: its purposes, lists, fields and pages. Without a
     * name, the default project. Defining a project again replaces it. The
     * callback runs when the waitlist first needs the project, so define in
     * boot() and read the environment through config(), never env().
     *
     * @param  string|(Closure(ProjectDefinition): mixed)  $project
     * @param  (Closure(ProjectDefinition): mixed)|null  $callback
     *
     * @throws InvalidConfigurationException when the project name is invalid or the callback is missing
     */
    public function define(string|Closure $project, ?Closure $callback = null): static
    {
        if ($project instanceof Closure) {
            [$project, $callback] = [WaitlistEntry::DEFAULT_PROJECT, $project];
        }

        if ($callback === null) {
            throw new InvalidConfigurationException("Waitlist::define() needs a callback that describes the project [{$project}].");
        }

        app(ProjectDefinitions::class)->define($project, $callback);

        return $this;
    }

    /**
     * A list of the default project; Waitlist::project() reaches the others.
     */
    public function for(string $list): ScopedWaitlist
    {
        return $this->project(WaitlistEntry::DEFAULT_PROJECT)->for($list);
    }

    /**
     * A project the catalog does not know is refused rather than taken for the
     * default one.
     *
     * @throws UnknownProjectException
     */
    public function project(string $project): ProjectWaitlist
    {
        if (! $this->hasProject($project)) {
            throw UnknownProjectException::forProject($project);
        }

        return new ProjectWaitlist($project);
    }

    /**
     * Whether the catalog knows the project; the default project always
     * exists. For gating credentials on projects in your own guards.
     */
    public function hasProject(string $project): bool
    {
        return in_array($project, app(ProjectCatalog::class)->projects(), true);
    }

    /**
     * The caller of a request to the signup endpoints, as the project resolver
     * and the useWaitlist gate see it: the first guard in
     * waitlist.authentication.guards that authenticates it, or null.
     */
    public function caller(Request $request): ?Authenticatable
    {
        return WaitlistCaller::of($request);
    }

    public function allProjects(): AllProjectsWaitlist
    {
        return new AllProjectsWaitlist;
    }

    /**
     * @param  array<string, string|array{version: string, locale?: string|null, hash?: string}>  $purposes  purpose => version shown, or {version, locale}
     * @param  array<string, mixed>  $metadata
     *
     * @throws UnknownWaitlistException
     * @throws UnknownPurposeException
     * @throws MissingConsentException
     * @throws InvalidEmailException
     */
    public function subscribe(string $list, string $email, array $purposes, array $metadata = [], ?RequestContext $context = null): SubscribeResult
    {
        return $this->for($list)->add($email, $purposes, $metadata, $context);
    }

    /**
     * @return list<PurposeWording>
     *
     * @throws UnknownWaitlistException
     */
    public function purposes(string $list, ?string $locale = null): array
    {
        return $this->for($list)->purposes($locale);
    }

    /**
     * @param  string|array<string, string>  $wording
     *
     * @throws InvalidArgumentException when a purpose, version or locale is too long
     * @throws WordingConflictException
     */
    public function registerWording(string $purpose, string $version, string|array $wording): int
    {
        return $this->project(WaitlistEntry::DEFAULT_PROJECT)->registerWording($purpose, $version, $wording);
    }

    public function retireWording(string $purpose, string $version): int
    {
        return $this->project(WaitlistEntry::DEFAULT_PROJECT)->retireWording($purpose, $version);
    }

    public function resendConfirmation(string $list, string $email, ?RequestContext $context = null): ?WaitlistEntry
    {
        return $this->for($list)->resendConfirmation($email, $context);
    }

    /**
     * @throws InvalidTokenException
     * @throws ExpiredTokenException
     */
    public function confirm(string $plainToken, ?RequestContext $context = null): WaitlistEntry
    {
        return app(ConfirmEntry::class)(plainToken: $plainToken, context: $context);
    }

    /**
     * By unsubscribe token; by address, use Waitlist::for($list)->unsubscribe($email).
     *
     * @throws InvalidTokenException
     */
    public function unsubscribe(string $plainToken, ?RequestContext $context = null): WaitlistEntry
    {
        return app(UnsubscribeEntry::class)(plainToken: $plainToken, context: $context);
    }

    /**
     * Adds an optional purpose. Needs a manage token; the unsubscribe token
     * cannot add anything. Idempotent.
     *
     * @throws InvalidTokenException
     * @throws ExpiredTokenException
     * @throws UnknownWaitlistException
     * @throws UnknownPurposeException
     */
    public function grantConsent(string $plainToken, string $purpose, string $version, ?string $locale = null, ?RequestContext $context = null): WaitlistEntry
    {
        return app(GrantConsent::class)(plainToken: $plainToken, purpose: $purpose, version: $version, locale: $locale, context: $context);
    }

    /**
     * Takes the unsubscribe token. Lists that share a primary purpose are
     * separate waitlists; an optional purpose is one consent for the project.
     * Idempotent.
     *
     * @throws InvalidTokenException
     */
    public function withdrawConsent(string $plainToken, string $purpose, ?RequestContext $context = null): WaitlistEntry
    {
        return app(WithdrawConsent::class)(plainToken: $plainToken, purpose: $purpose, context: $context);
    }

    /**
     * Records your listener's report that it mailed a cycle's confirmation
     * request; $reference is what it sent, e.g. a template version or the
     * provider's message id. The package verifies neither delivery nor the
     * legal validity of the consent.
     */
    public function confirmationMailed(WaitlistSubscription $subscription, string $reference, ?RequestContext $context = null): void
    {
        /** @var WaitlistEntry|null $entry */
        $entry = $subscription->entry()->first();

        // Erased in the meantime: a queued listener must not fail and mail again.
        if ($entry === null) {
            return;
        }

        app(RecordActivity::class)($entry, ActivityType::ConfirmationMailed, $subscription, $context, reference: $reference);
    }

    /**
     * Records your listener's report that it could not mail a cycle's
     * confirmation request, e.g. from the queued listener's failed() method.
     * The request stops counting against the resend cooldown and the caps, so
     * the person's own retry gets a mail. False when the cycle was confirmed
     * or has ended, a newer request was issued since, or the entry was erased.
     * $reference is what you want in the log, such as the provider's error
     * code; never the address or the provider's message.
     */
    public function confirmationFailed(WaitlistSubscription $subscription, ?string $reference = null, ?RequestContext $context = null): bool
    {
        // Erased in the meantime: a queued listener must not fail again.
        if ($subscription->entry()->doesntExist()) {
            return false;
        }

        return app(SubscriptionLifecycle::class)->confirmationFailed($subscription, $context ?? RequestContext::none(), $reference);
    }

    public function findByConfirmToken(string $plainToken): ?WaitlistSubscription
    {
        return ConfirmEntry::findByToken($plainToken);
    }

    public function findByUnsubscribeToken(string $plainToken): ?WaitlistEntry
    {
        return static::modelClass()::findByUnsubscribeToken($plainToken);
    }

    /**
     * Null once the token has expired.
     */
    public function findByManageToken(string $plainToken): ?WaitlistEntry
    {
        $entry = static::modelClass()::findByManageToken($plainToken);

        return $entry === null || $entry->hasExpiredManageToken() ? null : $entry;
    }

    /**
     * Returns the stored token, so links sent earlier stay valid; a fresh one
     * is minted only when none can be recovered (APP_KEY rotation without the
     * old key).
     */
    public function unsubscribeToken(WaitlistEntry $entry): UnsubscribeToken
    {
        return app(GetUnsubscribeToken::class)(entry: $entry);
    }

    /**
     * Mints a short-lived link to the preference page, replacing an earlier
     * one. It opens the person's data and erasure: send it only to the address
     * itself, or where you have identified the person some other way. Never
     * put it into every mail; that is what the unsubscribe link is for.
     *
     * @throws ManageLinksDisabledException
     */
    public function manageLink(WaitlistEntry $entry): ManageLink
    {
        return app(IssueManageLink::class)(entry: $entry);
    }

    /**
     * The manage link goes to the address via ManageLinkRequested and your
     * listener, never to whoever asked. False when the token is unknown or the
     * cooldown is running; a public endpoint must not tell the cases apart.
     *
     * @throws ManageLinksDisabledException
     */
    public function requestManageLink(string $unsubscribeToken): bool
    {
        $entry = $this->findByUnsubscribeToken($unsubscribeToken);

        return $entry !== null && app(RequestManageLink::class)(entry: $entry);
    }

    /**
     * With a purpose, the link withdraws only that one. Null only when the
     * package routes are disabled and no URL pattern is set.
     */
    public function unsubscribeUrl(WaitlistEntry $entry, ?string $purpose = null): ?string
    {
        $url = $this->unsubscribeToken($entry)->url;

        if ($url === null || $purpose === null) {
            return $url;
        }

        return UnsubscribeToken::withPurpose($url, $purpose);
    }

    /**
     * RFC 8058 one-click headers. Pass the purpose the mail is sent for, so the
     * click withdraws only that one. Empty when the package routes are
     * disabled: the one-click POST has to reach the package endpoint, not a
     * frontend page.
     *
     * @return array<string, string>
     */
    public function listUnsubscribeHeaders(WaitlistEntry $entry, ?string $purpose = null): array
    {
        if (! WaitlistConfig::routesEnabled()) {
            return [];
        }

        $url = DefaultConfirmationUrlGenerator::packageUrl(Page::Unsubscribe->value, array_filter([
            'token' => $this->unsubscribeToken($entry)->token,
            'purpose' => $purpose,
        ]));

        return [
            'List-Unsubscribe' => "<{$url}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    /**
     * Matches an address in any status, including one that left; use the
     * entry's status or hasConsentFor() for current membership.
     */
    public function exists(string $email, ?string $list = null): bool
    {
        return $this->project(WaitlistEntry::DEFAULT_PROJECT)->exists($email, $list);
    }

    /**
     * Matches an address in any status, including one that left; use the
     * entry's status or hasConsentFor() for current membership.
     *
     * @return Collection<int, WaitlistEntry>
     */
    public function findByEmail(string $email, ?string $list = null): Collection
    {
        return $this->project(WaitlistEntry::DEFAULT_PROJECT)->findByEmail($email, $list);
    }

    /**
     * @return LazyCollection<int, Recipient>
     */
    public function recipients(string $purpose): LazyCollection
    {
        return $this->project(WaitlistEntry::DEFAULT_PROJECT)->recipients($purpose);
    }

    public function report(): WaitlistReport
    {
        return $this->project(WaitlistEntry::DEFAULT_PROJECT)->report();
    }

    public function forget(string $email, ?string $list = null): int
    {
        return $this->defaultProject()->forget($email, $list);
    }

    /**
     * @return Collection<int, PersonalData>
     */
    public function personalData(string $email, ?string $list = null): Collection
    {
        return $this->defaultProject()->personalData($email, $list);
    }

    /**
     * Not through project(): the default project always exists, and erasing or
     * exporting its data must not wait for the catalog.
     */
    protected function defaultProject(): ProjectWaitlist
    {
        return new ProjectWaitlist(WaitlistEntry::DEFAULT_PROJECT);
    }
}
