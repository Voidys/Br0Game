# Br0Game

An open source browser space strategy game: build planets, research technologies, send fleets and fight it out in
alliances. Br0Game is a fork of [pr0game](https://codeberg.org/pr0game/pr0game), which grew out of 2Moons and
SteemNova. The game mechanics are unchanged; the code underneath has been modernised.

> **Br0Game lives on at GitHub: [github.com/Voidys/Br0Game](https://github.com/Voidys/Br0Game).**
> Codeberg does not allow AI-assisted coding, so the project moved here. Issues, pull requests and releases go to this
> repository.

## A word of thanks

**Thanks to everyone who worked on the earlier versions of this game: all maintainers and contributors so far, the
pr0game dev team, the 2Moons and SteemNova developers and everyone before them (see the credits below). This codebase
exists because of years of their work.**

Why this update: a few friends wanted to play the classic version again, but with a modern touch.

This update (Symfony and Twig port, new interface, bug fixes, performance work) was made with the help of Claude,
because I had resources left on my weekly usage.

## What's inside

| Part | Technology |
| --- | --- |
| Backend | PHP 8.4, Symfony 8.1 (routing, Security login, sessions, DI container, console), the game logic in `src/Game/` |
| Templates | Twig with autoescaping (`templates/{login,game,account,admin,install}`, rules in [docs/templates.md](docs/templates.md)) |
| Frontend | Plain CSS in cascade layers and native ES modules (`public/styles/`, `public/scripts/`), no jQuery, no CSS framework |
| Themes | Nine skins in `public/styles/theme/`; they only set colours and artwork, the layout is shared |
| Database | MySQL 8.4 or MariaDB 10.11; schema in `install/install.sql`, changes as migrations in `install/migrations/` |
| Battles | Rust engine with a PHP fallback (see below) |
| Charts | Reduced Chart.js 4 build (`public/scripts/lib/chart.js`, rebuild with `sh tools/chartjs/build.sh`) |
| Translations | `translations/<domain>.<locale>.yaml` (German and English complete, several others) |

Further reading in `docs/`: [CHANGELOG](docs/CHANGELOG.md), [game bug review](docs/GAMEBUGS.md),
[performance](docs/PERFORMANCE.md), [nginx setup](docs/nginx.md), [templates](docs/templates.md).

## What's new compared to pr0game

Same game rules, new everything around them. The full list, step by step, is in the
**[changelog (docs/CHANGELOG.md)](docs/CHANGELOG.md)**.

* **Interface:** top bar with resources and a classic side menu (a drawer on phones), every in-game page usable on a
  phone, tidy tables and forms in all nine themes, reworked messages page, clean URLs (`/game` instead of `game.php`).
* **Community:** a built-in board in the account area (posts, votes, threaded comments) instead of an external forum,
  news with drafts, scheduling and images, a team page managed in the admin panel.
* **Operators:** rebuilt admin panel (21 menu entries with tabs, dashboard, maintenance page, fully translated), a mini
  CMS for rules, imprint and privacy texts, a GDPR privacy policy filled from the game settings, a setup wizard for fresh
  installs, a default theme and game name setting, start page with hourly memes from `public/memes/`.
* **Fixes:** 83 game logic bugs found and fixed, among them a jump gate SQL injection, a repair dock duplication race and
  the cleaner deleting every player ([docs/GAMEBUGS.md](docs/GAMEBUGS.md)).
* **Speed:** fewer queries per page, the statistics cron 7× faster, static images as WebP (53.7 MB → 12.7 MB,
  `bin/console app:images:webp`), the Rust battle engine actually in use again ([docs/PERFORMANCE.md](docs/PERFORMANCE.md)).
* **Bots (optional, off by default):** bot players per universe with five default profiles (office worker, jobless,
  shift worker, lunatic, noob). They play locally with %-rolls and their own personalities, so no two build the same. An
  optional LLM (OpenAI, Claude, OpenCode Go, DeepSeek, OpenRouter) handles names, chat and alliances. All of it is set
  up under Admin → Bots.
* **Privacy and security:** login on Symfony Security, autoescaping in every template, Google Analytics and the voting
  feature removed.

## Installing

Three ways, described step by step in **[docs/INSTALL.md](docs/INSTALL.md)** (written for non-technical readers too):

* **ZIP download** (shared hosting, no command line): get `br0game-<version>.zip` and its `.sha256` from the
  [Releases page](https://github.com/Voidys/Br0Game/releases), upload it, point the document root to `public/` and open
  `/install/`.
* **Install script** (VPS with SSH): `curl -fsSLO https://raw.githubusercontent.com/Voidys/Br0Game/main/install.sh`,
  read it, then `bash install.sh`.
* **DDEV** (try it on your PC): `git clone https://github.com/Voidys/Br0Game.git && cd Br0Game && ddev start && ddev composer install`,
  then open https://br0game.ddev.site/install/.

Requirements: PHP 8.4 (cli, fpm, curl, gd, intl, mbstring, mysql, opcache, xml, zip; openssl if mail goes through SMTP
with TLS), MySQL 8.4 or MariaDB 10.11, Composer (not for the ZIP), cron. The web server's document root is `public/`
(nginx example including WebP delivery: [docs/nginx.md](docs/nginx.md); OPcache preloading:
[docs/PERFORMANCE.md](docs/PERFORMANCE.md)). The game's jobs run through `bin/console app:cron:run`, called every minute:

```
* * * * * cd /path/to/br0game && php bin/console app:cron:run --quiet
```

DDEV does this by itself (a small loop daemon in `web_extra_daemons`, `.ddev/config.yaml`). phpMyAdmin is an add-on:
`ddev add-on get ddev/ddev-phpmyadmin && ddev restart`. Release ZIPs are built by `tools/release/build.sh` (GitHub
workflow on `v*` tags).

## Development with DDEV

Useful commands:

```sh
ddev exec bin/console lint:twig templates                  # template syntax
ddev composer exec php-cs-fixer fix                        # new code: PER Coding Style
ddev composer exec php-cs-fixer fix -- --config=.php-cs-fixer.legacy.php   # legacy game code: PSR-12
```

Errors land in `var/log/`. After changing translations or game data, clear `var/game/`.

## Battle engines

The game uses the first engine that is available:

* **Rust (default):** 40–110× faster than the PHP engine for medium and large fights. Runs through the bundled launcher
  `bin/battlerust`, which loads `bin/libbattlerust.so`; nothing to install, no PHP extension. Linux x86_64 with glibc
  2.30 or newer (Ubuntu 20.04+, Debian 11+, RHEL/Alma/Rocky 9+). Rebuild the launcher with
  `sh tools/battlerust-cli/build.sh` (throwaway Ubuntu 20.04 container, no local compiler needed).
* **PHP (fallback):** always available, used on other systems (ARM, Alpine/musl, older distributions). Very large fights
  (over 500 000 units) are computed on proportionally scaled unit counts so they fit into memory.

## Origin, license and credits

Based on:
* [2Moons](https://github.com/jkroepke/2Moons)
* [SteemNova](https://github.com/steemnova/steemnova)
* [pr0game](https://codeberg.org/pr0game/pr0game)

The project is licensed under the [AGPL v3](LICENSE).

Copyright
* SteemNova is a fork of the open source browser game framework jkroepke/2Moons.
* Code copyright 2009-2016 Jan-Otto Kröpke, released under the MIT License.
* Code copyright 2018 @steemnova, released under the MIT License.
* Code copyright 2018-2020 @IntinteDAO, released under the MIT License (f66f0bf9f2d502f9cc3932e7f05e4795b3622ce6).
* Code copyright 07.05.2020-2020 @IntinteDAO, released under the AGPLv3 License.

Credits
* 2009-2019 2Moons Browsergame Engine - jkroepke
* 2008-2014 XGProjecto - lucky
* 2008, 2009 XNova "XNova 0.9a" - XNova Group / xnova.fr
* 2006-2007 UGamela 0.4 - Phoscur
* 2004-2006 UGamela 0.2h - Perberos
