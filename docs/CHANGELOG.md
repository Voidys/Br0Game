# Changelog: migration to PHP 8.4, Symfony 8.1 and Twig

Technical changes per migration step, newest last.

## 1. PHP 8.4 + MySQL 8.4 (`74d81b37`)

- DDEV: PHP 8.2 → 8.4, MySQL 8.0 → 8.4, extra packages `php8.4-imap/opcache/ds`.
- Explicit nullable types for parameters defaulting to null (`BattleHallFilter`, `PlayerUtil::createPlayer`, `ResourceUpdate::add/removeResources`).
- Removed deprecated `E_STRICT` from the error type map (`GeneralFunctions.php`).
- Registered PHP functions used in templates as Smarty modifiers (`pretty_fly_time`, `allowedTo`, `array_key_exists`, `array_filter`, `range`, `intval`, `min`, `max`, `date`).
- Removed the unused `.config/` lint configs.

## 2. Symfony 8.1 kernel with legacy bridge (`c2e5bd79`)

- Added Symfony 8.1 (FrameworkBundle, Twig 3, Doctrine DBAL 4/ORM 3, Monolog) via Flex next to the existing code.
- Document root moved to `public/` (DDEV config and `.ddev/apache/pr0game.conf`); the rest of the repository is no longer web-reachable.
- `public/index.php` as the single entry point; a bridge handed unported URLs to the old entry scripts.
- Legacy asset dirs symlinked into `public/` (`styles`, `scripts`, `favicon.ico`, `robots.txt`, `battlesim.html`).
- `App\` and legacy `TwoMoons\` share `src/`; legacy dirs excluded from service discovery.
- `DATABASE_URL` and `TABLE_PREFIX` in `.env`; `/healthz` smoke route (Twig + DBAL).
- Upgraded `symfony/yaml`, `symfony/var-dumper` and `php-cs-fixer` for Symfony 8; cs-fixer config moved to `.php-cs-fixer.dist.php`.

## 3. Shared login, locale and routing (`9c2cd0f9`)

- Symfony uses the game's native session (`2Moons`, `cache/sessions`); `LegacySessionAuthenticator` authenticates from it (stateless firewall).
- Account roles from `accounts.authlevel` (`ROLE_MODERATOR`/`OPERATOR`/`ADMIN` with a hierarchy).
- Legacy language packs (`language/<locale>/*.yaml`) registered as translator domains; default locale `en`, fallback `de`.
- `LocaleSubscriber`: `?lang=` > account language > `lang` cookie > Accept-Language > default.
- Services `GameConfig`, `LanguagePacks`, `TableNames`; Twig helpers `game_config()`, `languages()`, `asset_version`.
- Login layout and rules page ported to Twig (`/rules`); old `index.php?page=rules` redirects (308).

## 4. Smarty replaced by Twig (`80408618`)

- All 218 Smarty templates converted to Twig in `templates/legacy/<area>/`.
- `TwoMoons\Core\Template` is a Twig adapter with the former API (`assign_vars`, `show`, `display`, `fetch`, `message`).
- Child templates extend `layout_template`; layout includes that define blocks are inlined.
- `TemplateExtension` keeps PHP semantics (`empty`, `isset`, foreach casting, constants, `html_options`).
- `TemplateLanguage` renders missing translation keys as the key name (Smarty behaviour).
- Removed `smarty/smarty` and `styles/templates/`.

## 5. Entry points as Symfony controllers (`6eea66b2`)

- `GameEntryController` serves `/`, `/index.php`, `/game.php`, `/admin.php`, `/account.php`, `/cronjob.php`, `/CombatReport.php` (also with `/uniN/`), plus the installer, voting and wiki SSO scripts.
- Root entry scripts deleted; `LegacyKernel` (bootstrap) and `PageDispatcher` (`?page=`/`&mode=` dispatch) replace them.
- All `exit`/`die` in game code replaced by `throw new EndOfRequest()`; `LegacyResponseFactory` turns output, native headers and status into a Response.
- Game routes run outside the Symfony firewall (security.yaml `legacy`).
- `Template::loadFilter('output', 'trimwhitespace')` ported (spy reports); fixed the ajax layouts extending themselves.
- Removed the root `.htaccess` and `index.html`; `nginx.md` and README updated for the `public/` docroot.

## 6. Template helpers in Symfony Twig (`8490791c`)

- `TemplateExtension` registered with the Symfony Twig environment; `bin/console lint:twig templates` covers the game templates.
- Global game helpers are called late-bound, so templates compile without bootstrapping the game.

## 7. Bootstrap class and DB settings from .env (`808c5d4c`)

- `includes/common.php` → `App\Legacy\GameBootstrap::run()` (same steps, same order); the kernel, installer, voting and PHP migrations call it.
- `TwoMoons\Core\DatabaseConfig`: game DB connection and table prefix from `DATABASE_URL`/`TABLE_PREFIX`, with `includes/config.php` as fallback (installer, CLI).

## 8. Cron command and CLI migrations (`7b494e9d`)

- `bin/console app:cron:run [--job=ID]` runs due game cronjobs (same locking/rescheduling as the in-game `cronjob.php` trigger, which stays).
- `LegacyKernel::bootCli()`: CLI bootstrap without session/user (game data, default language, universe config).
- Installer runs PHP migrations through the PHP CLI instead of an HTTP request to the (no longer web-reachable) project root.
- PHP migrations call `GameBootstrap::run()` instead of `includes/common.php`.

## 9. Page controllers base + PER-CS

- `GamePage` route attribute: original URLs (`index.php?page=x`, case-insensitive, `/uniN/` variant) as real Symfony routes with priority over the generic entry route.
- `GameControllerInterface` + `GameControllerListener`: boot the game for the controller's area and collect results via `LegacyResponseFactory` (now also accepts returned Responses + native headers).
- `AbstractLoginController`: `render()`, `printMessage()`, `redirectTo()`, `sendJSON()` with the layout data of the former `AbstractLoginPage`.
- Pilot: `ShowNewsPage` → `Login\NewsController` (output identical).
- Added `symfony/expression-language` (route conditions).
- Code style: new code PER-CS (`.php-cs-fixer.dist.php`), legacy code PSR-12 (`.php-cs-fixer.legacy.php`).

## 10. Login area ported to controllers

- Pattern: page class → controller with class-level `#[GamePage]`; base controllers generated from the legacy `Abstract*Page` (constructor work → `initialize()`), `PageModeDispatch` resolves `?mode=` only to methods of the concrete controller.
- `display()`/`printMessage()`/`redirectTo()`/`sendJSON()` keep their request-ending semantics (`EndOfRequest`, may carry a Response) so control flow stays identical.
- All login pages ported (`App\Controller\Login\*`); legacy `ShowRulesPage` dropped (native `/rules`).
- `$requireModule = MODULE_*` stored as constant name and resolved after bootstrap (routing loads classes before the game constants exist).
- Side effect: `?page=hof` (lowercase) now works; the file/class case mismatch used to break it on Linux.

## 11. Account area ported to controllers

- All account pages ported (`App\Controller\Account\*`); `DataPrivacyController` (login base) keeps the ACCOUNT mode under `account.php`.
- Legacy `AbstractLoginPage`/`AbstractAccountPage` removed; unknown pages answered by the area error controller.

## 12. Game area ported to controllers

- All 51 game pages ported (`App\Controller\Game\*`, 102 routes incl. `/uniN/`); legacy `AbstractGamePage` removed.
- `AllianceController` keeps the former second page construction (`PlayerCardController` + `initialize()`), `GalaxyRows` uses `PhalanxController::allowPhalanx()`.
- Typed `static int $requireModule` handled like the untyped ones (constant name, resolved late).

## 13. Admin area ported, legacy pages gone

- All admin pages ported (`App\Controller\Admin\*`); `AbstractAdminController::initialize()` = former admin.php checks, then `construct()` (former page constructor, base = permission check by former class name).
- `AccountDataController` keeps its former lack of a per-page permission check; unreachable `ShowVertify.php` removed.
- `src/Pages/` and `PageDispatcher` removed; `GameEntryController` only answers unknown pages, cron, CombatReport and the standalone scripts.

## 14. Root cleanup

- Removed dead tooling: `.vscode/`, `.drone.yml`, `.gitlab-ci.yml`, `docker-compose.yml`, `tests/run.js`.
- Removed obsolete leftovers: `logs/`, `cache/.htaccess`, `.phpcs-cache`.
- `public/stats` symlink: the stats JSON export (`MODULE_STATS_JSON`) is web-reachable again after the `public/` docroot move.

## 15. Translations in Symfony format

- `language/<locale>/<DOMAIN>.yaml` → `translations/<domain>.<locale>.yaml` (101 files); Symfony loads them natively (`debug:translation`, `lint:yaml` work).
- `LanguageBuildCache` builds the game language cache from `translations/` (same cache keys, e.g. `en-L18N`).
- `LegacyTranslationsPass` → `AvailableLocalesPass` (only `app.locales` from `language/*/LANG.cfg`).
- `language/` keeps the pack metadata (`LANG.cfg`), text templates (`templates/*.txt`) and `bsim.json`.
- Battle simulator texts (`/language/<lang>/bsim.json`, loaded by the browser) reachable again via per-file symlinks in `public/language/` (broken since the `public/` docroot move); `LANG.cfg` stays private.

## 16. language/ dissolved

- `language/<locale>/LANG.cfg` → `config/languages.yaml` (lossless conversion); read via `Language::getPacks()`, `LanguagePacks`, `AvailableLocalesPass`, `LanguageBuildCache`.
- Text templates `language/<locale>/templates/*.txt` → `templates/texts/<locale>/*.txt` (still plain text with sprintf placeholders).
- `bsim.json` → `public/language/<lang>/bsim.json` (same URL, real files instead of symlinks).
- Language lists are now in alphabetical order (formerly filesystem order).

## 17. Docs and web root files

- `nginx.md` → `docs/nginx.md`.
- `robots.txt` → real file in `public/` (was a root file + symlink); dropped its rules for `/includes/` and `/cache/`, which are not web-reachable anymore.

## 18. Composer cleanup

- Removed unused `doctrine/orm` (+ collections, inflector, instantiator, lexer), `symfony/apache-pack`, `symfony/polyfill-php86`; ORM config and `src/Entity` dropped (only DBAL is used).
- `doctrine/dbal` required explicitly; `symfony/var-dumper` moved to require-dev.
- Exact pins relaxed: `erusev/parsedown ^1.7.2` (→ 1.8.0), `phpmailer/phpmailer ^6.9.1` (→ 6.12.0); `composer validate` clean, no audit advisories.
- Locked production packages: 71 → 64.
- `battlesim.html` (static TrashSim page) → real file in `public/` (was a root file + symlink).

## 20. Runtime data in var/

- `cache/` → `var/game/` (`CACHE_PATH`: game data and language caches, Twig cache, blocklist) and `var/sessions/` (native sessions, shared by game and Symfony).
- Updated `Session`, `framework.session`, `Language`, `LanguageBuildCache`, `ClearCache()`, the installer writable-dir check; `GameBootstrap` creates the dirs; Smarty leftover `cache/templates/` dropped.

## 21. Static assets in public/

- `styles/` and `scripts/` moved into `public/` as real directories (were symlinks); URLs unchanged.
- Disk reads adjusted: `Theme` (style.cfg/settings.cfg/theme list), `ScreensController` (screenshot listing); `ttf_file` default path updated.
- Theme `*.cfg` files are denied by the web server (`public/.htaccess`, `docs/nginx.md`).
- AssetMapper/Encore deliberately not used: ~4,900 files / 377 MB of mostly runtime-built image paths, cache-busting already via `?v=<commit>`.

## 22. Core port, phase 1: namespace

- `src/{Achievements,AdvancedStats,BattleEngine,Cache,Core,Cronjob,Missions,Models,Tutorials}` → `src/Game/*`, namespace `TwoMoons\*` → `App\Game\*` (230 files, tokenizer-based; string-built class names in Cache, TutorialUtil, Cronjob and path refs updated).
- Composer autoload: only `App\` → `src/`; `src/Game/` excluded from service discovery.
- Existing sessions are invalidated once (serialized `Session` class renamed).

## 23. Core port, phase 2: one database connection

- `App\Game\Core\Database` uses the PDO of Symfony's DBAL connection (`Database::usePdo()`, wired in `LegacyKernel`); standalone connect only outside Symfony (CLI migration scripts).
- DBAL runs the game's connection setup (`Database::INIT_COMMAND`: utf8mb4, `sql_mode = STRICT_ALL_TABLES`) via `doctrine.yaml` options.
- Verified: game and DBAL report the same connection id, sql_mode and charset.

## 24. Core port, phase 3: includes/ dissolved

- Code → `src/Game/Support/`: `constants.php`, `functions.php` (was GeneralFunctions), `tables.php` (dbtables), `vars.php`, `fleet_events.php` (FleetHandler), `julia.php`, `planet_data.php`.
- Config → `config/game.php` (installer-written, gitignored) + `config/game.sample.php`.
- Runtime → `var/log/game.log` (was includes/error.log), `var/backups/`, `var/ENABLE_INSTALL_TOOL`, `var/FIRST_INSTALL` (installer, `ddev installtool`, install translations updated).
- Installer writable-dir check now covers `var/backups/`, `var/log/`, `config/`.

## 25. Voting removed

- Removed the voting feature: `voting.php` callback (+ route, firewall pattern), `VotingController` + template, navigation menu entry and its per-page voting query in `AbstractGameController`, `voting-refresh.js`, bootstrap `VOTING` mode, `VOTING`/`USERS_VOTING` table names, `AdvancedStatsEnum::VOTING_POINTS`, `$apikeys` in the config sample.
- Removed the TCG token setting (only used for voting rewards) from settings page + save.
- Removed the voting/TCG translation keys (ingame, all locales; verified no other key changed).
- Database tables `voting_sites`/`users_voting` and `users_settings.tcg_token` are left untouched (no destructive migration).
- Kept: the archived Uni 7 Hall of Fame entry "Voting Points" (historical content).

## 26. Wiki SSO resolver as Symfony controller

- `internal_sso_resolve.php` → `App\Controller\WikiSsoResolveController` (same URL, token check, statuses and JSON); settings via `App\Legacy\GameSettings` (reads `config/game.php`).
- Added the missing `App\Game\Core\WikiSsoCodeStore` (never existed upstream, so the SSO could not run): one-time codes on Symfony Cache (file in `var/game/wiki_sso/` or Redis), TTL 120 s.
- Verified end to end: wrong token 403, valid code 200 (account id/name), reuse 404, malformed 400, unconfigured 503.

## 27. Installer as class

- `install/index.php` → `App\Legacy\Installer::run()` (logic unchanged, re-indented; imports for global `Exception`/`PDO` added so catches keep working), served at `/install/` by `GameEntryController::install()` via `LegacyKernel::boot()` (INSTALL, or UPGRADE for `?mode=upgrade`).
- `install/` now only holds data (`install.sql`, `migrations/`, `VERSION`); `LegacyKernel::runScript()` removed (no standalone scripts left).

## 28. Game templates on Symfony Twig

- `App\Game\Core\Template` renders with Symfony's Twig environment (`Template::useEnvironment()`, wired in `LegacyKernel`); standalone environment only as CLI fallback. Compiled templates now live in Symfony's cache (`var/cache/<env>/twig`).
- Template areas are Twig namespaces (`@game`, `@login`, `@adm`, `@account`, `@install`, `twig.yaml`); includes in `templates/legacy/` namespaced, so no per-render loader switching.
- `App\Twig\EscapingStrategy` (`autoescape_service`): no autoescaping for game templates under any name (`@area/…`, `legacy/…`, file path), default strategy for the rest; `strict_variables: false`.
- Verified: crawl status + HTML identical (no added escaping).

## 29. Cron on Symfony Scheduler

- `App\Schedule` (Symfony Scheduler): every minute `RunDueCronjobs` → handler starts `bin/console app:cron:run` in a fresh process (the game fixes its time/state per process).
- Worker `bin/console messenger:consume scheduler_default`; DDEV runs it as `web_extra_daemons` entry, README documents production setup.
- Removed the legacy trigger: `cronjob.php` route + firewall pattern, the trigger images in the game layout (`getCronjobsTodo()`); CLI bootstrap now presents itself as `/index.php`.
- Added `symfony/scheduler`, `symfony/messenger`, `symfony/process`.
- Verified: the daemon picks up due jobs and reschedules them; crawl status identical, no trigger image in pages.

## 30. Game core in the container

- `Database`, `Config` and the game state (`Singleton`) are container services (factories over the static accessors, so game code and Symfony share the same instances; `Database`/`Config` lazy because they need the bootstrap).
- Cronjob tasks are DI services (tag `app.game_cronjob`); `Cronjob::execute()` resolves them through a service locator (`LegacyKernel` → `Cronjob::useTaskLocator()`), `new` only as fallback outside Symfony.
- `DailyCronJob`/`ReferralCronJob` renamed to match their files and the stored names (`DailyCronjob`, `ReferralCronjob`).
- Verified: scheduler daemon and admin "run" execute jobs through the container.

## 31. Core DI, phase 1: cronjob tasks

- New `ConfigRegistry` service (`get($universe)`) and `AbstractCronjobTask` (constructor-injected `Database`, `ConfigRegistry`, game state); all 9 tasks extend it and use `$this->db` / `$this->configs` / `$this->state` instead of static accessors.
- `InactiveMailCronjob` now implements the task interface via the base (it never did); `PirateSpawnerCronjob` lost its duplicate `$db` property.
- Verified: all 9 tasks executed through the command without errors.

## 32. Core DI, phase 2: page controllers

- `App\Controller\GameServices` trait (setter injection via `#[Required]`) in all four base controllers: `$this->db`, `$this->configs`, `$this->state`.
- 831 static accessor calls in the page controllers replaced (tokenizer-based, only where `$this` exists); 8 remain in static helper methods (phase 4).
- Controllers created with `new` get the services of their creator (`withGameServicesOf()`); `GameEntryController` injects them for the admin error page; static `printError()` entry points use the shared instances.
- Verified: crawl status identical, HTML differences only live data.

## 33. Core DI, phase 3: fleet processing and missions

- `FlyingFleetHandler` is a container service (constructor-injected `Database`, `ConfigRegistry`, game state); `fleet_events.php` gets it via `GameBootstrap::fleetHandler()` (wired by `LegacyKernel`).
- `MissionFunctions` and all 16 mission classes receive the services through their constructors (created by the handler); 97 static accessor calls replaced.
- Mission map `$missionObjPattern` → `FlyingFleetHandler::missionClasses()` (the MISSION_* constants only exist after bootstrap, the container loads the class earlier).
- Verified: transport dispatch, arrival (messages) and return (ships back) processed through the injected handler; crawl status identical.

## 34. Core DI, phase 4: battle engines

- `BattleHandler(bool $sim, ConfigRegistry, Singleton)` and `AbstractBattleEngine` (constructor-injected `ConfigRegistry` + game state); the handler builds the engines with them, missions and the simulator pass their own services; 28 static accessor calls replaced.
- Verified: battle simulation + report.

## 35. Core DI, phase 5: core instance classes

- `ResourceUpdate`, `Statbuilder`, `GalaxyRows`, `FlyingFleetsTable`, `MarketManager`, `AdvancedStatsHandler`, `SQLDumper`, `ExpeditionDeckHandler`, `SupportTickets`, `Template`, `TutorialUtil` + tutorial supports take `Database`, `ConfigRegistry` and game state as promoted constructor params; ~100 call sites pass their own services, static contexts (`functions.php`, `Installer`, `Template::printMessage`) fall back to the static accessors; 118 static accessor calls replaced by injected properties.
- `BattleHandler` also takes `Database` (fixes the steal/stats writes after phase 4).
- Verified: crawl unchanged, lint:container, lint:twig, cron run, mechanics run.

## 36. Core DI, phase 6: static utilities become objects

- `PlayerUtil`, `FleetFunctions`, `BuildFunctions`, `AccountUtil`, `ExpeditionFindableShipsHandler`, `Achievement`, `SpyReport` and the `Achievements/*` checkers: static methods → instance methods on the injected services, `X::fromGlobals()` for static contexts.
- New `GameUtilities` trait: lazy `$this->playerUtil()`, `$this->fleetFunctions()`, … built from the host's `db`/`configs`/`state`; used by controllers, missions, cronjob tasks, tutorials and core classes; ~590 call sites rewritten, ~180 static accessor calls inside the utilities replaced.
- `Discord` stays static on purpose (error-reporting sink).

## 37. Core DI, phase 7: leftovers

- Battle engines get an injected `Database` (stats writes no longer reach for the static accessor).
- Phalanx rules move from the phalanx page into `FleetFunctions::allowPhalanx()`/`getPhalanxRange()` (galaxy view no longer calls a controller statically); search list and jumpgate cooldown helpers become controller instance methods (`_getSearchList` private, out of `?mode=` reach).
- `ResourceUpdate::getProd()`/`getNetworkLevel()`, `HangarFilters` and `BattleHallFilter` run on injected services; base controllers and `PageModeDispatch` use their own services instead of `fromGlobals()`.
- Static on purpose (bootstrap boundary): the `Database`/`Config`/`Singleton` accessors themselves, `GameBootstrap`, `LegacyKernel`, `Installer`, `functions.php` + support files, `Session`, `Log`, `Mail`, `Language`, `Discord`, `ErrorController::printError()`, `Template::printMessage()`.

## 38. Core DI, phase 8: bootstrap, installer, global helpers

- `GameBootstrap` and `Installer` are container services with injected `Database`/`ConfigRegistry`/game state; `LegacyKernel` and `GameEntryController` get them injected (`$this->bootstrap->run()`, `$this->installer->run()`), both accessor-free; installer's post-config connection test forces the lazy DB service.
- Game rules out of `functions.php`: `getLanguage`, `getPlanets`, `getMainPlanet`, `CalculateMaxPlanetFields`, `CheckNoobProtec` → `PlayerUtil`; `ClearCache`, `listMigrations`, `checkDBUpgrade` → new `Maintenance` class; the globals stay as one-line wrappers, callers with services use `$this->playerUtil()`/`$this->maintenance()`.
- Left global by design: Twig/presentation helpers (`pretty_number`, `pretty_time`, `shortly_number`, date formatting, `message`, `allowedTo`, `isModuleAvailable`) and the PHP error/exception handlers.

## 39. Core DI, phase 9: view helpers, session, errors, mail/log/language, fleet events

- `ViewHelpers` (injected; `pretty_number`, `pretty_time`, `shortly_number`, date formatting, `message`, `allowedTo`, `isModuleAvailable`): Twig binds them via `TemplateExtension(ViewHelpers)`, PHP via `$this->viewHelpers()`; the globals remain as one-line wrappers.
- `SessionManager` replaces the static side of `Session` (init/create/load/client IP); the current session lives in the game state, `Session` objects get the services attached after create/unserialize (not serialized).
- `ErrorReporter` service = former `exceptionHandler()`/`errorHandler()`: registered by `GameBootstrap`, injected into `LegacyResponseFactory`; `Discord` and `Mail` are instance classes (Discord without DB, so it still reports when the DB is down).
- `Log`, `Language` (now `ConfigRegistry`-injected; pack metadata → dependency-free `LanguageCatalog`), `Cronjob` (container service with the task locator; `app:cron:run` gets it injected), `ExpeditionDeckHandler::updateDeckConfig()`, error pages (`showError()` instead of static `printError()`) run on injected services.
- Fleet events run inside `GameBootstrap` on the injected `FlyingFleetHandler` (`fleet_events.php` and the static locator are gone); `julia.php` → `JuliaShipData` (also fixes its engine construction after phase 7); `vars.php`/`constants.php` write the including method's `$this->state`; `Template::printMessage()` (unused) removed; `Singleton::__isset()` no longer warns on unset keys.

## 40. Core DI, phase 10: last statics, two old bugs

- `Universe` is an injected object (state: current/emulated/available universes in the game state; no database, so the installer works before a config exists); 244 call sites → `$this->universe()`.
- `Cache` is a plain injected object with a fixed key → builder map (no runtime registration order); builders (`VarsBuildCache`) get the services; `vars.php` → `GameData::load()`, the `MODULE_*` map → `GameBootstrap::registerModules()`.
- `_date()` → `ViewHelpers::formatDate()`; the remaining global wrappers are gone — `functions.php` keeps only stateless helpers (battle engines use `$this->viewHelpers()->isModuleAvailable()`; a tokenizer scan finds no calls to undefined functions).
- `ConfigFieldSchema` gets the game state passed, `SQLDumper` reads the connection settings from the injected `Database`, `Template` no longer builds its own Twig fallback.
- Fix: `uni1_advanced_stats_history` primary key includes `timestamp` (migration `1794621600`; the statistics cron failed on every second snapshot).
- Fix: admin universe and reset pages no longer warn on a missing `sid` parameter.

## 41. Core DI, phase 11: static accessors removed

- `Database::get()`, `Database::usePdo()`, `Config::get()`/`reload()`, `Singleton::load()`, `DatabaseConfig::get()` are gone: the container builds `Database` (from `DatabaseConfig` + Symfony's DBAL connection, lazy), `Singleton` (game state) and `DatabaseConfig` directly.
- `ConfigRegistry` owns the per-universe `Config` objects and schemas (former static side of `Config`); `Config` instances save through their registry.
- All `fromGlobals()` factories removed; `Database::formatDate()` is an instance method.

## 42. Request, response and session on Symfony

- Request input from the Symfony `Request` only: `LegacyKernel` puts query/post/input (POST over GET)/cookies/server into the game state; no `$_GET`/`$_POST`/`$_REQUEST`/`$_COOKIE`/`$_SERVER` in game code (~260 reads), `HTTP` is an injected object (`$this->http()->_GP()`, 625 call sites).
- Response side: redirects are `RedirectResponse`s, headers/cookies/status codes are collected (`HTTP::sendHeader()`/`sendCookie()`) and applied by `LegacyResponseFactory`; no native `header()`/`setcookie()`/`http_response_code()` left.
- The game session lives in the Symfony session (`obj` attribute, `SessionManager` on `Request::getSession()`, written back before the response); no `session_start()`/`$_SESSION`. Storage: `SESSION_HANDLER_DSN` (default files in `var/sessions`; replaces the admin `session_handler`/`redis_connection_url` settings). Existing logins are invalidated once.
- `DatabaseConfig` gets `DATABASE_URL`/`TABLE_PREFIX` via env processors; Twig reaches `Template` through the game state; `NumberAbbreviation` is an instance; unused `clearGIF()` removed.
- Fix: admin rights page lists the pages from the admin controllers (it still scanned the removed `src/Pages/Admin/`).

## 43. UI refactor: top bar, side panel, native JS (branch task/ui-refactor)

- New game layout: `layout.base` (document shell) + `layout.full`/`layout.popup`; sticky top bar (planet switch, live resources, server clock, messages with badge, rank, account, admin, logout), grouped left side panel with Lucide icons and server-side active state, drawer on small screens, skip link.
- CSS: `app/base.css` (tokens + layered base/components: modal, tooltip, tabs, sortable tables, autocomplete, progress) and `app/game.css` (layout); classic theme stylesheets keep styling the content on top; old layout rules stripped from `ingame/main.css`.
- JS: native ES modules in `public/scripts/app/` (core, dialog on `<dialog>`, tooltip, timers, ticker, ui) replace jQuery, jQuery UI, fancybox, tablesorter, validationEngine, jquery.cookie, tooltip.js and base.js/topnav.js; all page scripts are modules, inline scripts run via `onPageInit()`; same for login, account, admin and installer.
- Removed: jQuery & plugins, tailwind runtime (account area got its own CSS), Font Awesome CDN, tinymce, numeral, html5 shim, unused base CSS (-2 MB scripts, -350 KB CSS); local Lucide sprite (`public/styles/resource/icons/lucide.svg`, `icon()` Twig helper).
- Layout data: player rank/points for the top bar (`total_rank` in the bootstrap user query); new `ui_*` translation keys (de, en).
- Fixes along the way: dead Google jsapi feed on the admin overview, broken `false.png` path, unicode info glyph in the fleet expedition counter.

## 44. UI refactor, part 2: CSS fully on the layer system, account lobby

- `ingame/main.css` → `app/content.css`, rewritten with native nesting and logical properties, dead rules removed (jQuery UI, tinymce, tablesorter, trader, commented-out blocks; 1133 → 1095 lines, pixel-identical).
- The game imports the player's theme and `content.css` into the `legacy` cascade layer (`@layer reset, base, legacy, components` + unlayered shell), so all game CSS runs through cascade layers.
- Account lobby (account.php: universes, settings, register, popup) redesigned in the shell language: slim top bar with Lucide icons, token-based panels and buttons, labelled form grid, responsive to 390px.
- Verified in the browser: market offer → arrival → purchase end to end, account popup (logout), all game pages at 1440/390, admin, login.

## 45. UI refactor, part 3: no legacy CSS or libraries left

- All nine theme stylesheets (`styles/theme/*/formate.css`) rewritten as modern CSS: `--theme-*` palette custom properties, native nesting, logical properties, rem units, range media queries; dead rules removed (old layout selectors, jQuery UI, fancybox, tinymce, old OGame table classes, IE scrollbar hacks, invalid selectors); 6,600 → 4,300 lines. Pixel-verified per theme against the previous files (0.00–0.01 % with clock masked).
- `app/content.css` uses rem instead of px (exact conversion, pixel-identical in game, admin and installer).
- TrashSim removed (static `battlesim.html`, its prebuilt bundle with jQuery, the `trashsim` JSON page and both links); the native battle simulator (also linked from spy reports) covers it. Shipped scripts now ~512 KB (was ~3.1 MB); no jQuery anywhere.
- Dead jQuery UI rule removed from the admin stylesheet.

## 46. Runtime naming

- `App\Legacy` → `App\Runtime` (the game's runtime layer on Symfony): `LegacyKernel` → `GameKernel`, `LegacyResponseFactory` → `GameResponseFactory`, `LegacySession` → `GameSessionReader`, `Security\LegacySessionAuthenticator` → `GameSessionAuthenticator`; firewall `legacy` → `game`. No behaviour change.

## 47. Last global access gone

- Login pages read the `lang` cookie from the request state instead of `$GLOBALS['_COOKIE']`; the installer's unattended-install defaults (`DB_*`, `ADMIN_*`) come in as Symfony env parameters instead of `getenv()`. No superglobals, `$GLOBALS`, `getenv()` or static accessors remain in `src/`.
- Theme breakpoints use range media queries (`width <= 699px`), last stray px padding → rem; pixel-identical at 1440 and 390.

## 48. Units and theme file names

- All shipped stylesheets (app, account, login, admin, installer, base, nine themes) use rem only: 424 px values converted (n/16), incl. hairlines, outlines, shadows and breakpoints; pixel-identical at 1440 and 390 in game (3 themes), login, account, admin and installer.
- Theme stylesheets renamed `formate.css` → `theme.css` (`Theme::getThemeCssPath()`, template variable `themeCssPath`).

## 49. Game constants as compile-time consts

- The 279 fixed game constants (modules, elements, fleet states, limits, auth levels, ...) are `const` declarations in `src/Game/Support/game_constants.php`, loaded by Composer (`autoload.files`); values verified identical. `constants.php` and its runtime `define()` block are gone.
- Request/install-dependent constants (protocol, URLs, cache path, code version) are defined by `GameBootstrap::defineRequestConstants()`.
- Fix: `GIT_VERSION` resolves the repository's `.git` again (it was empty since the move to `src/Game/Support/`), so asset URLs get their cache-busting `?v=<commit>` back.

## 50. Runtime context instead of request constants

- `App\Runtime\RuntimeContext` (container service, `$state->runtime`, PHP 8.4 asymmetric visibility + property hook) replaces the define()d `TIMESTAMP`, `MODE`, `ROOT_PATH`, `CACHE_PATH`, `AJAX_REQUEST`, `HTTPS`, `PROTOCOL`, `HTTP_BASE/ROOT/FILE/HOST/PATH` and `GIT_VERSION`; started once per request by `GameKernel::boot()/bootCli()`, timestamp frozen per request as before. ~400 uses in 90 files rewritten token-aware (strings/SQL untouched).
- `App\Runtime\ProjectPaths` (typed class consts) gives path-only code (theme, cache builders, language catalog, disposable-mail blocklist) the project/cache dirs without request state.
- `DB_NAME`/`DB_PREFIX` → `Database::getDatabaseName()/getTablePrefix()`; `tables.php` uses a local `$prefix`.
- PHP error state moved to `ErrorReporter::register()` (E_ERROR, log to var/log/game.log, handlers); dropped no-op `mb_internal_encoding`, timezone fallback, `display_errors` and the dead P3P header from the bootstrap.
- `functions.php` is Composer-autoloaded (`autoload.files`); `userStatus()` moved to `PlayerUtil::userStatus()`.
- Templates use the `asset_version` Twig global instead of `php_const('GIT_VERSION')`; `GameExtension` reads it from the runtime context.
- `composer.json` requires `php >=8.4.1` (matches Symfony 8.1).
- UI: top bar, side panel and content sit in a 75rem (1200px) shell (`--shell-max`, full-bleed bar with centred contents via `--shell-gutter`); account lobby uses the same width; resources always show short numbers (exact value in the tooltip), "Rank" label hidden in favour of the trophy icon.

## 51. Top bar rows, themeable shell

- Top bar in two rows: brand, planet switch and status on top, the four resources in their own evenly spaced row below (full numbers again from 40rem up, short + tooltip on phones); "Rank" label back. Opaque bar, `--topbar-h` measured by `ui.js` (ResizeObserver) so the sticky side panel and fixed theme boxes sit below it.
- Fix: the player's theme is imported after `content.css` again (it wins ties, as in the original header); the order was reversed since the first UI commit.
- Shell tokens in `@layer base` so themes can set them: `--shell-bar-bg`, `--shell-side-bg`, `--shell-line`, `--nav-link-border/-radius/-align/-gap`, `--nav-icon-display`, `--brand-h`. MinimalNoir uses them for its classic look (black bars, boxed centred menu, terminal logo via `.topbar__brand img { content }`).
- Theme `.message-box` offsets follow the bar height (`calc(var(--topbar-h) + 0.5rem)`) instead of the old 6.25rem header.
- Side panel: more top padding below the bar, tighter menu items; permanent page scrollbar on the full game layout in every theme.

## 52. Compact build queue, one button component

- Build and research queues share `shared.buildqueue.html.twig`: one fixed-height row per entry (position, name/requeue, countdown, end time, cancel) plus free slots up to the queue limit (`queueSlots` = `max_elements_build`/`max_elements_tech`), so queueing or cancelling never moves the page; progress as a thin bar on the active row. `buildlist.js` reads the active name for the tab title again (dead selector).
- Buttons: one component in the `components` layer for all themes (`:where()` keeps it overridable by later components), signal-coloured buttons carry their colour on the border, `.selected` toggles, `.btn--danger`/`.btn--small`; no wrapping except build-list buttons.
- New theme tokens: `--btn-bg/-fg/-border/-hover-bg/-hover-border/-radius/-shadow/-selected`, `--progress-fill/-track`, `--nav-active-bg/-fg/-icon`; MinimalNoir (black, white, inverted active item) and pr0gress/community pr0gress (slate, green) use them.
- Translations: `ui_queue`, `ui_queue_free` (de, en).

## 53. SteemNova skin: one corner radius (0.25rem)

- `nova/theme.css`: all 19 radius declarations → 0.25rem (square corners stay square), shell tokens `--radius`, `--radius-sm`, `--btn-radius`, `--nav-link-radius` = 0.25rem, and the shared content corners (artwork `img`, nested tables, repair-dock and tutorial headers) overridden to match. Circles (status dot, planet switch) and count badges keep their shape.

## 54. Dead third-party scripts removed

- Google Analytics (legacy `ga.js`/`_gaq`, shut down by Google in 2023) removed from the game, account and login footers and the Symfony login layout, with its template variables, the admin config group (`use_google_analytics`, `google_analytics_key`) and the `se_*google*` admin translations; DB columns left in place.
- `templates/base.html.twig` no longer pulls idiomorph / frankenphp-hot-reload from a CDN.

## 55. Clean URLs

- All pages answer without file extension: `/game`, `/admin`, `/account`, `/CombatReport`, `/` (login), `/install/`, also under `/uniN/`. `#[GamePage]` and the entry routes match `/<area>{_ext}` (optional `.php`); `LegacyUrlRedirectListener` 301-redirects GET/HEAD on the former `*.php` URLs (query kept), POSTs to them are still served. Firewall pattern updated.
- Links rewritten token-aware in templates, page scripts, PHP and translations (584 lines): `game.php` → `game`, `index.php?…` → `./?…`, script identifiers (`GamePage`, `boot()`, `getEntryScript()`) and external links untouched. `HTTP::redirectTo()` accepts `./…`.
- `RuntimeContext`: `httpRoot` is the request's directory for both URL forms, `httpFile` the clean area name (`''` for the login), so the universe check and `redirectToUniverse()` keep working.

## 56. Admin backend overhaul

- Frameset (menu / top nav / content frames) replaced by one layout on every admin page: top bar (universe switcher, back to game, drop admin rights), grouped side menu with icons, ticket badge, live filter (`/` focuses, Enter opens), page title from the menu; drawer below 60rem. Menu data from `App\Controller\Admin\AdminShell` (one definition, filtered by rights), injected by `Template::adm_main()`; password prompt and quick editors render bare. `?page=index|menu|topnav` redirect to the overview, `?page=logout` returns to the game.
- `admin/admin.css` (on the shared tokens; old `admin/main.css`, boilerplate, nova theme, icon font and Google font no longer loaded): cards, notices, stat tiles, data tables (sticky header, sortable, row filter), form grid with visible help texts, switches, sticky save bar, toolbar, tabs, badges, pager, empty states. `admin/main.js`: menu filter, universe switch, `data-confirm`, unsaved-changes guard (`data-track-dirty`), `data-table-filter`. Quick editor opens in the modal dialog.
- All 60+ admin pages rebuilt for their task: dashboard (warnings, online/players/tickets/fleets, quick access, system and cron state), settings as section cards with jump list, search with structured results and windowed pager, data sheets, ban/creator/rights/reset/universe, cronjobs, modules as switches, logs with old→new diff, tickets as thread. Destructive actions confirm. 18 Lucide icons added to the sprite; ~60 admin translation keys (de, en).
- Fixes: search pager lost the filter (`fuki`/`fucki`) and delete guard for own/root account (`||` → `&&`); rank labels (`rank_N` never existed → `se_rank`); cronjob editor showed "range(0, 59)"; log player list SQL (`ORDER BYl.id`); news count always 1; destroyed-planets flag only checked the last planet; ban sort links lost their mode; broken `<form>` nesting in the rights pages; dashboard checked removed `cache/`/`includes/` instead of `var/`.

## 57. Clean URLs in stored content, admin checked in use

- Migrations `1794700800`/`1794700801`: stored messages and admin comments link the clean URLs (plain and URL-encoded `*.php` links rewritten); Hall of Fame links to the live universes use `/game` too. No `.php` page link left in templates, scripts, translations or the database.
- Fix: saving a module or clearing the cache crashed (503) when the stored `version` was empty (`Maintenance::clearCache()` falls back to `install/VERSION`).
- Admin verified in use (settings save round-trip, Enter = save, module switch, cronjob state toggle, search pager keeps filters, quick editor modal, confirmations, table filter; flying fleets with a live fleet). Flying-fleet table condensed (sender/target/time columns combined, icon actions) so it fits without horizontal scrolling; `.data--wrap-head` and `.break` helpers; comment cells break long URLs. No admin table overflows at 1440.

## 58. Admin fully on translations, destructive forms verified

- Every visible admin text comes from the Symfony translations (`translations/admin.{de,en}.yaml`): hard-coded labels, column headers, aria-labels/placeholders, inline-script texts and PHP strings ("Ja/Nein", info page, support categories, exception texts) moved to ~100 new `adm_*` keys. de/en key sets of admin, ingame, public and l18n are identical now (137 admin, 123 ingame, 15 public, 2 l18n English values added); tone reviewed (natural game language, proper names like "Hall of Fame" kept). English menu labels in Title Case, clearer menu groups (Players, Monitoring), "Logout" instead of "Drop admin rights", "Password Hash" instead of "MD5 generator".
- Icon sprite URL carries `?v=<mtime>` so new icons are not hidden by a cached sprite.
- Destructive admin forms exercised end to end with cleanup: ban/unban, planet create + delete, mass message, ticket answer + close, giveaway, universe reset (empty selection). Fix: the planet creator built `SET , name` without a field limit, so the planet name was never applied.

## 59. Universe reset works with a partial selection

- Fix: the universe reset read every option as `$_POST['x'] == 'on'`; unticked checkboxes are not posted, the "undefined key" warning became an error in the admin and the reset died (503) unless every option was ticked. All 21 options use `?? ''` now; same fix for the moon creator's diameter checkbox. Dead "reset error list" option (no handler) removed.
- Fix: ticket list status badges were empty (Twig macro without `LNG`).
- Verified: reset with a real selection (notes only → notes cleared, players/planets/messages intact), ticket list with several tickets, flying-fleet "send back" on a live fleet.

## 60. Compact admin menu with tabs

- Admin menu from ~40 to 21 entries: related pages share one entry and switch via tabs below the page title (`AdminShell` entries carry their tabs; rights per tab, an entry shows when any tab is allowed). Settings (Server, Universe, Planets, Modules), Expeditions (Card Deck, Findable Ships), Players (Players, Online, Planets, Moons, Active Planets, Advanced Search), Accounts (Info, Editor, Create, Swap), Moderation (Bans, Activations, Multi-IP, Comments, Analytics), Messages (List, Global Message, News), Permissions (Rights, Ranks); groups General, Community, Tools, System. The menu filter also matches tab names.
- New Maintenance page (Tools) with Clear Cache and Update Points as explained actions instead of action links in the menu; both lead back to it. Message pages only auto-redirect when a delay is set (a 0 s delay used to skip the result).
- Section jump list on the settings pages styled as chips, distinct from tabs.

## 61. Symfony Security for the whole game, leaner dependencies

- Login is Symfony Security: one stateful firewall over all routes, `LoginFormAuthenticator` handles the start-page login (bcrypt cost 13 via the password hasher, `PasswordUpgraderInterface`; failed-login counter code=1/4, verification link for not yet verified accounts, entry point code=3). `access_control` protects `/game`, `/account`, `/admin` (public battle report links `game?page=raport&raport=…` stay open). Logout via the firewall (`/logout`) and `GameAuth::logout()` (game/account logout, password change, account deletion); `GameLogoutListener` closes the game session and its row before the session is invalidated. Programmatic login after e-mail verification and installation (`GameAuth::login()`).
- The game session object (chosen player, planet, admin second factor) follows the Symfony login (`GameBootstrap::syncGameSession()`): new game session on a fresh login, logout after SESSION_LIFETIME idle, rewritten row after a session id renewal. The old login controller only redirects; `GameSessionAuthenticator`/`GameSessionReader` (stateless bridge) removed.
- Cron without worker: `bin/console app:cron:run` every minute from a system cron (README) or the DDEV loop daemon; symfony/messenger, symfony/scheduler, symfony/process (+ clock) removed, as is monolog-bundle (Symfony's built-in logger). PHP extension requirements trimmed to what is used (+ pdo_mysql).

## 62. Symfony profiler with a game database panel

- symfony/web-profiler-bundle (dev only): debug toolbar and profiler on every page, game pages included.
- Panel "Game DB" (`App\DataCollector\GameDatabaseCollector`): the game's queries run on the native PDO connection and are not seen by Doctrine's panel, so `Database` records SQL, parameters, type and duration in debug mode (`$debug` = `%kernel.debug%`, nothing recorded in prod); toolbar item with count and time, panel with repeated queries and the full list.
- DBAL for the game queries was measured and left out: +5–12 % per query (0.19 → 0.21 ms) without security gain (the game already binds values).

## 63. Universe in the top bar

- Game top bar shows the universe name with a globe icon next to the server clock (icon only below 68.75rem, name in the tooltip; hidden on phones with the clock); the footer drops the universe name and the server time and keeps game name and version.

## 64. Start page overhaul

- Login area on one layout (`login/layout.base.html.twig`, light/normal/popup extend it; main.header/navigation/footer removed); `css/login/login.css` replaces main.css/register.css/icon font: nova palette, CSS star field instead of background images, header nav with language select (no flag images), orange-bar panels, menu toggle on phones.
- Start page: hero with the game description, register/login buttons, plain universe start line with live countdown (`.uni-countdown`, own class so app/timers.js keeps off it) and the comic panel (`images/login/hero.webp`); link box (Discord, wiki, HoF, battle hall, screenshots, news) next to the login form; login errors as notice instead of alert(); meta description, Open Graph and JSON-LD in the head.
- Content pages (news, register, lost password, ban list, battle hall, team, rules, imprint, privacy, screenshots, HoF variants, error/redirect) re-marked without layout tables or inline styles; Symfony login layout on the same CSS.
- Screenshots regenerated from the current UI as WebP with thumbnails (6.6 MB → 0.6 MB), sorted by file name; old backgrounds, header image and flags deleted.

## 65. Start memes, compact start page, fresh-install wizard

- Start page memes from `public/memes/` (any webp/avif/jpg/png/gif; new files join automatically), one per hour in file name order (`IndexController::memes()`), alt text `home_meme_alt_<name>` with a generic fallback; the two new comics as WebP (6 MB JPEG → 273 KB), `hero.webp` moved there as `first-day.webp`.
- Start page in two columns: meme + link box left, welcome + login right (welcome and login first on phones); hero login button and the duplicate register button removed.
- Footer: `© Project Br0Game` with the default game name, else `© <name> on Project Br0Game`; `game_name` defaults to `Br0Game`.
- New global setting `default_theme` (admin select over the css themes, default nova) for new accounts, players and visitors (`Theme::resolveDefaultTheme()`).
- `{game_name}` placeholder in texts no longer recurses while the config loads (`ConfigRegistry::isLoaded()`, replacement deferred to the first read) — fixed the runaway memory of the CLI cron.
- Installer rebuilt for fresh installs: no `config/game.php` → `/` redirects to `/install/`, which opens without `var/ENABLE_INSTALL_TOOL` (created automatically, removed after the admin step). Steps licence → requirements (check list) → database form (connection, database, prefix, "create database", migration password) → connect with the form values, create the database, check the prefix is free, write `config/game.php` and `.env.local` (`DATABASE_URL` + `TABLE_PREFIX`) → build the tables task by task (tables, migrations, config, universe; JSON per task, `scripts/install/build.js`, `task=all` without JS) → game name (default Br0Game) + default theme → administrator → done. New `ins_layout` on the start page styles (`css/install/installer.css`), language from `?lang=`/cookie; upgrade mode unchanged.
- Installer texts rebranded to Br0Game (de/en; the historic "pr0game v1.7" upgrade note stays) and a credits strip below every installer step: UGamela → XNova → XG Proyecto → 2Moons → SteemNova → pr0game → Br0Game (credits as in README.md).
- Licence made consistent with `LICENSE` (GNU AGPL v3): installer licence step shows the LICENSE file (the bundled GPL v3 copy is gone), `composer.json` `AGPL-3.0-only` (was MIT), installer footer notes the AGPL; new global setting `source_url` (default the public fork) linked as "Source code" in the start page, Symfony login and game footers (empty hides it).

## 66. Board, mini CMS, team section, news overhaul, WebP images

- Shared content layer: `App\Content\HtmlContent` (symfony/html-sanitizer; `rich()` for pages/news, `basic()` for the board, `fromPlainText()`, `excerpt()`), native rich text editor `scripts/app/rte.js` (`<textarea data-rte="rich|basic">`, toolbar, paste cleanup, HTML source view) + `css/app/rte.css` (`.rich-text` for rendered content), loaded by the admin, account and login layouts.
- Board instead of the external forum: reddit-style board in the account area (`account?page=board`, login required, cross-universe, identity = account): posts with flairs, hot/new/top sorting, up/down votes via fetch (score adjusted by vote delta), threaded comments with collapse and inline reply/edit, own edit/soft delete, moderators pin/lock/delete, rate limits, CSRF token in the session. Tables `board_posts`, `board_comments`, `board_votes` (migration 1794787203). Old `?page=board` routes redirect there; board links in the game navigation, account top bar and start page header. `forum_url` stays only as the contact link in the ban message.
- Mini CMS: `pages` table (slug + language, migration 1794787200), `App\Content\ContentPages` with fallback (language → en → any → the previous built-in text), admin `admin?page=pages` with the editor and "reset to default"; contacts (`?page=disclamer`), data privacy (login + account) and rules (`/rules`) render from it.
- Team: own admin section `admin?page=team` (rows with name, position, contact, description, order; add/remove rows), table `team_members` (migration 1794787201), empty after installation; the hard-coded team table is gone.
- News overhaul (migration 1794787202): title up to 160 chars, summary, draft/published/scheduled (publish date), pinned, rich text (old news keep rendering as plain text via `format`), one image per news (uploaded, stored as WebP Q85 in `public/uploads/news/`, removed with the news); admin list with filters and badges, POST-only actions; public list with cards, pagination and single article view; "latest news" panel on the start page.
- Admin menu: new entry "Content" (pages, news, team).
- WebP for all static images: `App\Content\ImageProcessor` (GD, quality 85, animated GIF/WebP/PNG detected and left alone) and `bin/console app:images:webp` (copies `foo.png.webp` next to the originals when smaller; 2150 images, 53.7 MB → 12.7 MB); `public/.htaccess` serves the copy to browsers that accept WebP (`Vary: Accept`), URLs unchanged; the installer runs it as last build task; copies and uploads are gitignored.
- install.sql carries the new tables and the extended news table.

## 67. Templates out of legacy, native rendering, autoescaping

- Templates moved from `templates/legacy/<area>/` to `templates/{login,game,account,admin,install}/`; Twig namespaces (`@game`, `@adm`, …) replaced by plain paths (`admin/` instead of `adm/`), `twig.yaml` path mapping removed. Conventions in `docs/templates.md`.
- `App\Game\Core\Template` (Smarty-compatible wrapper: `assign_vars`, `show`, `fetch`, `.tpl` names, `extends:` chains, template dirs, caching no-ops) deleted. Controllers render through the `App\Controller\RendersPages` trait: `assign()`, `render($template): Response`, `display()` (throws the page for helpers), `addScript()`/`addInlineScript()`; page actions return their Response; area layout data comes from `layoutData()` in each base class; admin layout data from `AdminShell::pageData()`.
- `App\View\GameView` renders outside controllers (installer, admin/installer message page, spy reports, compiled-template clearing); `App\View\HtmlWhitespace` replaces the Smarty trimwhitespace output filter for stored report HTML.
- All 120 page controllers ported (`: Response` actions, admin `show()` returns the page, `$this->message()` for admin notices), unused imports and dead assigns removed, class docblocks rewritten in English; installer renders via `GameView`; unused install templates (`ins_convert`, `ins_header`, `ins_upgrade`) deleted.
- Autoescaping on for all game templates with the `game_html` strategy (`App\Twig\GameEscaping`, htmlspecialchars without double encoding, registered on every Twig environment by `App\Twig\EscaperConfigurator`): input stays encoded by `HTTP::_GP()`, raw values (old data, code-built strings) are now escaped — fixes stored XSS through names printed unescaped (player/planet names in statistics, player card, empire, admin lists).
- `LNG` is a Twig global (`App\Twig\LanguageView`, lazily bound to the request's language): translations are trusted `Twig\Markup`, nested lists wrapped the same way, missing keys print the key name (missing list entries print nothing); `format`/`sprintf`/`nl2br` filters are escape-aware; helper output is `is_safe: all`; `TemplateExtension` moved to `App\Twig`.
- Markup from code is explicit: `|raw` for stored HTML (messages, reports, CMS content), `Twig\Markup` for code-built HTML with escaped parts, translations formatted in templates instead of PHP (`LNG.key|format(...)`), JSON in scripts `|json_encode|raw`; `_GET`/`_POST` template variables replaced by `app.request`.
- Fixed on the way: admin message list overwrote the user's timezone (misplaced parenthesis), statistics update failure log was unreachable, notes page character counter still used jQuery, account privacy page rendered the login layout, lost-password form used a misspelled translation key (`passwordErrorUsernameEmpty`).
- Verified by a 140-page crawl diffed against the pre-port HTML (identical status codes; differences only escaped raw test data, URL `&amp;`, live data), a fresh install end to end, and ~40 browser-driven action flows (messages, notes, buddies, alliance lifecycle, settings, tickets, build/research/shipyard, fleet send/recall, simulator, account settings and board, registration errors, admin ban/creator/broadcast/news/pages/team/quick editor/account editor/rights/support/giveaway/stats) with `<b>x</b> & "q" 'z'` input shown literally and escaped once; FAQ answers, board bodies and admin/system message subjects marked as stored HTML.

## 68. GDPR privacy policy, clean content typography

- Default privacy policy rewritten for the GDPR (de/en; the other locales keep copying en/de): controller and contact, server logs, account/player data, in-game communication, fair-play checks (IP comparison, sessions, bans, per-account page log on suspicion, disposable-mail check), emails, optional Google reCAPTCHA (EU-U.S. DPF), strictly necessary cookies (`2Moons`, `lang`, `account`) and local storage, recipients and processors, storage periods, Art. 15–21/77 rights with the Art. 21 objection notice, no Art. 22 decisions, security.
- `ContentPages::privacyPolicy()` fills it from the live settings: `{game_name}`, `{contacts_url}`, `{contact_mail}`, `{deletion_days}`, `{inactive_days}`, `{message_days}`; optional blocks `<!--mail-->`, `<!--recaptcha-->`, `<!--inactive-->`, `<!--messages-->` drop out when the feature is off (replaces the positional `%s` template).
- Rules text cleaned in all locales (no list-item wrappers, `h2` sections, no inline colours; notice line as `.rules-notice`); game rules page uses the shared rich-text styles (rte.css now loaded in the game layout).
- Rich text typography calmer: 76ch line length, softer body colour, smaller headings with more space above, muted list markers, framed `details`; the login heading reset no longer flattens rich-text headings.
- Menus: the public header and the admin tabs mark the current page with a plain underline instead of a filled pill.

## 69. Inline CSS out of the templates

- New `utilities` cascade layer in `css/app/base.css` (alignment, widths, spacing, visibility helpers) that wins over themes and components; replaces one-off `style=""` attributes.
- About 500 static inline styles and 19 `<style>` blocks moved into the area stylesheets (`app/content.css` page sections, `admin.css`, `login.css`, `account.css`, `installer.css`) or onto utilities; PHP-built markup (fleet events, expedition messages, tutorial links, error page) uses classes. `shared.questions.styles` include removed.
- Inline styles left only where needed: data-driven values (category/signal colours, report grid columns, comment depth) and `display:none` on elements that JS toggles via `style.display`.
- Fixed on the way: combat report header colours (`color:colorPositive` was invalid CSS), `text-right!`-style Tailwind leftovers became real utilities (fleet cargo table now right-aligned), market and galaxy toggles use `hidden`.

## 70. Game logic bug sweep (docs/GAMEBUGS.md)

- Four read-only reviews (fleets/missions, combat, economy/queues, players/alliances/market/cron) plus end-to-end runs produced 79 findings, documented with evidence in `docs/GAMEBUGS.md`; all fixed and tested (oversized PHP-engine battles now fight on scaled counts instead of being cancelled, moon wrecks stay with the parent planet's dock by design, fusion burn at empty stock, player deletion cleans secondary tables).
- Critical: jump gate SQL injection through ship amounts (GB-01), repair dock double deploy race (GB-02), cleaner deleting every player when automatic deletion is disabled (GB-03).
- Fleets: ACS target/delay validation, hold limits, missile recall and moon interceptors, missiles had no element class (migration 1794787211), deliveries to destroyed planets, universe filters, trade parameter whitelist, step-3 locking, hostile missions return when the target is in vacation.
- Combat: repair rate/rounding capped and fair, memory limit parsing, consistent Rust rounding, honour loot tiers, per-user battle stats, ACS loot without resource creation, moon destruction RIP count and hall-of-fame galaxy, simulator input and report limits, report comment limit.
- Economy: research queue timing and resources, demolish duration, queue re-timing and removal, shipyard multi-type cost/limit checks, planet abandon queue handling, moon income on the shared config, techtree branches toggle again, planet name length.
- Players/alliances/market/cron: alliance rights (settings, kick, close, transfer, succession, diplomacy, applications), note and ticket ownership, market universe/self-buy/rollback, buddy and message validation, daily cron cleanup, stats export joins, registration settings copy, phalanx wording, language pack self-repair.

## 71. Performance pass (docs/PERFORMANCE.md)

- Benchmarked in prod mode (built-in server in the web container, OPcache, no profiler; per-page medians + `performance_schema` query counts) and on a seeded clone for the cron routines; results unchanged (crawl diff, side-by-side row comparison).
- Per request: config read once (`GameConfig` reuses `ConfigRegistry` rows), session/onlinetime/multi-IP writes throttled to changes or 30 s, ban-expiry update only when needed, migration scan cached, tutorial sync at most once a minute, alliance counters skipped without an alliance; game pages 40 → 28 queries, about -15 to -20 %.
- Pages: empire chart one query (65 → 1) and fleets one query, galaxy colonisation/noob checks batched; empire 50 → 26 ms, galaxy 36 → 25 ms.
- Routines: statistics cronjob 1560 → 213 ms (4375 → 66 queries; batched stat rows, record maxima per type), resource update about -50 % (shared wreck field lock, formulas compiled to closures instead of `eval`), fleet events -24 % (language packs reused).
- Indexes (migration 1794787218): `accounts_to_users`, tutorial tables, `users_to_messages (user_id, message_unread, deleted_at)`.
- README: production install with an authoritative class map and OPcache preloading (about -3 ms per request on top).

## 72. One Rust battle engine, Julia and FFI removed

- Rust engine was never loaded since the move to `src/Game/` (library path pointed to `src/bin/`, GB-80).
- `bin/battlerust`: standalone launcher (C, `tools/battlerust-cli/`, built in a throwaway Ubuntu 20.04 container by `build.sh`, so it needs only glibc 2.4 and runs wherever the library's glibc 2.30 is available) that loads `libbattlerust.so` and exchanges the battle JSON over stdin/stdout; the library's progress output is kept off the result stream.
- `BattleEngineRust` runs the launcher via `proc_open` (no FFI extension needed); engines are now Rust → PHP. Removed: `BattleEngineJulia`, `JuliaShipData` (+ its export/restart in maintenance), `juliaBattleEngine/`, the FFI call and `bin/libbattlerust.dll` (Windows).
- Benchmark (PERFORMANCE.md): Rust ≈ 40× faster than the PHP engine for 39k units, ≈ 110× for 600k, ≈ 32× for 3.4M (PHP on scaled counts); the launcher adds ≈ 2 ms per battle.
- composer.json: `ext-gd` required (news image uploads, WebP copies), `ext-openssl` moved to `suggest` (only PHPMailer's SMTP over TLS uses it); README requirement list updated.

## 73. In-game tables and forms

- `css/app/tables-forms.css` (layer `components`, loaded by the game layout): tables span the content width, cells left aligned with roles `.num` (right, tabular), `.ctr`, `.act`; `table.form-table` label/control rows with one shared control width; `.form-actions`, `.control-group`, `.control-wide`, `input.num`/`type=number` and `input.coord` widths; even control heights; `.table-scroll` for wide tables on phones. Applies to the content area and dialogs in every theme; documented in `docs/templates.md`.
- Fixed widths gone (`table519`/`table569` with `!important`, `width=` attributes, `w-80/90/95` on main tables); all in-game templates got column roles and form conversions (settings, fleet steps, fleet table, galaxy, information, resources, empire, market, simulator, battle hall, records, messages, alliance pages, tickets, notes, buddies, search, statistics, player card, …) without changing submitted fields or JS hooks.
- Phone check: no in-game page wider than a 390 px screen (was 5 of 50); wide tables scroll inside their wrapper, fleet/empire tables keep sticky first columns.
- Broken markup fixed on the way (galaxy tooltips, fleet step 1 ACS list, techtree, records, alliance info, achievements, simulator); in-game team page reads the admin team list (GB-81); alliance ranks removed with their alliance (GB-82).

- Themes are palette and decoration only: table/form layout declarations (cell and control padding/alignment, input widths, build-button sizes, `.table519` margins) and dead selectors (`#menu`, `.planetb*`, `.bulida`, `.buildb`) removed from all nine theme packages (401 lines); build buttons get one even width from `content.css`.
- Verified per theme: contact sheets of 12 pages × 9 themes (fresh screenshots); fixed what they showed (resources table now six columns throughout, simulator fleet/defence split 50/50 and top aligned via the new `.top` role). Jump gate ship list rendered with a temporary second gate moon (removed again); amount fields use 5rem on phones so "amount + max" fits. CSS cost measured: 6 KB / 1.9 KB gzip, style and layout times unchanged within noise.

- Messages page reworked (same oldschool look): category tiles in two even rows of seven (three columns on phones) with unread counts in bold and empty categories dimmed; one toolbar (search | favourites + grouping filters); bulk bar above and below the list with a new "select all" box, the action select and compact page links (no empty links for hidden pages); message headers share one column grid (type, sender, subject, date, tools) so the list reads like a table; unread messages get a coloured edge, single messages lose the double frame; "An:" replaced by the translated `mg_to`; new key `mg_select_all` in all locales.
- Bulk "delete unmarked" deleted the marked messages too and bulk actions dropped the page number (GB-83).
- In-game warning boxes (temporary admin rights, rebuild protection) printed black text on the dark shell background: old light-box rule removed from `content.css`, text and link colours set in `game.css`; dead `.globalWarning` rules removed from the pr0gress themes.
- Charts: Chart.js 4.4.7 (full UMD copy in `scripts/base/`, 206 KB) replaced by a reduced Chart.js 4.6.1 ES module (`public/scripts/lib/chart.js`, line + bar, 173 KB / 60 KB gzip; rebuild with `sh tools/chartjs/build.sh [version]`). The ~600 lines of inline chart code moved out of the templates into `scripts/app/charts.js` (theme colours, number format, tooltip helpers), `scripts/game/empire.js` (points + change charts, collapsible row groups via `data-toggle-rows`) and `scripts/game/alliance-chart.js`; templates only print the data as `<script type="application/json">`. Charts draw right away (the 500 ms start delay is gone); `shared.alliance.chart.script.html.twig` removed.


## 74. Public board, start page menu

- Board readable without login: `access_control` lets guests reach `account?page=board` (list and post view), `GameBootstrap::bootAccountGuest()` builds an account stub (id 0, cookie/browser language, server timezone, PUBLIC texts); `BoardController` sends guests to the login for every other mode (all writes, vote), guests get no CSRF token/session, disabled vote buttons and a "log in to post" hint instead of forms; `AbstractAccountController` keeps all other account pages login-only. Guests see the board in the start page frame (`account/layout.site` extends `login/layout.base`, new `body_class` block, Board marked current in the site nav); sprite icon `log-in` added. The account bar logo links to the start page.
- Start page menu on narrow screens: toggle moved to the right edge after the language select, menu opens as a full-width list with 44 px rows and a current-page marker, closes on Escape (focus back to the toggle), outside click and when the window grows past the breakpoint; brand text hidden below 24rem.
- Start page display font Michroma (OFL, self-hosted in `styles/resource/fonts/michroma/` with `OFL.txt`, preloaded): logo, chrome headings (`h1`–`h3` outside rich text and board post titles) and panel title bars; weight 400 only (`font-synthesis: none`), sizes toned down for the wide face. One subset woff2 (17 KB instead of 32 KB for Google's latin + latin-ext): Basic Latin, Latin-1, Polish/Turkish letters, Œœ Ÿ, dashes, quotes, ellipsis, €, ™ (unicode-range in `login.css`; built from Michroma-Regular.ttf with HarfBuzz subsetting, e.g. npm `subset-font`). Cyrillic falls back to the body font. Logo has no hover underline; phone header keeps brand, language and toggle in one row (globe icon hidden, select capped below 30rem; brand text hidden below 22rem). In-game unchanged.

## 75. Popups become modals (no iframes, no popup windows)

- `scripts/app/dialog.js` rewritten: `Dialog.open(url[, width])` fetches the page as a fragment (header `X-Game-Fragment: 1`) into one in-document `<dialog>` instead of an iframe; scripts of the fragment are re-created and run in order (`onPageInit` callbacks wait for them), forms submit and same-page links navigate inside the modal, a POST that redirects to another page follows with the whole window; `modal:load` event for page scripts that render per fragment. Height follows the content (old height arguments ignored). `Dialog.show()` also takes a `<template>` (cloned); `Dialog.alert()` is a themed message box with callback instead of `window.alert`.
- Server: `RuntimeContext::$ajax` is also set by the fragment header, so modal requests skip the planet resource tick and fleet event processing; `AbstractGameController::setWindow('popup')` becomes `ajax` in that mode, `game/layout.ajax` now outputs the page scripts, `{% block script %}` and inline code.
- Converted: info (`Dialog.info`), message write/forward, player card, buddy request, planet actions, alliance circular and diplomacy request, notes, spy report and phalanx (former `OpenPopup` windows, `OpenPopup()` now opens the modal), battle reports (links with `target="_blank"` in messages, battle hall, expedition texts open as modal in the game, modifier click still opens a tab), battle simulator result (former full screen `window.open`), admin quick editor (`opener.location` was broken in the iframe), rules link on the overview and data privacy in game navigation and account bar (`data-dialog`), alliance links in the statistics no longer target a named window.
- Achievements: descriptions are inline `<template>`s (`game/shared.achievementInfo.html.twig`, also on the player card); `AchievementsInfoController` and its template removed (no request at all).
- `parent.*`/`opener.*` calls replaced (same document now); per-fragment init: `raport.js` renders on page init and `modal:load` (first unrendered `#battle-statistics`), `message.js` spy report init on `modal:load`, overview actions tabs inline (ids prefixed `planet-actions-`); success/error messages in the modal flows use `Dialog.alert`.
- Theme: modal surface tokens `--modal-bg`, `--modal-border`, `--modal-fg` (defaults in `base.css`, set by all nine themes from their palette); the content inherits the theme's table, form and font styles because it lives in the page document. Close button follows the tokens; loading state with reduced-motion fallback.
- Left as is: external links (wiki, Discord, donations, GitHub), the building-34 YouTube easter egg, spy report links to fleet/simulator pages (new tab on purpose), public battle hall on the start page.
- Module cache busting: imported ES modules (`import './dialog.js'`) had no `?v=` and stayed in the browser cache (no Cache-Control, heuristic caching; `asset_version` = git commit ignores uncommitted edits), so old popup code kept running. `import_map()` (App\Twig\GameExtension) emits a `<script type="importmap">` mapping every file under `public/scripts/` to `?v=<mtime>`; included at the top of the game, account, login and admin layouts.
- Inline modals (`Dialog.show`): content goes into a scrolling `.modal__body` like fragment modals, the dialog keeps `overflow: visible` (close button on the edge was clipped and caused a horizontal scrollbar). Achievement description as picture + heading + text (`.achievement-info`) instead of a table that inherited the 42 % width of the tile name.

## 76. Achievements page overhaul

- Compact layout instead of building-style tiles (15rem tall, three per row; page height 3680 → ~1300 px desktop, 5160 → ~2250 px phone): header with "x of y unlocked" and progress bar, filter chips (all / unlocked / locked, inline onPageInit script, `data-ach-filter`), auto-fill tile grid (picture, name wrapping over up to two lines, unlock date or lock icon); locked tiles dimmed and greyscale. Tiles are buttons opening the description modal, which now also shows the unlock date or "not unlocked yet".
- `AchievementsController` passes one list (`id`, `unlocked`, formatted `date` via `php_dateformat`) without the not-implemented ones, plus counts. Styles in `game.css` (unlayered: the shared button component layer would otherwise restyle chips and tiles); accent `--ach-done` (default positive colour, nova: teal line). Keys `ach_progress`, `ach_filter_*`, `ach_locked`, `ach_unlocked_on` (de, en).

## 77. New messages notice only after login

- The "You have x new messages" banner on top of overview, buildings, research, shipyard, repair dock, tech tree and achievements is gone (`game/shared.messages.html.twig` and the `messages`/`message_type` assigns of seven controllers removed); the badge on the messages icon in the top bar shows the count.
- Instead a notice card under the top bar on the first full page after login (per player: `AbstractGameController::newMessagesNotice()`, flag `messages_notice_<userId>` in the Symfony session, which logout invalidates; link respects the "open message category" setting), closable (`[data-login-notice-close]` in ui.js), full width on phones, theme surface via the modal tokens. Key `ui_close` (de, en).

## 78. Battle simulator layout

- One toolbar row (add ACS slot, swap sides, share code input with load/share) instead of three; per slot a two-column grid (ships | technology, planetary defense and the defender's resources, the latter two in the first slot only, marked `data-first-slot-only` so share-code slot clones drop them); unit thumbnails from the theme (`gebaeude/<id>.gif`, removed when missing); per-column clear icons in the section headers instead of extra "reset" rows; sticky action bar with Calculate (disabled with the wait text while a simulation runs, 2 s cooldown) and reset; share/load messages via `Dialog.alert`. Field names unchanged (controller and share codes untouched). Page height ~1500 → ~1000 px.
- Layout only in `game.css` (`.sim`, unlayered): tables, inputs and buttons keep the theme's look. Keys `bs_swap`, `bs_share_code` (de, en).

## 79. Bots

- Optional bot players per universe (uni settings category `bots`: `bots_enabled` default off, `bots_max_count`, `bots_ticks_per_run`, `bots_llm_enabled`, `bots_llm_daily_limit`). Game logic in `src/Game/Bots/` (container services, `App\Game\Bots\` block in services.yaml): `BotEngine` (sessions by profile activity per server hour, shift rotation), `BotBrain` (scored candidates × profile weight × per-bot personality × noise, skill-weighted top-3 pick, mistake roll that shrinks with skill), `BotActions` (buildings, research, shipyard, defense, expeditions, colonisation with the same rules/costs/queue limits as the controllers), `BotSpawner`, `BotRegistry`, `BotLog`.
- LLM only for the human layer (`BotPersona`: nickname, persona, planet names; `BotSocial`: replies to private messages, buddy requests, alliance apply/found/accept, neighbour greetings), daily call budget per bot, local fallbacks without a key. `Llm\LlmClientFactory`/`LlmClient`: OpenAI, Claude, OpenCode Go, DeepSeek, OpenRouter (default base URLs, `max_completion_tokens` for OpenAI, `x-opencode-session` for OpenCode Go, stored key never sent to another base URL).
- Admin page `admin?page=bots` (`BotsController`, `admin/BotsPage.html.twig`, menu entry `adm_nav_bots` with tabs providers/profiles/bots): provider keys/models/base URLs with a test-connection button (JSON), profile CRUD/duplicate/restore defaults, spawn/list/remove bots, action log; session CSRF token on all POSTs.
- Tables `bot_llm_providers`, `bot_profiles` (5 defaults: office worker, jobless, shift worker, lunatic, noob), `bot_players`, `bot_log`, cronjob `bots` (every minute, `BotCronjob` ticks each universe with bots enabled) — migration 1794900000 + install.sql. Inactive mails skip bots.
- Fix: `ConfigFieldProcessor::processPostData()` skips `TYPE_NONE` fields, so saving the settings no longer resets `last_*_pos`/`users_amount`.

## 80. Admin information page

- Rebuilt `admin?page=information` on `App\Game\Core\ServerInfo` (read-only, via `GameUtilities::serverInfo()`). It shows:
  - health checks: PHP, extensions, OPcache, env/debug, display_errors, writable dirs, install tool, pending migrations, Rust engine, cron, HTTPS, mail, session handler;
  - server facts: OS, limits, CPU, load, uptime, disk/memory/OPcache meters;
  - database facts: size, top tables, migrations, MySQL status;
  - game statistics per universe plus totals, and a 30-day registration bar chart;
  - logs/caches: errors in the last 24 h, read from at most 2 MB of the log tail;
  - a copy-as-text block without secrets.
- Keys `adm_info_*` (de, en); admin.css block for the health list, meters, bar chart and log tail. Median ~80 ms.

## 81. Br0Game branding and version scheme

- Game name and links point to Br0Game / github.com/Voidys/Br0Game. This covers the admin bug-report text in all locales, installer texts, FAQ, the dashboard project link, error reports, user agents, dump file names (`br0gameBackup_`), composer metadata (`voidys/br0game`) and the DDEV project `br0game`. Credits, license headers, historic Hall of Fame entries and pr0game wiki links are kept.
- Defaults: `source_url` = repository, `git_issues_link` = GitHub issues. Migration 1794900100 fixes existing installs that still have empty, placeholder or Codeberg values.
- Version: `install/VERSION` is the single source, year scheme `1.<yy>.<patch>` (start: 1.26.0). `Maintenance::clearCache()` copies it into the config instead of forcing `<major>.<minor>.git`.

## 82. Easy install

- Setup wizard for non-technical users:
  - database step: defaults; prefix and migration password under "Advanced", the password generated and shown once; plain-language connection errors;
  - game step: language, timezone, universe name, speed preset (classic 1×, fast 3×, blitz 10×), registration, bots 0–50, mail off by default;
  - admin step: password confirmation, minimum length 8;
  - final checklist: login, cron line, lock, HTTPS, docs.
- Shared steps live in `App\Runtime\InstallService`. It generates `APP_SECRET`, sets prod mode outside DDEV, and writes `.env.local` and `config/game.php` with mode 0640.
- `bin/console app:install`: the same steps from the CLI, interactive or `--no-interaction`. It refuses on an existing install.
- `install.sh`: interactive or flag-driven. Modes: DDEV trial, server install, requirements check. It verifies the Composer installer signature, offers `--dry-run`, never runs sudo itself, and updates existing checkouts.
- `tools/release/build.sh`: release ZIP with vendor, prod `.env` and no `.env.local`, plus a `.sha256` file. `.github/workflows/release.yml` builds and attaches both on `v*` tags. `docs/INSTALL.md` covers the ZIP, script and DDEV paths, checksum verification, docroot `public/`, cron, updates, a security checklist and troubleshooting.

## 83. Shared theme artwork, WebP images

- `App\Game\Core\ThemeAssets` + `theme_asset()` (Twig) / `Theme::asset()` (PHP): theme image lookup = theme folder, the `assetSets` declared in its `style.cfg`, `styles/resource/theme-shared/common/`; each folder tries the exact path, then `.webp`. Per-theme map cached in `var/game/theme-assets-<theme>.php`, rebuilt when a folder mtime changes. All `{{ dpath }}…` image paths in templates, `GalaxyRows`, `GalaxyController`, `FleetDealerController` and the message/trader JS go through it (JS gets resolved URLs). Docs: `docs/themes.md`.
- Theme artwork stored once: `theme-shared/common` (identical in all/most themes), `theme-shared/sets/nebula` (DarkNebula, MinimalNoir, community, community pr0gress), `sets/classic` (nova, EpicBlueXIII, gow). 3193 duplicate files removed from the theme folders, byte-identical resolution verified for 4313 theme x path pairs.
- `tools/images/convert-theme-images.sh`: shipped JPG/PNG/GIF to WebP q90 (animated GIFs stay animated), size limits per category, originals deleted when the WebP is smaller; `tools/images/dedupe-theme-assets.php`: the dedupe step, with `--apply` and a built-in hash verification.
- `theme.css` url()s and the few hard-coded resource image references point to the `.webp` files.

## 84. Br0Game theme and content layout

- New theme `public/styles/theme/br0game/` ("Br0Game"). It is the default for new installs and players (`DEFAULT_THEME`, `default_theme`, install.sql). Existing players keep their theme. Palette, contrast values and rules are in DESIGN.md.
  - Look: a dark green-tinted CSS ground, layered surfaces, an emerald accent for state only, themed scrollbars/selection/caret/focus, and tabular numbers.
  - Artwork comes from the `nebula` asset set.
- Theme hooks (fallbacks keep every other theme unchanged):
  - signal colours and overview mission colours become CSS custom properties, emitted inline only when the player changed the factory default;
  - `--galaxy-pos-*` for galaxy position states, `--c-accent-ink`, `--shell-bar-shadow`.
- Content-area layout (shared, all themes):
  - build lists (buildings, research, hangar, defense, repair dock) as a title bar + artwork/requirements + a fixed action column (~25–30 % shorter, button always in the same place); one column with a thumbnail on phones;
  - empty queue slots collapsed into one row; build filters as a segmented control (no `|` separators);
  - overview status and planet in one row, planets/moons as an auto-fill grid;
  - galaxy navigation as a compact toolbar with chevron buttons (same fields);
  - fleet Continue rows sticky at the viewport bottom; an empty fleet table takes one row;
  - message categories as uniform chips (colour dot, count, full labels on desktop); neutral message rows with unread = raised + bold; row actions as Lucide icons (`star`, `reply`, `forward`, `chevron-up` added to the sprite).
- Shell (top bar, side panel, drawer, footer) structurally unchanged.

## 85. Legacy cleanup (phase 1a)
- Removed local leftovers of the old development branch (untracked `cache/`, `includes/`, `juliaBattleEngine/`, `stats_Universe_1.json`); the game cache lives in `var/game/`.
- Removed unreferenced images: `fancybox/`, `facebook/`, `battlesim/` (art), `banned.png`, `banner.jpg`, `meta.png`, `steem.png`, `pr0game_old.gif`, `icons/closed.png`, `icons/open.png` (+ generated `.webp` copies).
- `galaxy.js`: the userscript-style `GM_getValue`/`GM_setValue` shim became `galaxyStore.get/set` (same `<uniN>_<key>` localStorage keys and JSON format, storage errors handled).
- Removed 8 never-rendered templates (admin officiers editor, mod version, vertify x2, admin ticket create, alliance rename name/tag, login rules.default) and unused `HTTP::getPageResponse()`, `Theme::getTemplatePath()/isCustomTPL()`, `MarketPlaceController::checkTechs()`.

## 86. Test foundation (phase 1b)
- PHPUnit 13 as dev dependency (`phpunit.dist.xml`, `tests/bootstrap.php`, `composer test` / `composer test:unit`, autoload-dev `App\Tests\`).
- `tests/Unit` (no database): `HTTP::_GP` casting/encoding, `PlayerUtil::isNameValid` (player + planet names), fleet string format, reference-ratio format.
- `tests/Integration` (kernel + game booted like `bin/console`, dedicated database `db_test`): the schema is rebuilt per run by `tests/Support/setup-database.php` from `install.sql` + migrations + default config through `InstallService::runBuildTask`; every test runs in a transaction that is rolled back; refuses any database whose name does not end in `_test`.
- Golden values captured from the pre-renewal code (`GOLDEN_CAPTURE=1`, see `tests/Support/GoldenAssertions.php`): `BuildFunctions` prices/times/max constructible/catch-up, `ResourceUpdate` production, energy, storage, eco hash and every `ProdGrid` formula (the `eval()` ones), build/research/shipyard queue structures (serialize format) and their progression, `FleetFunctions` distance/duration/consumption/speed/room/ranges, market conversion + push detection, expedition value and findable ships.
- Battle: PHP engine seeded (`mt_srand`) golden fights, fights decided without luck identical in the PHP and Rust engines, invariants for both, statistical agreement of Rust vs PHP (the Rust engine has no seed).
- `config/packages/doctrine.yaml`: removed the `when@test` `dbname_suffix` (the test database is `db_test` via `.env.test`).
- CI job `tests` (PHP 8.4, MySQL 8.4 service, `db_test`).

## 87. HttpClient, no `@`, zero PHPUnit warnings (phase 2c)
- `curl_*` replaced by `symfony/http-client` (`HttpClientInterface`, injected): `LlmClient` (+ `LlmClientFactory`; POST, 10 s idle / 30 s total, no redirects, same headers, errors still `HTTP <status>: <message>` with the key masked), `Discord` (optional constructor argument, default client; failures swallowed as before), `DisposableEmailDomainsCronjob` (https only incl. redirects, 10 MB cap, same user agent), reCAPTCHA verification in `GameBootstrap`, multi-universe probe in `UniverseController` (no redirects, TLS check off only for localhost).
- New `App\Runtime\SafeFile` (read/mtime/write/mkdir/remove/touch/chmod/imageSize on `symfony/filesystem`): checks first, atomic writes with the final permissions (secret files never world-readable), real failures go to the error log; all file-system `@` suppressions (Maintenance, ThemeAssets, ImageProcessor, ServerInfo, installer, GameView, RuntimeContext, news/info controllers, ...) replaced; `@` on `ini_set`/`set_time_limit`/`date_default_timezone_get`/`sys_getloadavg`/`opcache_get_status`/`disk_*_space` dropped; the forbidden-text regex check of `BotSocial` swallows the PCRE warning in one scoped handler.
- `BattleEngineRust::parseRustJson`: statistics/loss accumulators initialised instead of `+=` on missing keys (results unchanged).
- Tests: `LlmClientTest` (request shape per provider, `max_completion_tokens` for openai, `x-opencode-session`, error mapping, key masking), `DiscordTest` (payload, failing webhook); `phpunit.dist.xml`: `failOnWarning=true`, details displayed.

## 88. No unserialize, no eval (phase 2a)
- New `App\Game\Core\StoredData` (`encode`/`decode`/`tryDecode`): structured DB columns are JSON (`JSON_THROW_ON_ERROR`-style, zero fraction preserved, assoc arrays); `decode` also reads legacy `serialize()` rows (`allowed_classes: false`, arrays only). It is the only `unserialize()` call left in `src/`.
- Columns converted: `planets.b_building_id/b_hangar_id`, `users.b_tech_queue/rights`, `planet_wreckfield.repair_order`, `log.data`, `raports.raport` (PHP-engine reports; Rust reports were already compressed JSON). All writers (build/research/shipyard/repairdock controllers, ResourceUpdate, PlayerUtil, BotActions, Log, admin rights, battle report writers) call `StoredData::encode`.
- Migration `migration_1794900200.php` + `App\Game\Core\StoredDataMigrator` (PDO, no game bootstrap, runs from the web upgrader and CLI): chunked, idempotent (only `a:<n>:{` rows), each row verified (JSON decodes to the identical structure) and left untouched when damaged/with objects/too long. No column widening needed (JSON is shorter than serialize).
- Cache files (`var/game/cache.*.php`, language packs) are `<?php return [...];` via `App\Game\Cache\Resource\PhpArrayFile` (atomic write, `include`, opcache); files in the old serialized format count as missing and are rebuilt once.
- Game session in the Symfony session: array (`Session::toArray/fromArray`) instead of a serialized object; sessions written before are still read once (`StoredData::decodeLegacySession`, `allowed_classes: [Session]`), so players stay logged in.
- `eval()` removed: `App\Game\Core\FormulaEvaluator` (tokenizer + recursive descent, closures compiled once per process, whitelist: $BuildLevel/$BuildTemp/$BuildEnergy/$BuildLevelFactor, abs/ceil/floor/round/sqrt/pow/min/max/log/exp, + - * / % **, unary +/-, parentheses, trailing ";") used by `ResourceUpdate`, `ResourcesController`, `InformationController`, `BuildingsController`; `ResourceUpdate::getProd` dropped. Results bit-identical (ProdGrid goldens unchanged).
- Tests: `FormulaEvaluatorTest`, `StoredDataTest`, `StoredDataMigratorTest`; `QueueTest` goldens compare the serialize() of the decoded queue (structure unchanged), plus a JSON-format and a legacy-input case.

## 89. Web hardening (phase 2b)
- CSRF everywhere, one mechanism: `framework.csrf_protection` + `App\Security\CsrfPolicy` + `App\EventListener\CsrfListener` (kernel.request, priority 16: after routing, before the firewall, so login/registration/lost password are covered). Every POST/PUT/PATCH/DELETE to a game controller (areas index/game/account/admin) needs the session token (header `X-CSRF-Token`, field/query `_csrf`); a few state-changing GETs are listed in `CsrfPolicy::GET_RULES` (token required too). Failure: 403, JSON for scripts (header present, `X-Requested-With`, `Accept: application/json`, `ajax=1`), a small page otherwise (`csrf_invalid`, de/en). A valid `_csrf` is removed from the request before game code sees it.
- Front end: `public/scripts/app/csrf.js` (imported by `app/main.js` and `login/main.js`): hidden `_csrf` field on submit/`formdata`/`form.submit()`, `X-CSRF-Token` header on every same-origin non-GET `fetch`, `_csrf` query on `sendBeacon`, links with `data-method="post"` submit an invisible POST form (confirm handlers run first). Twig: `csrf_meta()` in all layout heads, `csrf_token()`, `csrf_field()` (`App\Twig\CsrfExtension`).
- State-changing GETs turned into POST: all `getJSON`/`getText` callers that changed state (fleetAjax x4, jump gate, missile destroy, planet rename, message delete/favourite, rebuild protection, admin drop-rights, ACS rename, player card block toggle, diplomacy create) and ~40 action links (logout x3, buddy accept/delete, alliance diplomacy/kick/rank delete, tutorial, admin cronjob/active/universe/flying fleet/multi-IP/maintenance, module switch is now a form). Phalanx popups carry the token in the URL.
- Foreign navigation guard: a top-level cross-site navigation (`Sec-Fetch-Site: cross-site`) into an authenticated game/account/admin page is answered with 303 to the area's start page (battle reports, board and wiki login excepted), so a forgotten GET writer cannot be driven by a link.
- Admin `sid=<session id>` URL parameter removed (Universe, Reset, Rights, AdminShell, templates); the central token replaces it. Admin password prompt: POST body only (was GET too), 5 attempts per 15 min per IP+account, session id renewed when access is granted.
- Client IP: `SessionManager::getClientIp()` = `Request::getClientIp()`, validated (`UNKNOWN` otherwise); `framework.trusted_proxies`/`trusted_headers` from env `SYMFONY_TRUSTED_PROXIES` (default none). Client-IP/X-Forwarded-* headers of untrusted peers are never read.
- Sessions: `cookie_samesite: lax` (httponly, secure auto and strict mode were already on); id migrated by Symfony on login and logout, explicitly on admin elevation (`SessionManager::regenerateId()`); `SessionManager::revokeAccountSessions()` ends all game sessions of an account on password change/reset.
- Throttling (`symfony/rate-limiter`): `login_throttling` on the main firewall (5 per 15 min per IP+e-mail, `?code=4`, text changed), limiters `registration`, `lost_password`, `admin_elevation` (`App\Security\AuthRateLimits`). Login failures answer `?code=1` for known and unknown accounts alike (per-account `code=4` removed); lost password answers identically for unknown/known/recent requests and only works with working mail (no on-screen reset link).
- Tokens: reset/verification keys and generated passwords from `random_bytes` (`getRandomString()`, lost password, registration, battle simulator report id); reset links expire after 30 min (existing), verification links after 3 days (checked on use); mail links built from `DEFAULT_URI` (`App\Runtime\CanonicalUrl`), not the Host header.
- `SecurityHeadersListener`: `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy` (no CSP yet).
- Tests: `CsrfListenerTest` (valid/invalid/missing token, ajax JSON, GET rules, foreign navigation), `ClientIpTest` (trusted proxies, garbage headers).

## 90. Remaining audit fixes

- Alliance website/image links: only absolute http(s) URLs are stored and rendered (`AllianceController::httpUrlOrEmpty()`). Migration 1794900300 clears stored `javascript:`/`data:` values (audit XSS-1).
- Error page: exception message, file, URL and trace go into the HTML comment only in debug mode (`ErrorReporter` gets `%kernel.debug%`), escaped and with `--` neutralised (audit AUTH-10).

## 91. Translations completed (phase 3c)

- All locales (es, fr, pl, pt, ru, tr, pi, of) now carry every key of `en`/`de` in every domain (about 6800 keys added: admin, install, ingame, public, account, tech, faq, tutorial, fleet); es/fr/pl/pt/ru/tr translated, pi/of take the German text where no dialect text exists.
- Stale duplicate key `shortDescription.231'` (stray apostrophe) removed from `tech.de.yaml`.

## 92. Non-game frontend without inline code (phase 3b)

- Admin, account, login and installer templates have no `on*=` handlers, `javascript:` links or inline scripts left (CSP `script-src 'self' 'nonce'` clean); only JSON data islands and module scripts remain.
- New `templates/shared/page_config.html.twig` + `scripts/page/config.js`: server clock, language strings and flags as a JSON island (`#page-config`), published as the globals the shared modules read; `readPageData(id)` for per-page islands (`#login-config`, `#bots-config`, `#config-uni-data`, `#captcha-config`).
- Admin behaviour by data attributes in `scripts/admin/main.js` (`data-open-edit`, `-autosubmit`, `-set-checked`, `-check-all`, `-count-chars`, `-toggle-rows`, `-reveal`, `-goto-page`), `filterSelect.js` (replaces the 2003 `filterlist.js`), `ajaxForm.js` (`data-ajax-dialog`); one page module per complex page in `scripts/admin/pages/` (configUni incl. former `Percentslider.js`, bots, team, dump, reshuffle, sendMessages, active, tickets, createTestUser).
- Confirm questions: `data-confirm` sits on the submit button / link (asked once by `app/actions.js`), no longer on forms; admin fills the empty generic text. Removed duplicate confirm code from `admin/main.js` and `account/board.js`.
- Login: `[data-autosubmit]` selects, time zone preselection and `LoginConfig` moved into `login/main.js`/island; dead `Login.setLanguage`, `loginError`, `toggleBlock`, `execscript` (non-game layouts) removed. Installer language select and the four legacy table pages (update, doupdate, migration, message) now use `ins_layout`; `scripts/install/main.js`.
- Account popup layout: reCAPTCHA loader is `scripts/account/recaptcha.js` (needs `https://www.google.com` in `script-src`/`connect-src`/`frame-src` while it exists); `ErrorReporter` page lost its dead inline script.

## 93. Content-Security-Policy, no inline handlers in the game (phase 3a)

- `Content-Security-Policy` on the HTML of every area (`App\Security\ContentSecurityPolicy`, `EventListener\CspListener`): `default-src 'self'`, `script-src 'self' 'nonce-…'`, inline styles allowed (style="" attributes, signal colour `<style>`), `img-src 'self' data: https:` (alliance logos/avatars), `connect-src/font-src 'self'`, `frame-ancestors/base-uri/form-action 'self'`, `object-src 'none'`. Per-request nonce (`CspNonce`, Twig `csp_nonce()`; the importmap is the only inline script).
- `CSP_MODE=enforce|report|off` (default enforce; areas in `app.csp.enforced_areas`, all five by default, the rest report-only); violations go to `POST /csp-report` (`CspReportController`, rate limited 30/min per IP) and `var/log/csp.log`.
- reCAPTCHA sources (google.com, gstatic.com in script/frame/connect-src) are opened only for pages that call `csp_allow('recaptcha')` (game and account layouts when a key is configured).
- Game templates: 0 `on*=` attributes (was 198 in 46 files), 0 `javascript:` links (was 26), 0 inline `<script>` (was 34 files). Behaviour by data attributes and one delegated listener per event type (`public/scripts/app/actions.js`: `data-action`, `data-on-change|input|keyup|focus|blur|submit|error`, `data-confirm`, `registerActions()`); built-in actions for dialogs, popups, auto-submit selects, collapsible rows, char counters, row filters, fill-input.
- Template data reaches modules as JSON data islands (`data_island(id, data)`, JSON_HEX_* escaped; `pageData(id)` in `app/core.js`); layout clock/language values in `#game-env` (`app/env.js`), resource tickers in `#resource-tickers`, reCAPTCHA loader `app/recaptcha.js`. `execscript`/`addInlineScript` no longer used by the game (4 callers replaced).
- New page modules: settings, alliance, acs, achievements, techtree, tutorial, playercard, logout; `var` -> `let` in app and game scripts. Dead scripts removed: `trader.js`, `vertify.js`, `install.js`.
- Stored message texts and old translation strings with `onclick="Dialog.Playercard(…)"` keep working (legacy shim reads the blocked attribute); PHP-built links (fleet table, tutorial link, ticket back button) use data attributes.
- Fixes found on the way: receiver search list of the write dialog never became visible and went stale on the second open; galaxy `annotatehover` crashed on a tooltip-less cell; alliance "leave" link lacked `data-method="post"`; phalanx/overview-actions/message modules now re-init on every modal load.
- Translations: the two alliance message texts (`al_leaving_msg`, `al_new_apply`) in all ten `ingame.*.yaml` now use `data-action="dialog-playercard" data-id="%s" data-name="%s"` (same three placeholders). Old stored messages are rewritten when shown by the Twig filter `legacy_markup` (no CSP report); `actions.js` keeps a click fallback. `javascript:history.back()` buttons (ticket, login/account register) became `data-action="history-back"`. No `on*=` or `javascript:` is left in `src`, `translations` or `templates/texts`.
- Layout tables: fleet step 1 submit row (`div.form-actions`), fleet step 2 mission choice (`div.mission-list`), repair dock and shipyard info boxes (`p#infobox`), alliance chart wrapper (plain `div`). Header-strip tables (custom fleets toggle, alliance "back") stay tables: as buttons/divs they lost the theme's header look.

## 94. PlayerUtil split (phase 4b1)

- `PlayerUtil` (2827 lines) is a facade of 406 lines; same public methods and signatures, each delegates to one of 11 services in `App\Game\Core\Player\*` (built through `GameUtilities`, same `Database`/`ConfigRegistry`/`Singleton` constructor): `PlanetPositions` (free slot, random/block/linear position strategies, astrophysics limits), `PlanetManager` (create planet/moon, delete planet, clear), `PlanetReshuffle` (admin reshuffle), `PlanetQueries` (main planet, planet list, field limit), `PlayerRegistration` (create player, name validation), `PlayerDeletion` (delete player, alliance and buddy cleanup), `PlayerMessenger` (send/forward messages), `VacationMode`, `PlayerLookup` (colours, stb, buddy/block status, auth level, language), `ProtectionRules` (catch-up, noob/strong/outlaw/rebuild protection, status), `HonorPoints`, `PlayerCharts`.
- Long methods shortened (createPlayer and deletePlayer are ~85 lines only because of their 16-parameter signature and flat delete sequence): `createPlayer`, `createPlanet`, `createMoon`, `deletePlayer`, `removeFromAlliance`, `sendMessage`, `disable_vmode`, `randomHP`, `calculate_honorpoints_multi`, `checkNoobProtection`, `reshuffleAllPlanets` split into private steps; default colour table is a constant. SQL, order of queries and random calls unchanged. All public methods and non-trivial private methods have docblocks.
- New `PlayerLifecycleTest` (colony/moon, position rules, messages, vacation round trip, protection rules, player deletion).

## 95. AllianceController split (phase 4b2)

- `AllianceController` (2631 lines, 33 handlers with SQL and rules inline) is the HTTP adapter only (672 lines): same `#[GamePage]` routes, modes, `?action=` admin dispatch, request parameters and template variables; the domain logic moved into `src/Game/Alliance/` (namespace `App\Game\Alliance`, reached through `AllianceServices`, built with the controller's db/configs/state like the `GameUtilities` objects).
- Services: `AllianceRepository` (alliance/rank/member/receiver queries, `rightsOf()`), `AllianceMembershipService` (found, leave, kick, transfer, disband), `AllianceApplicationService` (search, apply, withdraw, list/detail, accept/decline with the transaction), `AllianceRankService` (permission page, create/update/delete ranks, assign ranks, `AllianceRights::canHandOut()` replaces five copies of the rights loop), `AllianceDiplomacyService` (relations, overview, create/accept/reject/delete, scoping checks of GB-10/12/30/31/34), `AllianceSettingsService` (admin overview save), `AllianceMessenger` (application/leave notices, circular, per-language diplomacy mails: one grouping loop instead of five), `AllianceMemberLists` (member, main/wing and admin lists from one row builder instead of three copies), `AllianceStatistics`, `AlliancePages` (info/home template variables), `AllianceText` (`html()`, `multiline()`, `httpUrlOrEmpty()`), `AllianceRights` (right names).
- Early exits (rights, rule violations, message pages, JSON errors) are thrown as `AllianceException` by the services and turned into redirect/message/JSON by `AllianceController::exitWith()`; services never end the request.
- Fixed on the way (both covered by `AllianceServicesTest`): applying to a non-existing alliance id no longer reads array offsets of `false` (it redirects to the alliance home instead of answering "alliance full"); the admin member list no longer fatals for a leader/member without a statistics row (`+= false`), it uses the 0-points fallback of the other lists.
- Circular dialog: the rank select now lists the alliance's ranks by their real rank id (`AllianceRepository::rankNames()`, same data as the rank admin page); the send side already filtered receivers by `ally_rank_id` (it never worked before because the select only offered "all players").
- Diplomacy accept/delete/war messages: the two receiver lists were merged with array `+` (by index); they are now merged with `array_merge` and de-duplicated by user id, so every member of both alliances gets exactly one message.
- New `AllianceServicesTest` (35 tests): founding and name rules, apply/withdraw/accept/decline/full/elsewhere, rank rights (grant/update/assign limited to held rights, leader never ranked, scoping to the own alliance), kick rules (leader, self, foreigner, higher rights), transfer, leave, disband, diplomacy scoping and peace flow, circular, member lists, settings, link sanitising. Verified against the old controller: 23 alliance GET pages render byte-identical (rich data set), 64-step HTTP flow (create to disband) passes.

## 96. FleetFunctions and ResourceUpdate split (phase 4b3)

- `FleetFunctions` (1260 lines) is a facade (same public signatures, `$allowedSpeed` stays there) over seven services: `FleetMath` (distance, speed, duration, consumption, room, slots, missile/phalanx range), `FleetString` ("id,count;" format), `FleetMissionRules` (available missions, stay blocks, phalanx rules), `BashRules`, `FleetLauncher` (`sendFleet`, `sendFleetBack`, `getCurrentFleets`), `AcsService` (group timing), `FleetStatistics` (max factors, total fleet state).
- `sendFleet()` split into planet cargo update, flight insert, event insert and fleet log insert (one shared parameter array); `sendFleetBack()` into load/recall/mark-returning; `getAvailableMissions()` into pirate base / recycling / planet mission methods. SQL and parameters unchanged.
- `ResourceUpdate` (1239 -> 280 lines) is the orchestrator (order: shipyard, repair job, wreckfield, building/research completions by end time, production, save). It delegates to `ResourceProduction` (eco hash, `rebuild()`, `produce()`, research network level; formulas via `FormulaEvaluator`), `ResourcePersistence` (planet save with achievements/stats, resource and queue updates), `WreckfieldMaintenance` (72 h repair deployment and wreckfield expiry), `QueueFailureNotifier` (queue failure messages) and the queue state machines `HangarQueueProcessor`, `BuildingQueueProcessor`, `ResearchQueueProcessor`, which work on a shared `QueueState` (user/planet rows, built deltas, target time, stock-update callback; same copy semantics as the old properties). Public `ResourceUpdate` API unchanged.
- Research queue "not progressing" investigated: no bug. A planet whose `last_update` lies in the future (my test had set a queue end time ahead of `b_building`, which pushed `last_update` forward) is skipped entirely, queues included; the original code (a64f9ce5) has the same `ProductionTime > 0` gate. Research completes on any page load of its owner, also on another planet than `b_tech_planet`; documented in the `ResourceUpdate` docblock and pinned by two tests.
- `ResourceProductionTest` reflects `ResourceProduction::runFormula()` now; all other goldens unchanged.

## 97. Statbuilder, marketplace, messages, expedition, account editor, creator split (phase 4b4)

- `Statbuilder` (987 lines) is a facade (same public methods) over `App\Game\Statistics`: `StatDataLoader` (input queries), `StatScores` (research/building/defense/fleet formulas), `StatPointsWriter` (batched upserts), `StatRanker` (category ranks, honor ranks, honor titles), `StatRecords`, `StatArchive`, `StatJsonExport`; `generateStats()` split into hidden check, row fill and alliance totals. New `StatbuilderTest` pins the written statpoints rows (stat 1 and 2, honor titles), records and history on fixture data (captured before the split).
- `MarketPlaceController` (887) keeps `show()`; `App\Game\Market`: `MarketRules` (slots, vacation, diplomacy filter, push check), `MarketPurchase` (the 370-line `doBuy()` split into load/validate/plan trip/send/re-route/notify; unreachable duplicate wanted-resource check dropped), `MarketOfferListing`, `MarketTradeHistory`.
- `MessagesController` (846) is the HTTP adapter over `App\Game\Messages`: `MessageMailbox` (favourite, delete, bulk actions, recipient search), `MessageComposer` (receiver filtering, forbidden-content ban, send/forward), `MessageListing` (categories, filter, grouped paging, row data).
- `MissionCaseExpedition` (946) keeps draw/dispatch/report/return; the events live in `App\Game\Missions\Expedition` (`ExpeditionResourceEvent`, `ExpeditionShipEvent`, `ExpeditionCombatEvent`, `ExpeditionCombatReport`, `ExpeditionFleetValue`, `ExpeditionSize`, shared `ExpeditionContext`); RNG calls and their order unchanged. `MarketAndExpeditionTest` reflects `ExpeditionShipEvent::determineFoundShips()` now.
- `AccountEditorController` (990) and `CreatorController` (838) only pick the tab: one class per tab in `App\Game\Admin\AccountEditor` / `App\Game\Admin\Creator` (planets and alliances saves split into collect/write steps), pages and messages through `AdminPageResponder` (`ClosureAdminResponder`).
- Fixed: creating a second moon in the admin creator reported success (`createMoon(): int` turns `false` into 0); the check is now `> 0`. New `MessagesAndCreatorTest` (PM send with string ids, second moon, expedition stay event).
- Round 2 of step 97: `AlliancesTab` deletion fixed (members were released with the wrong placeholder `:id` instead of `:userId`; the statistics log now records each row's own "after" data instead of the whole list), `deleteAlliance()` split into release members / detach statistics / delete related rows; removing a member (`delete_u`) selected a non-existing `userId` column of users, now `id`; `PersonalTab` without a changed field no longer builds an empty `SET` (no-op, still logged as success). `AccountEditorTabsTest` covers all three.
- `MarketPurchase` is covered by `MarketPurchaseTest` (buy a resource offer: paid resources, buyer fleet, re-routed seller fleet, trade link, messages; refusals: unknown/own offer, push prevention, not enough ships).
- `MissionCaseAttack` (729) and `MissionCaseDestruction` (748) share `AbstractBattleMission` (target lock, participants, losses, debris, result stats, report, messages, loot, top-KB; split into `BattleFleetHandling` and `BattleReporting` traits and the `BattleSetup` value object); each class keeps its own `TargetEvent()` order (moon roll for the attack, moon/fleet destruction for the destruction). `BattleMissionTest` pins the rows of an attack on a planet and a moon and of a destruction without death stars (captured on the old classes).
- `FleetStep3Controller` (698) hands the dispatch to `App\Game\Fleet\FleetDispatch` (form/session, validation, ACS, target, mission rules in `FleetDispatchMissionRules`, flight plan, launch, trade offer); refusals are `FleetDispatchRefused`, shown by the controller as the same message page.
- `SettingsController` (767) hands `send()` to `App\Game\Settings\SettingsUpdater`: the 76 plain settings are a column to request-parameter/default table, the special ones (language, decimal separator, themes, hangar filters, spy hours, spy count, fleet actions, messages per page) are validated individually; the save is verified by posting the unchanged settings form back (row identical).
- `ServerInfo` (787) is a facade over `ServerInfoSystem`, `ServerInfoDatabase`, `ServerInfoGame`, `ServerInfoLogs` and `ServerInfoFormat` (`App\Game\Core\ServerInfo`); the information page renders identically.
- `BotSocial` (748) keeps the turn and the buddy step; `BotSocialMessaging` (replies, greetings, sending, language/situation helpers) and `BotSocialAlliance` (apply, found, applications) are injected services; `BotSocialTest` runs 40 social turns.
- Fixed on the way: `AdvancedStatsHandler::getValue()` and the honor lookups read `['statvalue']` of a missing row ("array offset on false" warnings in every fight of a player without stats; covered by `BattleMissionTest`).
- Admin player search: the page size is limited to the offered values (an empty `limit` caused `LIMIT x,0` and a division by zero).

## 98. Typed User/Planet read models (phase 4a)

- New `App\Game\Model\User` and `App\Game\Model\Planet` (final readonly): typed snapshots of the `$USER` / `$PLANET` state arrays (ids, coordinates, type, temperature, fields, stocks with production/storage/bonus/energy, queue end times and raw queue strings, protection/vacation/ban/outlaw data, points, bonus factors; building levels, tech levels and ship/defense/missile counts as element maps behind `level()`, `count()`, `has()`, `productionRate()`). Built with `fromState($row, $resource)` or `$this->models()->user()/planet()` (`ModelFactory`, via `GameUtilities`); missing or NULL keys read as 0. Snapshots only: mutations still go through the arrays.
- Typed service methods, old array signatures kept as `@deprecated` adapters (convert and delegate): `BuildFunctions` (`elementPriceFor`, `restPriceFor`, `isBuyableFor`, `isAccessibleFor`, `buildingTimeFor`, `maxConstructibleFor`, `maxRocketsFor`), `ResourceProduction` (`hashFor`, `rebuildFor`, `networkLevelFor`; the eco hash string is unchanged), `FleetMath`/`FleetFunctions` (`maxFleetSlotsFor`, `expeditionLimitFor`, `fleetMaxSpeedFor`, `missionDurationFor`, `fleetConsumptionFor`, `fleetShipInfoFor`), `ProtectionRules`/`PlayerUtil` (`catchUpFactorFor`, `spyTechAllowedFor`, `checkNoobProtectionFor`).
- Call sites moved to the typed versions: queue processors (building, research, hangar), `ResourceUpdate`, noob-protection checks (galaxy, fleet dispatch/step 2/missile/ajax, statistics, alliance members, mission rules), spy-tech checks (player card, charts, alliance applications), fleet planning (`FleetDispatch`, `FleetStep2Controller`, `MarketPurchase`).
- Reference aliases: 674 of the 787 `$X = & $this->state->X;` aliases (293 for USER/PLANET) became plain reads, found by an AST script (variable never written, passed by reference or captured by reference, and no call into a method that writes that state member); 113 remain (61 USER/PLANET).
- Tests: `UserTest`, `PlanetTest` (mapping, casts, defaults, snapshot semantics) and `TypedModelServicesTest` (typed versus array results, legacy eco-hash formula).
- Fixed: an unknown admin page for a not-yet-elevated admin crashed (the hand-built error page had no rate limiter); `GameEntryController` now passes the security services.

## 99. Formatted translations (phase 4c)

- `Language::format()`, `formatHtml()` (arguments escaped without double encoding, Markup/numbers kept) and `formatIn()` (nested keys) replace every `sprintf($LNG['key'], ...)` in `src/` (about 180 call sites in 70 files); templates use `LNG.format('key', ...)` (`LanguageView::format()`, trusted Markup) instead of `|format`/`sprintf(LNG.key, ...)`. `AllianceText::html()` takes the language and key.
- The helper counts the printf placeholders (`Language::placeholders()`/`placeholderCount()`, `%%` ignored, positional `%1$s` supported) and throws a `LogicException` on a mismatch when `Language::$strictFormat` is set (kernel debug via `ErrorReporter::register()`, always in PHPUnit); in production it logs and pads/cuts the arguments.
- Escaping rule: `formatHtml()` for player-controlled values in HTML/stored messages (buddy requests, alliance applications/leaving/circulars/treaty texts); `format()` for plain text and already-safe values. See docs/templates.md "Formatted texts".
- Latent bugs found by the placeholder scan and fixed: texts with a missing or broken placeholder (`al_request_deleted` in 7 locales, `reg_file`/`reg_dir` es/fr, `sb_stats_update` es/fr/pt/tr `%t`, `ov_security_confirm` fr, `tn_delete_mode` tr, `tn_vacation_mode_until` pt, `al_make_ally_insufficient_points` tr, `al_diplo_war_mes` es/ru (the reason printed the treaty level), `sys_notenough_money` pt (shifted arguments), `fl_attack_confirm_diplo` tr `%1%s`, `sys_mess_spy_report_details_*` tr `1$s`, `bd_need_engine`/`bd_more_engine` ru), `css_account_banned_expire` (all locales now carry `%1$s` date and `%2$s` contact link = the `forum_url` setting; without a forum_url the contact sentence is left out; the hard-coded Discord link is gone), and calls with the wrong argument count (`tn_delete_mode`: the time zone belonged to `formatDate()`, `registerWelcomePMText` passed an unused extra value).
- New `LanguageFormatTest` (formatting, placeholder counting, mismatch handling, escaping, and a scan that every language has the placeholders of English for all texts filled by `format()`).
