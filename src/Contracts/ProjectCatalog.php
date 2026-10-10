<?php

declare(strict_types=1);

namespace Taldres\Waitlist\Contracts;

use Taldres\Waitlist\Support\ListPolicy;
use Taldres\Waitlist\Support\ProjectPeriods;

/**
 * Everything project-specific: lists, the wording of their purposes, the
 * fields a signup may carry, the frontend URL patterns, whether manage
 * links are offered and the periods that differ from the configuration. The default reads the projects registered with
 * Waitlist::define(); bind your own to keep them in a database.
 *
 * The package snapshots the wording a person agreed to, so later catalog
 * changes never touch stored consent.
 */
interface ProjectCatalog
{
    /**
     * Null when the project has no such list.
     */
    public function policy(string $project, string $list): ?ListPolicy;

    /**
     * The versions new consents may still use, oldest first; the last one is
     * current. Each holds one text for every locale, or a text per locale.
     * Retire a version by leaving it out. Never change the wording of a
     * version: register a new one.
     *
     * @return array<string, string|array<string, string>> version => wording, or locale => wording
     */
    public function versions(string $project, string $purpose): array;

    /**
     * The fields a signup on the list may send under "metadata", with their
     * validation rules; the HTTP signup refuses every other key, and all
     * metadata when this is empty. Called for each validation, so rule
     * objects built here never outlive a request.
     *
     * @return array<string, mixed> field => rules
     */
    public function fields(string $project, string $list): array;

    /**
     * The project's own page for an action, or null. $action is a Page value.
     * Page::Confirm, Unsubscribe and Manage are where mail links point, with a
     * {token} placeholder; null leaves them on the package routes. The other
     * pages are where a browser lands; null answers JSON.
     */
    public function urlPattern(string $project, string $action): ?string;

    /**
     * False when the project mails no manage links: requesting or minting one
     * throws ManageLinksDisabledException, and the HTTP request is refused.
     */
    public function manageLinks(string $project): bool;

    /**
     * The retention periods and the confirm link lifetime the project promises
     * where they differ from the configuration; each one it leaves out is read
     * from waitlist.retention and waitlist.double_opt_in.token_ttl. A central
     * API serves sites that promise different periods in their privacy notices.
     */
    public function periods(string $project): ProjectPeriods;

    /**
     * The origins, such as https://example.com, from which a browser may use the
     * project's endpoints as a guest; empty for any. A request without an Origin
     * header, as from a server, is not affected. The same list drives CORS.
     *
     * @return list<string>
     */
    public function origins(string $project): array;

    /**
     * Must include "default": the facade acts on that project when no
     * project() is named.
     *
     * @return list<string>
     */
    public function projects(): array;

    /**
     * Named lists of a project; "*" when it accepts any list.
     *
     * @return list<string>
     */
    public function lists(string $project): array;
}
