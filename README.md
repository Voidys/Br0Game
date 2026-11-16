<div align="center">

# Br0Game

**An open source browser space strategy game.** Build planets, research technologies, send fleets and fight it out
in alliances.

[![CI](https://github.com/Voidys/Br0Game/actions/workflows/ci.yml/badge.svg)](https://github.com/Voidys/Br0Game/actions/workflows/ci.yml)
[![Release build](https://github.com/Voidys/Br0Game/actions/workflows/release.yml/badge.svg)](https://github.com/Voidys/Br0Game/actions/workflows/release.yml)
[![Latest release](https://img.shields.io/github/v/release/Voidys/Br0Game?label=release&color=blue)](https://github.com/Voidys/Br0Game/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/Voidys/Br0Game/total?color=blue)](https://github.com/Voidys/Br0Game/releases)
[![License: AGPL v3](https://img.shields.io/badge/license-AGPL--3.0-green)](LICENSE)
<br>
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony 8.1](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white)](https://symfony.com/)
[![Twig](https://img.shields.io/badge/templates-Twig-8BC34A)](https://twig.symfony.com/)
[![MySQL 8.4 | MariaDB 10.11](https://img.shields.io/badge/MySQL%208.4%20%7C%20MariaDB%2010.11-4479A1?logo=mysql&logoColor=white)](docs/INSTALL.md)
[![Last commit](https://img.shields.io/github/last-commit/Voidys/Br0Game)](https://github.com/Voidys/Br0Game/commits/main)

[**Install guide**](docs/INSTALL.md) · [**Download**](https://github.com/Voidys/Br0Game/releases/latest) ·
[Changelog](docs/CHANGELOG.md) · [Report a bug](https://github.com/Voidys/Br0Game/issues)

</div>

> **Br0Game lives on at GitHub.** It is a fork of [pr0game](https://codeberg.org/pr0game/pr0game), which grew out of
> 2Moons and SteemNova. Codeberg does not allow AI-assisted coding, so the project moved here. Issues, pull requests
> and releases go to this repository.

## Contents

- [Highlights](#highlights)
- [Features](#features)
- [Quick start](#quick-start)
- [Requirements](#requirements)
- [Running a server](#running-a-server)
- [Development](#development)
- [Battle engines](#battle-engines)
- [Tech stack](#tech-stack)
- [Thanks](#thanks)
- [Origin, license and credits](#origin-license-and-credits)

## Highlights

The game rules are the same as before; everything around them is new.

| | |
| --- | --- |
| **81 game bugs fixed** | **3 critical** (a jump gate SQL injection, a repair dock duplication race, a cleaner that deleted every player), **11 high**, **26 medium**, **7 low-medium**, **34 low**. All are documented in [docs/GAMEBUGS.md](docs/GAMEBUGS.md) |
| **Up to 48 % faster pages** | A typical game page went from 40 to 28 queries. The empire view went from 116 to 37 queries and the galaxy from 71 to 33 ([docs/PERFORMANCE.md](docs/PERFORMANCE.md)) |
| **Up to 96 % faster background jobs** | The statistics cronjob went from 4375 to 66 queries, the records build is 96 % faster, and the resource update is 56 % faster |
| **Smaller images** | Static images are served as WebP (53.7 MB → 12.7 MB) |

## Features

- **Modern interface:**
  - top bar with resources and a classic side menu, which becomes a drawer on phones;
  - every in-game page works on a phone;
  - nine themes, popups as modals, clean URLs (`/game` instead of `game.php`).
- **Community:**
  - a built-in board with posts, votes and threaded comments;
  - news with drafts, scheduling and images;
  - a team page managed in the admin panel.
- **Admin panel:**
  - rebuilt with a dashboard, tabs, a maintenance page and full translations;
  - a mini CMS for rules, imprint and privacy texts, and a GDPR privacy policy filled from the game settings;
  - a detailed server information page with health checks, database facts and game statistics.
- **Bots** (optional, off by default):
  - bot players per universe with five default profiles: office worker, jobless, shift worker, lunatic and noob;
  - they play locally with %-rolls and their own personalities, so no two build the same;
  - an optional LLM (OpenAI, Claude, OpenCode Go, DeepSeek, OpenRouter) gives them names, chat and alliance life;
  - set up under **Admin → Bots**.
- **Easy install:**
  - a setup wizard with speed presets, registration and bot options;
  - a command line installer and an install script;
  - a ready-to-upload release ZIP.
- **Privacy and security:**
  - login on Symfony Security, and autoescaping in every template;
  - the installer generates the app secret;
  - Google Analytics and the voting feature were removed.

The full list, step by step, is in the **[changelog](docs/CHANGELOG.md)**.

## Quick start

Pick the way that fits you. **[docs/INSTALL.md](docs/INSTALL.md)** walks through each one, written for non-technical
readers too.

| Way | For whom | You need |
| --- | --- | --- |
| [**ZIP download**](#1-zip-download) | Shared hosting or a simple server, no command line | A web host with PHP 8.4, MySQL/MariaDB and cron |
| [**Install script**](#2-install-script) | A VPS or server with SSH | Git, PHP 8.4, Composer (the script checks and helps) |
| [**DDEV**](#3-ddev-try-it-on-your-pc) | Trying it on your own PC | Docker and DDEV |

### 1. ZIP download

1. Download `br0game-<version>.zip` and its `.sha256` file from the
   [latest release](https://github.com/Voidys/Br0Game/releases/latest). Download only from that page.
2. Check the file:

   ```sh
   sha256sum -c br0game-1.26.0.zip.sha256            # Linux
   shasum -a 256 br0game-1.26.0.zip                  # macOS: compare with the .sha256 file
   certutil -hashfile br0game-1.26.0.zip SHA256      # Windows: compare with the .sha256 file
   ```

3. Upload and extract it. Set your domain's **document root to the `public/` folder**.
4. Create an empty database in your hosting panel.
5. Open `https://your-domain/install/` and follow the wizard. Run it right after uploading: until it is finished,
   anyone who opens the page could set the game up.
6. Add the cron job the last wizard page shows you.

The ZIP is about 340 MB, mostly theme artwork. If your host's file manager can extract archives, upload the ZIP and
unpack it on the server instead of uploading the files over FTP.

### 2. Install script

Download it, read it, then run it. Please don't pipe it into a shell.

```sh
curl -fsSLO https://raw.githubusercontent.com/Voidys/Br0Game/main/install.sh
less install.sh
bash install.sh                 # interactive menu
bash install.sh --help          # all options, e.g. --mode server --dir /var/www/br0game --dry-run
```

The script:
- checks PHP, its extensions and Composer, with install hints for apt, dnf and pacman;
- fetches the latest release and installs the dependencies;
- runs the command line installer or points you to the web wizard.

It never runs `sudo` by itself. You can also run the installer directly:

```sh
php bin/console app:install     # asks for everything, or pass --no-interaction with options (see --help)
```

### 3. DDEV (try it on your PC)

```sh
git clone https://github.com/Voidys/Br0Game.git
cd Br0Game
ddev start
ddev composer install
```

Then open **https://br0game.ddev.site/install/**. DDEV runs the cronjobs by itself.

## Requirements

| | |
| --- | --- |
| PHP | 8.4 with `curl`, `gd`, `intl`, `mbstring`, `mysql` (pdo_mysql), `xml`, `zip`, `opcache` (recommended), `openssl` (for SMTP with TLS) |
| Database | MySQL 8.4 or MariaDB 10.11 |
| Web server | nginx or Apache with the document root on `public/` ([nginx example with WebP delivery](docs/nginx.md)) |
| Cron | One job every minute (see below) |
| Composer | Only for the script and git installs; the release ZIP already contains `vendor/` |
| Battles | The Rust engine needs Linux x86_64 with glibc ≥ 2.30. Everywhere else the PHP engine is used automatically |

## Running a server

- **Cron:** the game's background jobs (statistics, cleanup, bots…) run every minute.

  ```
  * * * * * cd /path/to/br0game && php bin/console app:cron:run --quiet
  ```

- **Health check:** **Admin → Game info** shows ok/warning/error checks for:
  - PHP, its extensions and OPcache;
  - writable folders, pending migrations and cron;
  - the battle engine, HTTPS and mail.
- **Updates:**
  - Git or script installs: `git pull && composer install --no-dev -o`.
  - ZIP installs: back up the database and `config/game.php`, then replace the files except `config/` and `var/`.
  - Database migrations run automatically on the next page load. Details: [docs/INSTALL.md#updating](docs/INSTALL.md#updating).
- **Performance:** OPcache preloading and an authoritative class map are described in
  [docs/PERFORMANCE.md](docs/PERFORMANCE.md).
- **Security:** see the checklist in [docs/INSTALL.md#security-checklist](docs/INSTALL.md#security-checklist):
  - HTTPS on;
  - the document root on `public/`;
  - `config/game.php` kept private;
  - a strong admin password.

## Development

Local development uses [DDEV](https://ddev.readthedocs.io/) (see [Quick start](#3-ddev-try-it-on-your-pc)).

```sh
ddev exec bin/console lint:twig templates                                   # template syntax
ddev exec bin/console lint:container                                        # service container
ddev composer exec php-cs-fixer fix                                         # new code: PER Coding Style
ddev composer exec php-cs-fixer fix -- --config=.php-cs-fixer.legacy.php   # legacy game code: PSR-12
```

- **Testing:** PHPUnit (`tests/Unit` needs no database, `tests/Integration` runs against a dedicated database `db_test`):

  ```sh
  ddev exec vendor/bin/phpunit                        # everything (also: ddev composer test)
  ddev exec vendor/bin/phpunit --testsuite Unit       # no database needed (also: ddev composer test:unit)
  ddev exec vendor/bin/phpunit --filter BuildFunctionsTest
  ```

  - The test database name must end in `_test` (`.env.test`, `DATABASE_URL`); the tests refuse to run against anything else, so the dev database `db` is never touched. DDEV's `db` user may need `GRANT ALL ON db_test.*`; DDEV's root user can do that (`ddev mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS db_test; GRANT ALL ON db_test.* TO 'db'@'%';"`).
  - Every run rebuilds the schema of `db_test` from `install/install.sql` and the migrations; each test runs in a rolled-back transaction.
  - The mechanics tests compare against golden values captured from the code before the legacy renewal (prices, build times, production, queues, fleet maths, battles). A failing golden test means a result changed; only update the value (`GOLDEN_CAPTURE=1 vendor/bin/phpunit --filter <Class>` prints the new values to `var/golden/`) when the change is intended.
  - The Rust battle engine is checked against the PHP engine with the same seed (`mt_srand` / `BattleEngineRust::useSeed()`): results must be identical field by field, on the golden fleets and on 300 generated battles (`BATTLE_PARITY_RUNS=5000` for a longer soak). `sh tools/battlerust/build.sh test` runs the crate's own tests (RNG against PHP sequences, engine rules).
- Errors land in `var/log/`. After changing translations or game data, clear `var/game/`.
- Template and escaping rules are in [docs/templates.md](docs/templates.md).
- **CI** ([`ci.yml`](.github/workflows/ci.yml)) runs on every push to `main` and on every pull request:
  - composer validate;
  - PHP syntax, Twig, YAML and container lint;
  - the PHPUnit suite against a MySQL 8.4 service container (`db_test`);
  - the Rust battle engine: `cargo test`, a rebuild of `bin/libbattlerust.so` (must equal the committed sha256) and the battle parity tests against the fresh library;
  - `bash -n` and shellcheck for the install scripts.
- **Releases** ([`release.yml`](.github/workflows/release.yml)):
  - bump `install/VERSION` (year scheme `1.<yy>.<patch>`, e.g. `1.26.0`) and push a tag `v1.26.0`;
  - the workflow builds the ZIP with `tools/release/build.sh` and attaches it with its SHA256 to the GitHub release.
- **Bugs and ideas:** [GitHub issues](https://github.com/Voidys/Br0Game/issues). Pull requests are welcome.

## Battle engines

The game uses the first engine that is available:

- **Rust (default):**
  - 40–110× faster than the PHP engine for medium and large fights, and it fights exactly like the PHP engine (same rules, same random draws: with the same seed the result is identical);
  - runs through the bundled launcher `bin/battlerust`, which loads `bin/libbattlerust.so`, so there's nothing to install and no PHP extension;
  - Linux x86_64 with glibc 2.30 or newer (Ubuntu 20.04+, Debian 11+, RHEL/Alma/Rocky 9+);
  - the source is in [`tools/battlerust/`](tools/battlerust) (Rust, builds in Docker, nothing else needed): `sh tools/battlerust/build.sh` rebuilds `bin/libbattlerust.so` reproducibly, `build.sh test` runs the crate tests, `build.sh check` compares a fresh build with the committed library; formats and details in [docs/battle-engine-contract.md](docs/battle-engine-contract.md);
  - rebuild the launcher with `sh tools/battlerust-cli/build.sh`.
- **PHP (fallback):**
  - always available, and used on other systems (ARM, Alpine/musl, older distributions);
  - very large fights (over 500 000 units) are computed on proportionally scaled unit counts;
  - also takes over when the Rust engine refuses a fight (over 60 million ships) or fails.

## Tech stack

| Part | Technology |
| --- | --- |
| Backend | PHP 8.4, Symfony 8.1 (routing, Security login, sessions, DI container, console), the game logic in `src/Game/` |
| Templates | Twig with autoescaping (`templates/{login,game,account,admin,install}`) |
| Frontend | Plain CSS in cascade layers and native ES modules (`public/styles/`, `public/scripts/`), no jQuery, no CSS framework |
| Themes | Nine skins in `public/styles/theme/`; they only set colours and artwork, the layout is shared |
| Database | MySQL 8.4 or MariaDB 10.11; schema in `install/install.sql`, changes as migrations in `install/migrations/` |
| Charts | Reduced Chart.js 4 build (`public/scripts/lib/chart.js`, rebuild with `sh tools/chartjs/build.sh`) |
| Translations | `translations/<domain>.<locale>.yaml` (German and English complete, several others) |

## Thanks

**Thanks to everyone who worked on the earlier versions of this game: all maintainers and contributors so far, the
pr0game dev team, the 2Moons and SteemNova developers and everyone before them. This codebase exists because of years
of their work.**

Why this update: a few friends wanted to play the classic version again, but with a modern touch. This update (the
Symfony and Twig port, new interface, bug fixes, performance work) was made with the help of Claude, because I had
resources left on my weekly usage.

## Origin, license and credits

Based on [2Moons](https://github.com/jkroepke/2Moons), [SteemNova](https://github.com/steemnova/steemnova) and
[pr0game](https://codeberg.org/pr0game/pr0game). Licensed under the [AGPL v3](LICENSE).

**Copyright**
- SteemNova is a fork of the open source browser game framework jkroepke/2Moons. pr0game is a fork of SteemNova, and
  Br0Game is a fork of pr0game.
- Code copyright 2009-2016 Jan-Otto Kröpke, released under the MIT License.
- Code copyright 2018 @steemnova, released under the MIT License.
- Code copyright 2018-2020 @IntinteDAO, released under the MIT License (f66f0bf9f2d502f9cc3932e7f05e4795b3622ce6).
- Code copyright 07.05.2020-2020 @IntinteDAO, released under the AGPLv3 License.
- Code copyright 2022-2026 the pr0game dev team and community ([codeberg.org/pr0game/pr0game](https://codeberg.org/pr0game/pr0game)), released under the AGPLv3 License.
- Code copyright 2026 the Br0Game contributors, released under the AGPLv3 License.

**Credits**
- 2026 Br0Game - Br0Game contributors
- 2022-2026 pr0game - pr0game dev team and community
- 2018-2020 SteemNova - @steemnova, @IntinteDAO
- 2009-2019 2Moons Browsergame Engine - jkroepke
- 2008-2014 XGProjecto - lucky
- 2008, 2009 XNova "XNova 0.9a" - XNova Group / xnova.fr
- 2006-2007 UGamela 0.4 - Phoscur
- 2004-2006 UGamela 0.2h - Perberos
