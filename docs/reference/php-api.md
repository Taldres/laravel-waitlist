# PHP API

Everything goes through the `Waitlist` facade (`Taldres\Waitlist\Facades\Waitlist`).
Where you start decides what a call acts on:

| Start | Acts on |
| --- | --- |
| `Waitlist::…` | the default project |
| `Waitlist::for('beta')->…` | one list of the default project, by address |
| `Waitlist::project('acme')->…` | one project; `->for('beta')` one of its lists |
| `Waitlist::allProjects()->…` | every project, for requests about a person |
| `Waitlist::confirm($token)` and every other token method | the entry the token belongs to, in whichever project |

Without `project()`, the facade never acts on all projects. See
[Projects, lists and fields](../projects.md).

Methods that complete a flow take tokens; `ScopedWaitlist` methods are
email-centric and act on one list, except `withdraw()` and `unsubscribe()`,
which follow the [withdrawal rule](../lifecycle.md#what-a-withdrawal-reaches):
an optional purpose goes on every list of the project, and leaving a list also
withdraws its primary purpose where it is optional elsewhere.

## Defining projects

In a service provider's `boot()`, see [Projects, lists and fields](../projects.md):

```php
Waitlist::define(Closure $callback): WaitlistManager                        // the default project
Waitlist::define(string $project, Closure $callback): WaitlistManager       // a named one; defining again replaces it
```

The callback receives a `Taldres\Waitlist\Definitions\ProjectDefinition` and runs
when the project is first needed. Every method throws an
`InvalidConfigurationException` for what it cannot accept.

```php
$project->purpose(string $name, array $versions): static           // version => text, or version => [locale => text]; oldest first
$project->list(string $name, string $purpose): ListDefinition      // "*" for every list name not defined
$project->fields(Closure|array $rules): static                      // field => rules, for every list
$project->urls(?string $confirm = null, ?string $unsubscribe = null, ?string $manage = null,
               ?string $confirmed = null, ?string $expired = null, ?string $invalid = null,
               ?string $unsubscribed = null, ?string $erased = null): static

$list->optional(string ...$purposes): static
$list->doubleOptIn(bool $enabled = true): static                    // overrides waitlist.double_opt_in.enabled
$list->fields(Closure|array $rules): static                         // on top of the project's; same names replace them
```

## Manager: `Waitlist::`

```php
Waitlist::for(string $list): ScopedWaitlist
Waitlist::project(string $project): ProjectWaitlist                         // UnknownProjectException if the catalog lacks it
Waitlist::hasProject(string $project): bool                                 // the default project always counts
Waitlist::caller(Request $request): ?Authenticatable                        // as the resolver and the useWaitlist gate see it
Waitlist::allProjects(): AllProjectsWaitlist
Waitlist::purposes(string $list, ?string $locale = null): array              // list<PurposeWording>
Waitlist::registerWording(string $purpose, string $version, string|array $wording): int
Waitlist::retireWording(string $purpose, string $version): int
Waitlist::subscribe(string $list, string $email, array $purposes, array $metadata = [], ?RequestContext $context = null): SubscribeResult
Waitlist::resendConfirmation(string $list, string $email, ?RequestContext $context = null): ?WaitlistEntry
Waitlist::confirm(string $plainToken, ?RequestContext $context = null): WaitlistEntry
Waitlist::confirmationMailed(WaitlistSubscription $subscription, string $reference, ?RequestContext $context = null): void  // record the reference of the mail your listener reports sending
Waitlist::grantConsent(string $manageToken, string $purpose, string $version, ?string $locale = null, ?RequestContext $context = null): WaitlistEntry
Waitlist::withdrawConsent(string $unsubscribeToken, string $purpose, ?RequestContext $context = null): WaitlistEntry
Waitlist::unsubscribe(string $unsubscribeToken, ?RequestContext $context = null): WaitlistEntry
Waitlist::requestManageLink(string $unsubscribeToken): bool                 // fires ManageLinkRequested
Waitlist::findByConfirmToken(string $plainToken): ?WaitlistSubscription
Waitlist::findByUnsubscribeToken(string $plainToken): ?WaitlistEntry
Waitlist::findByManageToken(string $plainToken): ?WaitlistEntry            // null once expired
Waitlist::unsubscribeToken(WaitlistEntry $entry): UnsubscribeToken
Waitlist::manageLink(WaitlistEntry $entry): ManageLink                     // mints a fresh, short-lived one
Waitlist::unsubscribeUrl(WaitlistEntry $entry, ?string $purpose = null): ?string
Waitlist::listUnsubscribeHeaders(WaitlistEntry $entry, ?string $purpose = null): array
Waitlist::exists(string $email, ?string $list = null): bool
Waitlist::findByEmail(string $email, ?string $list = null): Collection
Waitlist::recipients(string $purpose): LazyCollection                       // LazyCollection<Recipient>
Waitlist::report(): WaitlistReport
Waitlist::forget(string $email, ?string $list = null): int
Waitlist::personalData(string $email, ?string $list = null): Collection
Waitlist::verifySpamUsing(?Closure $callback): WaitlistManager
Waitlist::encryptUsing(?Encrypter $encrypter): WaitlistManager
```

## One list: `ScopedWaitlist`

```php
Waitlist::for('beta')->purposes(?string $locale = null): array
Waitlist::for('beta')->fields(): array                                         // field => rules the HTTP signup applies; add() does not
Waitlist::for('beta')->add(string $email, array $purposes, array $metadata = [], ?RequestContext $context = null): SubscribeResult
Waitlist::for('beta')->resendConfirmation(string $email, ?RequestContext $context = null): ?WaitlistEntry
Waitlist::for('beta')->withdraw(string $email, string $purpose, ?RequestContext $context = null): ?WaitlistEntry
Waitlist::for('beta')->unsubscribe(string $email, ?RequestContext $context = null): ?WaitlistEntry
Waitlist::for('beta')->requestManageLink(string $email): bool                  // fires ManageLinkRequested
Waitlist::for('beta')->recipients(?string $purpose = null): LazyCollection     // the primary purpose by default
Waitlist::for('beta')->has(string $email): bool
Waitlist::for('beta')->find(string $email): ?WaitlistEntry
Waitlist::for('beta')->count(): int
Waitlist::for('beta')->entries(): Builder
Waitlist::for('beta')->report(): WaitlistReport
Waitlist::for('beta')->snapshot(): ListSnapshot
Waitlist::for('beta')->export(string $path): int
Waitlist::for('beta')->forget(string $email): int
Waitlist::for('beta')->forgetAll(): int
Waitlist::for('beta')->personalData(string $email): Collection
```

`find()`, `has()`, `count()` and `entries()` include addresses that left; use the
entry's status or `hasConsentFor()` for current membership, `snapshot()` for
counts by status.

`add()` returns a `SubscribeResult`:

```php
$result->entry;         // WaitlistEntry
$result->subscription;  // WaitlistSubscription: this cycle, with its consents
$result->outcome;       // SubscribeOutcome, see the lifecycle
```

## One project: `ProjectWaitlist`

```php
Waitlist::project('acme')->for(string $list): ScopedWaitlist
Waitlist::project('acme')->purposes(string $list, ?string $locale = null): array
Waitlist::project('acme')->registerWording(string $purpose, string $version, string|array $wording): int
Waitlist::project('acme')->retireWording(string $purpose, string $version): int
Waitlist::project('acme')->exists(string $email, ?string $list = null): bool
Waitlist::project('acme')->findByEmail(string $email, ?string $list = null): Collection
Waitlist::project('acme')->forget(string $email, ?string $list = null): int
Waitlist::project('acme')->personalData(string $email, ?string $list = null): Collection
Waitlist::project('acme')->recipients(string $purpose): LazyCollection
Waitlist::project('acme')->report(): WaitlistReport
```

## Every project: `AllProjectsWaitlist`

```php
Waitlist::allProjects()->exists(string $email): bool
Waitlist::allProjects()->findByEmail(string $email): Collection
Waitlist::allProjects()->personalData(string $email): Collection
Waitlist::allProjects()->forget(string $email): int
Waitlist::allProjects()->report(): WaitlistReport
```

## The entry model

```php
$entry->email;                          // decrypted
$entry->project;  $entry->list;  $entry->status;   // EntryStatus
$entry->purposes;                       // purposes in force
$entry->hasConsentFor('newsletter');
$entry->currentSubscription->consents;  // purpose, version, locale, text, granted_at, withdrawn_at

WaitlistEntry::query()->inProject('acme')->forEmail($email)->whereConsentedTo('newsletter');
```

Never write entries, cycles, consents or activity directly: go through the
facade, so transitions stay conditional and events fire once.

## Exceptions

What a caller handles extends `WaitlistException`:

| Exception | Thrown when |
| --- | --- |
| `UnknownWaitlistException` | the list is not configured |
| `UnknownProjectException` | the project is not in the catalog (a kind of unknown waitlist) |
| `UnknownPurposeException` | a purpose, version or locale the list does not offer |
| `MissingConsentException` | the list's primary purpose was not chosen |
| `WordingMismatchException` | a posted hash does not match the registered text, or a required hash is missing (a kind of unknown purpose) |
| `WordingConflictException` | other text for a version that is already registered |
| `InvalidEmailException` | the address is not valid |
| `InvalidTokenException` | an unknown token, or a confirm token of a cycle that has ended |
| `ExpiredTokenException` | a confirm or manage token past its lifetime |

A setup mistake throws an `InvalidConfigurationException`, an
`InvalidArgumentException` and deliberately no `WaitlistException`, so a
`catch (WaitlistException)` in your controller never shows a broken setup to a
visitor as a form error. Fix it in code or config instead of catching it:

| Exception | Thrown when |
| --- | --- |
| `InvalidConfigurationException` | a project definition, a config value or a binding is wrong: a list whose primary purpose is optional too, a mail link without `{token}`, a model or binding of the wrong class, a guard `config/auth.php` does not define, a period below its minimum, export columns that may not be exported |
| `MissingWordingException` | a list's primary purpose has no wording in force, e.g. with `StoredWordingCatalog` before the first `waitlist:wording`; a kind of `InvalidConfigurationException`, which `waitlist:privacy` reports as a row instead of failing |

The macroable classes, swappable models and contracts are in [Extending](../extending.md).
