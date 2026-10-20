---
paths:
    - '**/*.php'
    - '.phpcs.xml'
    - 'phpstan.neon.dist'
    - 'composer.json'
---

# PHP coding standards in effect

Loads when a PHP file or a PHP tooling config is read. `composer lint` runs PHPCS then PHPStan.

- **PHPCS runs `humanmade/coding-standards` (HM)** over `inc/`, `blocks/` and `uat-checklist.php`, against
  `testVersion 8.4-` and `minimum_supported_wp_version 7.1`. `tests/` and `stubs/` are not scanned: the tests
  run as plain scripts outside any namespace and print their own report.
- **Three exclusions, each with its reason in `.phpcs.xml`.** `PSR1.Files.SideEffects` everywhere, because every
  file carries the `defined( 'ABSPATH' ) || exit;` guard. `NamespaceDirectoryName.NoIncDirectory` for the main
  file and `blocks/`, which WordPress finds by name. `DocComment.MissingShort` in `blocks/`, where an inline
  `/** @var */` gives PHPStan a render template's variables.
- **PHPStan level 5** over `inc/`, `blocks/` and the main file, with WordPress from `php-stubs/wordpress-stubs`
  and WP-CLI from `stubs/wp-cli.php`. A new WP-CLI call needs its signature added to that stub.
- **Conditions read subject first**: `$title !== ''`, never `'' !== $title`.
- **`phpcs:ignore WordPressVIPMinimum.*` annotations stay.** They are inert under HM, and the VIP Code Analysis
  Bot reads them once the plugin is installed into a VIP application.

Fix the finding, not the gate. A suppression needs a comment saying why the sniff is wrong for that line.

```sh
composer lint                          # PHPCS, then PHPStan
vendor/bin/phpcs -s path/to/file.php   # show the sniff name for each violation
composer phpcbf                        # auto-fix what PHPCS can; read the diff
```
