---
name: package-compatibility
description: "Use this skill when reviewing Laravel package compatibility across composer constraints, PHP versions, Laravel versions, Testbench versions, dependency stability lanes, Windows CI, or matrix-sensitive code and workflow changes."
license: MIT
metadata:
  author: laravel
---

# Package Compatibility

## Primary Goal

Keep package code, dependencies, and workflows compatible with the supported Laravel 13 and PHP 8.3+ matrix.

## Workflow

1. Read `composer.json` first to determine PHP, Laravel, and Testbench constraints.
2. Check changed code against the lowest Laravel release `composer.json` allows (13.0.0) and PHP 8.3+ syntax before adopting newer framework or language features. PHP 8.4 syntax such as property hooks is a parse error on 8.3. Do not call PHP 8.4 functions such as `array_any()` directly: Laravel's `Arr::first()`, `Arr::some()` and `Arr::every()` cover them, the latter two with a closure that takes `mixed`, as PHPStan rejects a narrower one.
3. Review `.github/workflows/tests.yml` for dependency stability lanes, prefer-lowest coverage, prefer-stable coverage, and Windows concerns. The prefer-lowest lane turns off Composer's blocking of releases with security advisories, as it would otherwise never install Laravel 13.0; locally, test the floor in a copy with `composer config policy.advisories.block false`.
4. When changing dependencies, confirm constraints still allow the intended Laravel and Testbench versions.
5. Validate with the smallest local command available, then rely on CI for full OS and dependency matrix coverage.

## References

- `composer.json`
- `.github/workflows/tests.yml`
- `phpstan.neon.dist`
- `tests/`
- `workbench/`

## Examples

- Review a new Laravel API call by checking whether it exists in Laravel 13.0.0, the lowest version `composer.json` allows, before merging it into shared package code.
- Review a dependency bump by checking Composer constraints, Testbench constraints, prefer-lowest behavior, and Windows path assumptions.

## Anti-Patterns

- Assuming the latest local dependency version represents the whole support matrix.
- Adding PHP syntax or Laravel APIs that exceed `composer.json` constraints.
- Ignoring Windows path separators, executable assumptions, or shell-only syntax in tests and workflows.
- Removing dependency stability lanes because they are slower than a single happy path.
