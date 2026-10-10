# Release Notes

## [Unreleased](https://github.com/taldres/laravel-waitlist/commits/main)

- Requires PHP 8.3+ and Laravel 13
- Subscription cycles with exactly-once state changes
- Consent per purpose: one required primary purpose per list, optional purposes withdrawable on their own
- Wording per locale: forms get the text in the visitor's language, and each consent stores the locale it was given in
- Forms with their own copy of the wording post back its hash, and a text that drifted from the registered one is refused
- `recipients()`: everyone a mail for a purpose may go to, once per address, with the links and the locale that mail needs
- Wording registered where it is written: `StoredWordingCatalog`, `waitlist:wording` to sync a frontend's wording on deploy, and `Waitlist::registerWording()` for CMS webhooks
- Wording sent by a project's own servers: with `wordingFromCallers()` a signup brings the text along with the version, a version seen for the first time is registered with the server that sent it (`registered_by`, `WordingRegistered`), and a registered version never changes; only callers the `useWaitlist` gate allows `WaitlistAction::RegisterWording`, by default those acting for the project, may send it, never guests
- A purpose choice that is wrong says what is wrong with it, and hashes are accepted in either case
- Addresses, metadata, IP and user agent always encrypted at rest with Laravel's `encrypted` casts, following `Model::encryptUsing()`, or with an encrypter for the package only (`Waitlist::encryptUsing()`); lookups on a keyed hash
- Retention periods applied by a scheduled `waitlist:prune`
- Refusals the routes answer themselves name an `error` (`invalid_token`, `expired_token`, `unknown_list`, `not_subscribed`, `list_unavailable`), so a client can tell them from a `404` for a route that does not exist
- Reporting from the activity log: daily series and totals per list, and the confirmed count at the end of any day (`confirmedOn()`), with every departure recorded by the status it left so a later clean-up is not counted twice
- Self-service preference page: purposes, data export, erasure. Mails carry an unsubscribe token that can only remove; the page opens with a short-lived manage link mailed to the address on request
- `waitlist:privacy` for the record of processing
- Projects defined in code: `Waitlist::define()` in a service provider describes a project's purposes, lists, fields and pages, checks what it is given, and runs when the project is first needed; `waitlist:install` publishes a `WaitlistServiceProvider` to start from and registers it
- Projects: one app can run the waitlists of several products, each with its own purposes, lists, fields and frontend pages; all of it can come from your own `ProjectCatalog`, and a `ProjectResolver` picks the project of each signup before it is validated, so one app can serve as a central waitlist API
- Who may call, as a Laravel gate: `useWaitlist` decides per project, action and list for the signup, the wording and a manage link by address, after the project is resolved and before the body is validated; its default keeps a caller acting for a project (`HasWaitlistProject`) to its own and, with `waitlist.authentication.required`, refuses guests and callers without a project; define the ability to replace it. `AuthenticatedProjectResolver` takes the project from the caller of the configured guards, for central APIs whose clients hold credentials
- Setup mistakes throw `InvalidConfigurationException` (an `InvalidArgumentException`, never a `WaitlistException`), and a primary purpose without wording `MissingWordingException`, which `waitlist:privacy` reports while failing on anything else
- Config keys and frontend pages as enums: `ConfigKey` names every key of `config/waitlist.php` with its default, which a test keeps in step with the file, and `Page` the pages a project defines
- Fields per project and list: a signup accepts only the metadata its project and list define, validated with any Laravel rule, rule objects included, built anew for every request; `Waitlist::for($list)->fields()` hands the same rules to your own controllers, and `waitlist:privacy` lists them
- One withdrawal rule: lists that share a primary purpose are separate waitlists, an optional purpose such as a newsletter is withdrawn on every list of the project, and leaving a list withdraws its primary purpose
- Recording of the confirmation mail reference via `Waitlist::confirmationMailed()`, next to the consent wording
- `waitlist:rekey` moves every stored value onto the current key, so old keys can be retired
- RFC 8058 one-click unsubscribe
- Rate limits per route group with named limiters (`waitlist`, `waitlist-links`) that can be tuned, replaced or turned off; token links are limited per token rather than per IP
- Servers calling for a project are limited as one source, since all their visitors share an address: capped per server at `caller_signup_per_minute`, with the limit per visitor left to them, unless they forward the visitor's address in the header named by `authentication.client_ip_header`, which then also counts per visitor and is the IP recorded
- Signups capped per address across lists (`max_pending_per_address`), so made-up list names cannot flood a mailbox; errors name the field in a JSON 422
- Bundled Laravel Boost skills: the integration as a whole, plus focused skills for the frontend, mail, provider sync, projects, reporting, the launch, privacy operations and testing
