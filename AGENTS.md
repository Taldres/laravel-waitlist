# Laravel Waitlist

This repository is a Laravel package. Keep the package focused, idiomatic, and easy for Laravel developers to install, test, and maintain.

## Package Conventions

- Use Laravel-native package APIs and the existing service provider shape before adding abstractions.
- Keep package names, namespaces, Composer metadata, publish tags, documentation, and examples aligned with `taldres/laravel-waitlist`. The config key and publish tags use the short form `waitlist`.
- Add only the files and dependencies needed for the package behavior being implemented.
- Prefer explicit Laravel package code over helper abstractions unless the extension point is real.
- Keep tests focused on observable package behavior through public APIs, service provider wiring, commands, routes, published resources, and documentation promises.

## Project Rules

- Code, comments, commit messages and docs are in English. Comments only explain a non-obvious why.
- Privacy is a design constraint: personal data is stored with Laravel's `encrypted` casts and goes through the access and erasure paths, a retention period, and `waitlist:privacy`.
- Never write subscriptions, consents or activity directly; go through the actions and `SubscriptionLifecycle`, so transitions stay conditional and events fire once.
- The package ships no legal texts and never sends mail or makes outbound requests.
- Use `Illuminate\Support\Str` and `Arr` where they state the intent more clearly or replace a compound native operation, such as `Str::before()`, `Str::after()`, `Str::chopStart()` or `Arr::last()`; no blanket migration, and no collections for simple array work. Keep keys, order, strict comparisons, null handling, byte versus Unicode semantics and PHPStan's types (`Arr::only()` is typed as a bare `array` and loses the key type), and check that the helper exists in the lowest Laravel version `composer.json` allows. `Arr::some()` and `Arr::every()` need a closure that takes `mixed`, as PHPStan rejects a narrower one.
- Public classes with behavior stay open, not `final`: apps have needs the package cannot foresee. Value and result objects and classes marked `@internal` may be `final`. Open is no promise: the supported customizations are models, contracts with their config keys, container bindings, the `useWaitlist` gate, events and macros, as `docs/extending.md` lists them. A class is only swappable where the package resolves it from the container or the config, not where it creates it with `new`; do not add new `final` classes or remove `final` without that in mind.

## Attribution

- Do not add `Co-authored-by:` trailers to commits.
- Do not add any other AI attribution, such as "Generated with ..." lines, to commit messages or pull request descriptions.
- Commits are authored by the configured git user only.

## Quick Commands

- Full validation: `composer test`
- Formatting check: `composer lint:check`
- Static analysis: `composer analyse`
- Pest tests: `composer test:unit`
- Server databases: `DB_CONNECTION=mysql|mariadb|pgsql vendor/bin/pest`
- Workbench build: `composer build`
- Workbench server: `composer serve`

## Local Skills

- `package-scaffold`: use when adding package capabilities or wiring them through the service provider, including commands, migrations, routes, config, views, translations, assets, middleware, publish tags, workbench files, and console-only behavior.
- `package-testing`: use when adding or changing package tests with Pest 4/5 and Orchestra Testbench.
- `package-release`: use when preparing changelog, release notes, tags, or GitHub release workflow changes.
- `package-compatibility`: use when reviewing code, dependencies, or CI against the PHP and Laravel support matrix.
- `package-generate-skill`: use when updating the bundled Boost skill from the package implementation, README, and examples.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
