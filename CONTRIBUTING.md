# Contributing to Ruvelo Editorial

Thanks for helping! Bug reports, docs fixes and features are all welcome.

## Before you start

- **Bugs:** [open an issue](https://github.com/Ruvelo/wp-editorial/issues/new/choose) with the steps to reproduce, or go straight to a pull request with a failing test.
- **Features:** open an issue first if it's more than a small change, so we can agree on the shape before you spend time on it.
- **Security issues:** don't open an issue. See [SECURITY.md](https://github.com/Ruvelo/.github/blob/main/SECURITY.md).

## Setup

You need PHP 8.2+, Composer and WP-CLI. No database server: locally, WordPress runs on SQLite.

```bash
git clone https://github.com/<you>/wp-editorial && cd wp-editorial
composer install
bin/dev-site.sh            # WordPress on SQLite in .wp/, with the plugin and Halyard's demo
wp server --path=.wp --port=8998
composer check
```

Sign in at `http://localhost:8998/wp-admin` as `maya` / `password`. `bin/dev-site.sh --fresh` starts the demo over.

`composer check` runs exactly what CI runs:

| Command | What it does |
|---|---|
| `composer test` | PHPUnit integration tests (wp-phpunit; SQLite locally, MySQL in CI) |
| `composer lint` | Code style (Pint) and the WordPress security, database and i18n sniffs (PHPCS) |
| `composer format` | Fix code style |
| `composer analyse` | Static analysis (PHPStan level 8 with the WordPress extension) |
| `composer zip` | Build `build/ruvelo-editorial.zip` |
| `composer pot` | Regenerate `languages/ruvelo-editorial.pot` |

CI also runs the tests on WordPress 7.0. To do that locally, download 7.0 next to the SQLite drop-in and point the suite at it:

```bash
wp core download --version=7.0 --path=/tmp/wp70
cp -r .wp/wp-content/plugins/sqlite-database-integration /tmp/wp70/wp-content/plugins/
sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#/tmp/wp70/wp-content/plugins/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    /tmp/wp70/wp-content/plugins/sqlite-database-integration/db.copy > /tmp/wp70/wp-content/db.php
composer require --dev "wp-phpunit/wp-phpunit:~7.0.0" -W   # don't commit this
WP_CORE_DIR=/tmp/wp70 vendor/bin/phpunit
```

Before a release, also run Plugin Check against the built plugin (CI does it on every push):

```bash
composer zip
wp plugin install plugin-check --activate --path=.wp
# point .wp/wp-content/plugins/ruvelo-editorial at build/ruvelo-editorial, then:
wp plugin check ruvelo-editorial --path=.wp
```

`bin/screenshots.sh` regenerates `.wordpress-org/` (screenshots, banners and icons) from the running demo site; it needs headless Chrome.

## Making a change

1. Branch from `main`.
2. Write a test that fails without your change, in `tests/Feature`.
3. Keep the public surface stable: hook names and arguments, REST routes and shapes, WP-CLI commands, block names and attributes, template file names, link formats and settings keys. If you must change one, say so in the PR.
4. Run `composer format` and `composer check`.
5. Add a line under **Unreleased** in [CHANGELOG.md](CHANGELOG.md).
6. Open the pull request. Screenshots help for anything visual.

## Where things live

```
ruvelo-editorial.php   Plugin header and a tiny PSR-4 loader
src/Review.php, States.php, Queue.php   Review state, the queue and its queries
src/Gate.php           The publish gate and the administrator override
src/Notes.php          The link to core Notes
src/Notifications.php  Grouped emails through WP-Cron
src/Admin/             Queue, review screen, editor panel, settings, widget
src/Rest/, src/Cli/    REST API and WP-CLI
src/Abilities.php      Abilities API registrations
templates/             Emails (themes can override them)
assets/                CSS and plain JavaScript, no build step
demo/                  Halyard's demo content
bin/                   Dev site, zip, screenshots
```

## Style

- `declare(strict_types=1)` everywhere, typed properties and return types.
- No `@phpstan-ignore` or baseline entries: fix the cause. `phpcs:ignore` only with a reason.
- Comments explain *why*, not *what*.
- Every page keeps working with JavaScript off: every action is a plain link or form first.
- No remote requests, no admin notices outside the plugin's own screens, and nobody emailed who didn't ask.
- UI follows the [Ruvelo house style](https://github.com/Ruvelo/.github/blob/main/BRAND.md): CSS variables, no build step, light and dark.

By contributing you agree that your work is released under the [GPL-2.0-or-later license](LICENSE) and that you'll follow the [code of conduct](https://github.com/Ruvelo/.github/blob/main/CODE_OF_CONDUCT.md).
