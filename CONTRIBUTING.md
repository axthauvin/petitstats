# Contributing to Petitstats

Thanks for your interest in improving Petitstats! This guide will explain how to set up a local development environment, the coding standard, checks and tests, and the commit convention.

## Local setup

Requirements:

- PHP 8.1 or later with the `sqlite3` and `pdo_sqlite` extensions
- [Composer](https://getcomposer.org/)
- [Node.js](https://nodejs.org/) (only to run the JavaScript tests)

```bash
git clone https://github.com/axthauvin/petitstats.git
cd petitstats/app
composer install
composer serve
```

Then open [http://localhost:8080/dashboard/](http://localhost:8080/dashboard/) and go through the onboarding to create a local admin account. The landing page is served at [http://localhost:8080/](http://localhost:8080/) by the development router.

Local data (`auth.db`, `sites.json`, analytics databases) is written to `petitstats-data/` at the repository root. To start from a clean install, point `PETITSTATS_DATA_DIR` to an empty directory before running `composer serve`.

## Project layout

| Path                | Contents                                                           |
| ------------------- | ------------------------------------------------------------------ |
| `app/`              | The deployable application (this is what release archives contain) |
| `app/src/`          | PHP classes, autoloaded under the `Petitstats\` namespace (PSR-4)  |
| `app/dashboard/`    | Dashboard pages, API endpoints (`src/api/`) and front-end assets   |
| `app/track.php`     | Tracking endpoint                                                  |
| `app/petitstats.js` | Tracking script embedded on websites                               |
| `app/tests/`        | JavaScript unit and smoke tests, PHPUnit tests in `app/tests/php/` |
| `landing/`          | Marketing site and install guide                                   |
| `docs/`             | User documentation, also published as the site's `/docs/` pages    |
| `scripts/`          | Development router, release and deployment scripts                 |

## Coding standard

- PHP follows [PER Coding Style 3.0](https://www.php-fig.org/per/coding-style/), enforced by PHP-CS-Fixer (`.php-cs-fixer.dist.php`).
- New PHP classes go in `app/src/` under the `Petitstats\` namespace, one class per file, with the file path matching the namespace ([PSR-4](https://www.php-fig.org/psr/psr-4/)).
- Every PHP file starts with `declare(strict_types=1);`.
- Indentation, line endings and line length are defined in `.editorconfig` (4 spaces, LF, 120 characters for PHP).

If you use VS Code, the recommended extensions in `.vscode/` format PHP files on save. (if you have a different IDE, do not hesitate to submit a pull request to add its configuration !).

## Checks and tests

Run these before opening a pull request. PHP commands run from `app/`:

```bash
composer cs:check   # report coding standard violations
composer cs:fix     # fix them automatically
composer analyse    # static analysis with PHPStan
composer test       # PHP tests with PHPUnit
```

PHPStan runs at the level set in `app/phpstan.base.neon`. Errors that existed when it was introduced are listed in `app/phpstan-baseline.neon`: new code must not add to it. Run `composer analyse:all` to see every error including the baselined ones, and when you fix one, regenerate the baseline with `composer analyse:baseline`.

PHPUnit tests run against a temporary data directory with a single `test_site` website, so they never touch your local install. Test what a class returns for given data rather than how it computes it, so the tests keep passing through refactors.

JavaScript tests run from the repository root:

```bash
node --test app/tests/*.test.js
```

Some tests start a PHP server, so they are skipped when `php` is not on your `PATH`.

The [CI workflow](.github/workflows/ci.yml) runs all of these checks, plus a PHP syntax check on PHP 8.1, on every pull request and every push to `main`.

If you change something that the tests don't cover, describe how you checked it manually in the pull request.

## Documentation

The guides in `docs/` are the only source: edit the Markdown files, and they read well on GitHub as they are. `scripts/build-docs.php` turns them into the `/docs/` pages of the website (plain PHP, no dependency) when the landing page is packaged. `composer serve` builds them on start, so open http://localhost:8080/docs/ to preview them. After editing a guide while the server runs, rebuild the pages:

```bash
php scripts/build-docs.php
```

## Commit convention

Commits follow [Conventional Commits](https://www.conventionalcommits.org/):

```git
<type>(<optional scope>): <short summary in lowercase>
```

| Type       | Use for                                       |
| ---------- | --------------------------------------------- |
| `feat`     | A new feature or user-visible improvement     |
| `fix`      | A bug fix                                     |
| `refactor` | Code changes that don't change behaviour      |
| `docs`     | Documentation only                            |
| `chore`    | Tooling, dependencies, build and housekeeping |

Examples: `feat(sessions): add session duration filter`, `fix: client code only logs js when in debug mode`.

Reference the issue a commit resolves in its message, for example `closes #47`.

## Pull requests

1. Create a branch from `main`.
2. Keep each pull request focused on a single issue or change.
3. Make sure the coding standard check and the tests pass.
4. Explain what changed and why, and link the related issue.

For security issues, please do not open a public issue, contact a maintainer directly instead.
