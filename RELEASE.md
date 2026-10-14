# Release Instructions

A release is a tag `vX.Y.Z` on `main` with a GitHub release. Packagist picks the tag up through its GitHub hook, and `.github/workflows/update-changelog.yml` adds the release notes to `CHANGELOG.md` when the release is published.

## Versions

The first release is 1.0.0. The versions below it are milestones: the API, the configuration and the migrations may change, as the warning in the README says. A breaking change raises the minor version (`v0.1.1` to `v0.2.0`) and its pull request carries the `breaking` label; everything else raises the patch version. From 1.0 the package follows [SemVer](https://semver.org/).

The version comes from the tag. `composer.json` has none.

## Migrations

Until the first release the create migrations change freely. From the first release on they never change: a schema change is a new migration, guarded with `Schema::hasTable()` or `Schema::hasColumn()` so that publishing the migrations again is safe, and the release notes name it.

## Before you release

1. Review `CHANGELOG.md`, `.github/release.yml`, `.github/workflows/update-changelog.yml` and the diff since the last tag. Before you change a release workflow, check the supported matrix in `.github/workflows/tests.yml`.
2. `composer test` passes, and `main` is green on every lane of `tests.yml`, including MySQL, MariaDB and PostgreSQL.
3. `CHANGELOG.md` has a meaningful entry under "Unreleased" for everything users notice, an upgrade step such as a new migration included. The generated release notes list pull requests; they do not replace these entries.
4. The merged pull requests carry the right label (`breaking`, `enhancement`, `bug`, `documentation`, `maintenance`, `dependencies`, or `skip-changelog` for what readers of the notes do not need), because `.github/release.yml` groups the notes by label.
5. The package is released before the [JS client](https://github.com/Taldres/laravel-waitlist-js): its contract tests run against the package's `main`.

## Release

Tagging, pushing and publishing are the maintainer's step; everything above can be prepared by anyone.

1. On GitHub, open **Releases** and choose **Draft a new release**.
2. Create the tag `vX.Y.Z` on `main`.
3. Name the release exactly like the tag. The changelog workflow uses the name as the version heading.
4. Choose **Generate release notes**, add the entries from "Unreleased" above the generated list so that the changelog keeps them under the version, and read the result once.
5. Publish it as the latest release, not as a pre-release: the changelog workflow only runs for a published release.

## After the release

- The workflow "Update Changelog" has committed `Update CHANGELOG` to `main`.
- [Packagist](https://packagist.org/packages/taldres/laravel-waitlist) lists the new version.
- Release the JS client if it changed.
