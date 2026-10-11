---
name: package-release
description: "Use this skill when preparing Laravel package releases: CHANGELOG.md updates, generated release notes, GitHub release workflows, version checks, tags, release validation, or release automation changes. Never publish autonomously."
license: MIT
metadata:
  author: laravel
---

# Package Release

## Primary Goal

Prepare a safe package release checklist and implementation without tagging, pushing, or publishing unless the user explicitly approves that action.

## Workflow

1. Review `CHANGELOG.md`, generated release notes config, GitHub release workflows, open diff, and pending package changes.
2. Validate the release state with `composer test` before recommending a release.
3. Confirm whether version metadata needs to change; many Laravel packages rely on Git tags rather than a hardcoded package version.
4. Review tag naming, release branch, and GitHub release workflow behavior before any release command.
5. Until the first release the create migrations change freely. From the first release on they never change: a schema change is a new migration guarded with `Schema::hasColumn` or `Schema::hasTable`, and the notes name it.
6. Do not tag, push, or publish without explicit user approval.

## References

- `CHANGELOG.md`
- `.github/release.yml`
- `.github/workflows/update-changelog.yml`
- `.github/workflows/tests.yml`
- `composer.json`

## Examples

- Prepare a release by checking changelog coverage, confirming generated release notes categories, running `composer test`, and drafting the tag command for user approval.
- Update release notes grouping in `.github/release.yml` when a new label convention is added.

## Anti-Patterns

- Creating tags, pushing branches, or publishing releases without explicit approval.
- Skipping `composer test` before a release recommendation.
- Editing a create migration after the first release instead of adding a guarded one.
- Treating generated release notes as a replacement for meaningful `CHANGELOG.md` entries.
- Changing release workflows without checking the supported matrix in `.github/workflows/tests.yml`.
