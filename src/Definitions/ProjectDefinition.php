<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Definitions;

use Closure;
use Taldres\Waitlist\Enums\Page;
use Taldres\Waitlist\Exceptions\InvalidConfigurationException;

/**
 * One project as a Waitlist::define() callback describes it: what people can
 * agree to, the lists they can join, the fields a signup may carry and the
 * pages the mails link to. Mistakes throw while the callback runs, which is
 * when the waitlist first needs the project.
 */
final class ProjectDefinition
{
    /**
     * Project and list names; the HTTP signup accepts the same.
     */
    public const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/';

    /**
     * @var array<string, array<string, string|array<string, string>>>
     */
    private array $purposes = [];

    /**
     * @var array<string, ListDefinition>
     */
    private array $lists = [];

    /**
     * @var array<string, string>
     */
    private array $urls = [];

    /**
     * @var (Closure(): array<string, mixed>)|array<string, mixed>
     */
    private Closure|array $fields = [];

    private bool $wordingFromCallers = false;

    /**
     * @internal Created by ProjectDefinitions when the project is first needed.
     */
    public function __construct(
        public readonly string $name,
    ) {
        self::assertName('project', $name);
    }

    /**
     * What people can agree to, with its wording per version: one text, or a
     * text per locale. The last version is the current one; the others stay
     * accepted, so a form loaded before a change still records what it
     * showed. Never edit a version's wording, add a new one. Leave a version
     * out to stop accepting it; stored consents keep their wording.
     *
     * Checked here rather than trusted from the type, so a malformed wording
     * fails at once instead of disappearing from the form.
     *
     * @param  array<array-key, mixed>  $versions  version => wording, or version => locale => wording; oldest first
     */
    public function purpose(string $name, array $versions): static
    {
        self::assertPurposeName($this->name, $name);

        $normalized = [];

        foreach ($versions as $version => $wording) {
            $version = (string) $version;

            if ($version === '' || mb_strlen($version) > 100) {
                throw new InvalidConfigurationException("The versions of the purpose [{$name}] in the project [{$this->name}] must be 1 to 100 characters long, [{$version}] is not.");
            }

            if (! self::wellFormed($wording)) {
                throw new InvalidConfigurationException("The wording of the purpose [{$name}] version [{$version}] in the project [{$this->name}] must be a text, or a text per locale.");
            }

            $normalized[$version] = $wording;
        }

        $this->purposes[$name] = $normalized;

        return $this;
    }

    /**
     * A list people can join, with its primary purpose: required for every
     * signup and never bundled with an optional one. "*" accepts every list
     * name the project does not define. Defining a list again replaces it.
     */
    public function list(string $name, string $purpose): ListDefinition
    {
        if ($name !== '*') {
            self::assertName('list', $name, "in the project [{$this->name}] ");
        }

        self::assertPurposeName($this->name, $purpose);

        return $this->lists[$name] = new ListDefinition($this->name, $name, $purpose);
    }

    /**
     * Your pages. "confirm", "unsubscribe" and "manage" are where the mail
     * links point, with {token} replaced; unset ones use the package routes
     * when they are enabled. "confirmed", "expired", "invalid", "unsubscribed"
     * and "erased" are where a browser lands after posting to the package
     * routes; unset ones answer JSON. Null or an empty string leaves a page as
     * it is, so an unset environment value falls back to the package routes.
     */
    public function urls(
        ?string $confirm = null,
        ?string $unsubscribe = null,
        ?string $manage = null,
        ?string $confirmed = null,
        ?string $expired = null,
        ?string $invalid = null,
        ?string $unsubscribed = null,
        ?string $erased = null,
    ): static {
        $patterns = [
            Page::Confirm->value => $confirm,
            Page::Unsubscribe->value => $unsubscribe,
            Page::Manage->value => $manage,
            Page::Confirmed->value => $confirmed,
            Page::Expired->value => $expired,
            Page::Invalid->value => $invalid,
            Page::Unsubscribed->value => $unsubscribed,
            Page::Erased->value => $erased,
        ];

        foreach ($patterns as $action => $pattern) {
            if ($pattern === null || $pattern === '') {
                continue;
            }

            if (Page::from($action)->isMailLink() && ! str_contains($pattern, '{token}')) {
                throw new InvalidConfigurationException("The [{$action}] URL of the project [{$this->name}] needs a {token} placeholder.");
            }

            $this->urls[$action] = $pattern;
        }

        return $this;
    }

    /**
     * Fields a signup on any list of the project may send under "metadata":
     * field => validation rules. Without fields, a signup that sends metadata
     * is refused. Pass a closure to build rule objects on every validation
     * instead of sharing one instance across requests.
     *
     * @param  (Closure(): array<string, mixed>)|array<string, mixed>  $rules
     */
    public function fields(Closure|array $rules): static
    {
        $this->fields = $rules;

        return $this;
    }

    /**
     * Lets the project's servers send the wording with a signup; a version
     * seen for the first time is registered. Versions given with purpose()
     * win.
     */
    public function wordingFromCallers(bool $accept = true): static
    {
        $this->wordingFromCallers = $accept;

        return $this;
    }

    /**
     * @return array<string, array<string, string|array<string, string>>> purpose => versions
     */
    public function getPurposes(): array
    {
        return $this->purposes;
    }

    /**
     * @return array<string, string|array<string, string>> version => wording, or version => locale => wording; oldest first
     */
    public function getVersions(string $purpose): array
    {
        return $this->purposes[$purpose] ?? [];
    }

    /**
     * @return array<string, ListDefinition>
     */
    public function getLists(): array
    {
        return $this->lists;
    }

    /**
     * Only the list of that name; "*" is a list of its own here.
     */
    public function getList(string $name): ?ListDefinition
    {
        return $this->lists[$name] ?? null;
    }

    /**
     * @param  string  $action  a Page value
     */
    public function getUrl(string $action): ?string
    {
        return $this->urls[$action] ?? null;
    }

    public function getWordingFromCallers(): bool
    {
        return $this->wordingFromCallers;
    }

    /**
     * Built anew on every call.
     *
     * @return array<string, mixed>
     */
    public function getFields(): array
    {
        return self::resolveFields($this->fields, "project [{$this->name}]");
    }

    /**
     * @internal
     *
     * @param  (Closure(): mixed)|array<array-key, mixed>  $fields
     * @return array<string, mixed>
     */
    public static function resolveFields(Closure|array $fields, string $owner): array
    {
        $rules = $fields instanceof Closure ? $fields() : $fields;

        if (! is_array($rules)) {
            throw new InvalidConfigurationException("The fields of the {$owner} must be an array of field => validation rules.");
        }

        $resolved = [];

        // A dot would address a nested key and a comma would split the
        // allow-list rule.
        foreach ($rules as $field => $fieldRules) {
            if (! is_string($field) || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $field) !== 1) {
                throw new InvalidConfigurationException("The field [{$field}] of the {$owner} must start with a letter and contain only letters, digits, \"_\" and \"-\".");
            }

            $resolved[$field] = $fieldRules;
        }

        return $resolved;
    }

    /**
     * @internal
     */
    public static function assertPurposeName(string $project, string $purpose): void
    {
        if ($purpose === '' || mb_strlen($purpose) > 100) {
            throw new InvalidConfigurationException("The purposes of the project [{$project}] must be named with 1 to 100 characters, [{$purpose}] is not.");
        }
    }

    private static function assertName(string $kind, string $name, string $where = ''): void
    {
        if (mb_strlen($name) > 100 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidConfigurationException("The {$kind} name [{$name}] {$where}must be at most 100 characters, start with a letter or digit and contain only letters, digits, \".\", \"_\", \":\" and \"-\".");
        }
    }

    /**
     * @phpstan-assert-if-true string|array<string, string> $wording
     */
    private static function wellFormed(mixed $wording): bool
    {
        if (is_string($wording)) {
            return $wording !== '';
        }

        if (! is_array($wording) || $wording === []) {
            return false;
        }

        foreach ($wording as $locale => $text) {
            if (! is_string($locale) || $locale === '' || mb_strlen($locale) > 35 || ! is_string($text) || $text === '') {
                return false;
            }
        }

        return true;
    }
}
