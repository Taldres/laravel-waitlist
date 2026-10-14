## Laravel Waitlist

This package (`taldres/laravel-waitlist`) collects email addresses for a waitlist or early access, headless and privacy-first: versioned consent per purpose, double opt-in, encrypted personal data, retention and erasure. It ships no dashboard and never sends mail.

### Conventions

- Describe purposes, lists, fields and pages in `Waitlist::define()` in the published `WaitlistServiceProvider`; `config/waitlist.php` keeps only the technical settings. Read the environment with `config()`, never `env()`, outside the config files.
- Write only through the facade, `Waitlist::for($list)` and the actions, never into the tables: transitions are conditional updates inside a transaction, so events fire once.
- Plain tokens reach the application only through the events (`EntrySubscribed`, `ManageLinkRequested`) for the app's own mail, never through a response or a log.
- Swap behaviour with a container binding (`ProjectResolver`, `ProjectCatalog`, `SpamProtector`, `LinkGenerator`, `EmailNormalizer`) and the `useWaitlist` gate; schedule `waitlist:prune` yourself.

@verbatim
<code-snippet name="Define a waitlist" lang="php">
Waitlist::define(function (ProjectDefinition $project): void {
    $project->purpose('waitlist', ['2026-10' => 'Email me when early access opens.']);
    $project->list('default', purpose: 'waitlist');
});

$result = Waitlist::for('default')->add('jane@example.com', ['waitlist' => '2026-10']);
</code-snippet>
@endverbatim

Use the `laravel-waitlist-*` skills for the signup form, the confirmation mail, projects, reporting, privacy operations and tests.
