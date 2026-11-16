<?php

namespace App\Runtime;

use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Language;
use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Game\Core\Theme;
use App\Game\Core\Universe;
use Exception;

/**
 * The installer and database upgrade tool (formerly install/), unchanged in behaviour.
 * Served at /install/ by App\Controller\GameEntryController::install(), which bootstraps the
 * game in MODE INSTALL or UPGRADE first. Data files stay in install/ (install.sql, migrations/).
 */
final class Installer
{
    use \App\Game\Core\GameUtilities;

    /**
     * @param array<string, string|null> $defaults prefilled form values for unattended installs
     *                                              (DB_HOST, DB_USER, ..., ADMIN_MAIL from the environment)
     */
    public function __construct(
        private Database $db,
        private \App\Game\Core\ConfigRegistry $configs,
        private Singleton $state,
        private InstallService $installer,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire([
            'DB_HOST' => '%env(default::DB_HOST)%',
            'DB_USER' => '%env(default::DB_USER)%',
            'DB_PASSWORD' => '%env(default::DB_PASSWORD)%',
            'DB_NAME' => '%env(default::DB_NAME)%',
            'ADMIN_NAME' => '%env(default::ADMIN_NAME)%',
            'ADMIN_PASSWORD' => '%env(default::ADMIN_PASSWORD)%',
            'ADMIN_MAIL' => '%env(default::ADMIN_MAIL)%',
        ])]
        private array $defaults = [],
    ) {}

    /** @var array<string, mixed> template variables collected by assign() */
    private array $viewData = [];

    /**
     * Entry of the installer/upgrader (`/install/`): loads the config (if any), sets up the installer language, guards
     * the install tool (tool file, 1 hour expiry), authorizes the upgrade mode with the migration password and
     * dispatches the mode (intro, install steps, upgrade page, doupgrade).
     */
    public function run(): void
    {
        $mode = $this->http()->_GP('mode', '');
        $THEME = $this->state->THEME;
        $configAvailable = file_exists('config/game.php') && filesize('config/game.php') !== 0;
        if ($configAvailable) {
            require 'config/game.php';
            if (($mode === 'upgrade' || $mode === 'doupgrade') && !$this->maintenance()->checkDBUpgrade()) {
                $this->http()->redirectTo('../game', true);
            }
        }
        $LNG = $this->setupLanguage();

        $this->assign([
            'lang'       => $LNG->getLanguage(),
            'Selector'   => $LNG->getAllowedLangs(false),
            'title'      => $LNG['title_install'] . ' · Br0Game',
            'header'     => $LNG['menu_install'],
            'canUpgrade' => $configAvailable,
        ]);

        $enableInstallToolFile = $this->guardInstallTool($mode, $configAvailable);

        $language = $this->http()->_GP('lang', '');
        if (!empty($language) && in_array($language, $LNG->getAllowedLangs())) {
            $this->http()->sendCookie('lang', $language);
        }

        if ($mode === 'upgrade' || $mode === 'doupgrade') {
            $session = $this->authorizeUpgrade($database, $enableInstallToolFile);
        }

        switch ($mode) {
            case 'upgrade':
                $this->showUpgrade($LNG);
                break;
            case 'doupgrade':
                $this->doUpgrade($LNG, $session, $enableInstallToolFile);
                break;
            case 'install':
                $this->install($LNG, $enableInstallToolFile);
                break;
            default:
                $this->assign(['currentStep' => 0]);
                $this->show('ins_intro');
                break;
        }
    }

    /**
     * Creates the installer language (`?lang=`, else the `lang` cookie, else English), uses the nova theme and loads
     * the installer texts into `state->LNG`.
     */
    private function setupLanguage(): Language
    {
        $THEME = $this->state->THEME;
        $THEME->setUserTheme('nova');
        // installer language: ?lang= (also remembered as cookie), the cookie, else English
        $LNG = new Language($this->configs, 'en');
        $language = (string) ($this->state->requestQuery['lang'] ?? $this->state->requestCookies['lang'] ?? 'en');
        if ($language !== 'en' && in_array($language, $LNG->getAllowedLangs(), true)) {
            $LNG->setLanguage($language);
        }
        $this->state->LNG = $LNG;
        $LNG->includeData(['L18N', 'INGAME', 'INSTALL', 'CUSTOM']);

        return $LNG;
    }

    /**
     * Install tool access: a fresh `var/FIRST_INSTALL` or a project without config opens the tool (touches
     * `var/ENABLE_INSTALL_TOOL`), a tool file older than one hour (without KEEP_FILE) is removed, and a missing tool
     * file shows the "tool disabled" page (ends the request) outside the upgrade modes.
     *
     * @return string path of the tool file
     */
    private function guardInstallTool(string $mode, bool $configAvailable): string
    {
        $enableInstallToolFile = 'var/ENABLE_INSTALL_TOOL';
        $quickStartFile        = 'var/FIRST_INSTALL';
        // If include/FIRST_INSTALL is present and can be deleted, automatically create include/ENABLE_INSTALL_TOOL
        if (is_file($quickStartFile) && is_writeable($quickStartFile) && unlink($quickStartFile)) {
            SafeFile::touch($enableInstallToolFile);
        }
        // fresh project (no config/game.php yet): the installer opens by itself; the tool file keeps it open
        // for the steps after the database step, until the administrator is created (or for an hour)
        if (!$configAvailable && $mode !== 'upgrade' && $mode !== 'doupgrade') {
            SafeFile::touch($enableInstallToolFile);
        }
        // Only allow Install Tool access if the file "include/ENABLE_INSTALL_TOOL" is found
        if (is_file($enableInstallToolFile) && (time() - filemtime($enableInstallToolFile) > 3600)) {
            $content      = file_get_contents($enableInstallToolFile);
            $verifyString = 'KEEP_FILE';
            if (trim($content) !== $verifyString) {
                // Delete the file if it is older than 3600s (1 hour)
                unlink($enableInstallToolFile);
            }
        }
        if (!is_file($enableInstallToolFile) && !($mode === 'upgrade' || $mode === 'doupgrade')) {
            $this->show('ins_install');
        }

        return $enableInstallToolFile;
    }

    /**
     * Upgrade modes only: requires the migration password from the config (else removes the tool file and ends with an
     * error), grants the session migration access on the correct posted password and shows the password form
     * otherwise.
     *
     * @param array $database the `$database` array of config/game.php
     * @return \App\Game\Core\Session the session with migration access
     */
    private function authorizeUpgrade(array $database, string $enableInstallToolFile): \App\Game\Core\Session
    {
        if (!array_key_exists('migrationpw', $database) || empty($database['migrationpw'])) {
            unlink($enableInstallToolFile);
            throw \App\Game\Core\EndOfRequest::withResponse(
                $this->gameView()->messagePage('Migration password is not set. Please set migration password in config.php and try again.', false, 3, true),
            );
        }
        $migrationpw = $this->http()->_GP('migrationpw', '');
        if (!empty($migrationpw) && $migrationpw === $database['migrationpw']) {
            $session = $this->sessions()->create();
            $session->migrationAccess = 1;
            $session->save();
        }

        $session = $this->sessions()->load();
        if ($session->migrationAccess != 1) {
            $this->show('ins_migration');
        }

        return $session;
    }

    /**
     * Upgrade page: lists the pending migrations (SQL shown with prefix and database name filled in) with the file
     * revision and the current database version.
     */
    private function showUpgrade(Language $LNG): void
    {
        try {
            $sql    = "SELECT max(dbVersion) as dbVersion FROM %%SYSTEM%%;";
            $dbVersion  = $this->db->selectSingle($sql, [], 'dbVersion');
        } catch (Exception $e) {
            $dbVersion  = 0;
        }

        $updates = [];
        $fileRevision = 0;
        $migrationFileList = $this->maintenance()->listMigrations();
        foreach ($migrationFileList as $fileRevision => $fileInfo) {
            $updates[$fileInfo['pathName']] = makebr(
                str_replace(
                    '%PREFIX%',
                    $this->db->getTablePrefix(),
                    str_replace(
                        '%DB_NAME%',
                        $this->db->getDatabaseName(),
                        $fileContents = file_get_contents($fileInfo['pathName']),
                    ),
                ),
            );
        }

        $revision = end($migrationFileList);

        $this->assign([
            'file_revision' => $revision['fileRevision'],
            'sql_revision'  => $dbVersion,
            'updates'       => $updates,
            'header'        => $LNG['menu_upgrade'],
        ]);

        $this->show('ins_update');
    }

    /**
     * Runs the upgrade: checks that the game tables exist, applies every migration (PHP or SQL), records its version,
     * clears the caches, removes the tool file, ends the session and shows the result page.
     */
    private function doUpgrade(Language $LNG, \App\Game\Core\Session $session, string $enableInstallToolFile): void
    {
        // TODO:Need a rewrite!
        // Create a Backup
        $sqlTableRaw  = $this->db->nativeQuery("SHOW TABLE STATUS FROM `" . $this->db->getDatabaseName() . "`;");
        $prefixCounts = strlen($this->db->getTablePrefix());
        $dbTables     = [];
        foreach ($sqlTableRaw as $table) {
            if ($this->db->getTablePrefix() == substr($table['Name'], 0, $prefixCounts)) {
                $dbTables[] = $table['Name'];
            }
        }

        if (empty($dbTables)) {
            throw new Exception('No tables found for dump.');
        }

        set_time_limit(600);
        $db = $this->db;
        $sql = "INSERT INTO %%SYSTEM%% VALUES (:dbVersion);";
        $fileList = $this->maintenance()->listMigrations();
        foreach ($fileList as $fileInfo) {
            switch ($fileInfo['fileExtension']) {
                case 'php':
                    $this->runPhpMigration($fileInfo, $session, $enableInstallToolFile);
                    $db->insert($sql, [':dbVersion' => $fileInfo['fileRevision']]);
                    break;
                case 'sql':
                    $this->runSqlMigration($fileInfo, $session, $enableInstallToolFile, $sql);
                    break;
            }
        }

        $revision = end($fileList);

        $this->maintenance()->clearCache();

        $this->assign([
            'update'   => !empty($fileList),
            'revision' => $revision['fileRevision'],
            'header'   => $LNG['menu_upgrade'],
        ]);
        unlink($enableInstallToolFile);
        $session->delete();
        $this->show('ins_doupdate');
    }

    /**
     * Runs one PHP migration as a CLI script from the project root (the script is copied there for the run and
     * removed again). A non-zero exit removes the tool file, ends the session and throws with the script output.
     *
     * @param array{fileName: string, fileRevision: mixed} $fileInfo
     */
    private function runPhpMigration(array $fileInfo, \App\Game\Core\Session $session, string $enableInstallToolFile): void
    {
        // PHP migrations run as CLI scripts from the project root (the root is not web-reachable)
        copy($this->state->runtime->rootPath . 'install/migrations/' . $fileInfo['fileName'], $this->state->runtime->rootPath . $fileInfo['fileName']);
        $migrationOutput = [];
        exec(
            'cd ' . escapeshellarg($this->state->runtime->rootPath) . ' && ' . escapeshellarg(PHP_BINDIR . '/php') . ' '
            . escapeshellarg($fileInfo['fileName']) . ' 2>&1',
            $migrationOutput,
            $migrationExitCode,
        );
        if ($migrationExitCode !== 0) {
            unlink($enableInstallToolFile);
            unlink($this->state->runtime->rootPath . $fileInfo['fileName']);
            $session->delete();
            throw new Exception(
                sprintf(
                    'Update error.<br><br>Error on update %s:<br><pre>%s</pre><br><br>'
                    . '<b><i>You should restore with a backup.</i></b>',
                    basename($fileInfo['fileName']),
                    htmlspecialchars(implode("\n", $migrationOutput)),
                ),
            );
        }
        unlink($this->state->runtime->rootPath . $fileInfo['fileName']);
    }

    /**
     * Runs one SQL migration (`%PREFIX%` replaced, queries split at `;\n`): failing single queries are logged and
     * skipped; on any other exception the tool file is removed, the session ended and the error thrown. Records the
     * version on success.
     *
     * @param array{fileName: string, fileRevision: mixed} $fileInfo
     * @param string $versionSql INSERT statement of the version row
     */
    private function runSqlMigration(array $fileInfo, \App\Game\Core\Session $session, string $enableInstallToolFile, string $versionSql): void
    {
        $db = $this->db;
        $sql = $versionSql;
        $data = file_get_contents($this->state->runtime->rootPath . 'install/migrations/' . $fileInfo['fileName']);
        try {
            $queries    = explode(";\n", str_replace('%PREFIX%', $this->db->getTablePrefix(), $data));
            $queries    = array_filter($queries);
            foreach ($queries as $query) {
                if (empty(trim($query))) {
                    error_log('Query: [' . $query . '] is empty. File: ' . $fileInfo['fileName']);
                    continue;
                }
                try {
                    // alter table IF NOT EXISTS
                    $this->db->nativeQuery(trim($query));
                } catch (Exception $e) {
                    error_log('Query: [' . $query . '] failed. Error: ' . $e->getMessage() . '. Skipped');
                }
            }
            $db->insert($sql, [':dbVersion' => $fileInfo['fileRevision']]);
        } catch (Exception $e) {
            unlink($enableInstallToolFile);
            $session->delete();
            throw new Exception(
                sprintf(
                    'Update error.<br><br>%s<br><br><b><i>You should restore with a backup.</i></b>',
                    $e->getMessage(),
                ),
            );
        }
    }

    /** @param array<string, mixed> $variables */
    private function assign(array $variables): void
    {
        $this->viewData = array_replace($this->viewData, $variables);
    }

    /** renders templates/install/<name>.html.twig and ends the request with it */
    private function show(string $name): never
    {
        $context = $this->viewData + [
            'step' => $this->state->requestQuery['step'] ?? null,
            'scripts' => [],
            'execscript' => '',
        ];

        throw \App\Game\Core\EndOfRequest::withResponse(
            $this->gameView()->response('install/' . $name . '.html.twig', $context),
        );
    }

    /**
     * Fresh install, one step per request (the steps themselves are App\Runtime\InstallService, shared with
     * `bin/console app:install`):
     * 1 licence, 2 requirements, 3 database form, 4 connect + create the database + write the settings,
     * 5 build the tables (task by task), 6 game settings, 7 administrator form, 8 create the administrator, done.
     */
    private function install(Language $LNG, string $enableInstallToolFile): void
    {
        $step = (int) $this->http()->_GP('step', 0);
        $this->assign(['currentStep' => $step]);
        match ($step) {
            1 => $this->stepLicence(),
            2 => $this->stepRequirements($LNG),
            3 => $this->stepDatabaseForm(),
            4 => $this->stepDatabase($LNG),
            5 => $this->stepBuild($LNG),
            6 => $this->stepGame($LNG),
            7 => $this->stepAdminForm(),
            8 => $this->stepAdmin($LNG),
            default => $this->http()->redirectTo('./'),
        };
    }

    /** Step 1: shows the licence; accepting continues with the requirements. */
    private function stepLicence(): void
    {
        if (isset($this->state->requestPost['post'])) {
            if (isset($this->state->requestPost['accept'])) {
                $this->http()->redirectTo('./?mode=install&step=2');
            }
            $this->assign(['accept' => false]);
        }
        // the project's licence file (GNU AGPL v3), shown as is
        $this->assign(['licenceText' => (string) SafeFile::read($this->state->runtime->rootPath . 'LICENSE')]);
        $this->show('ins_license');
    }

    /** Step 2: PHP/extension checks and writable paths (see InstallService::requirements()). */
    private function stepRequirements(Language $LNG): void
    {
        $this->assign($this->installer->requirements($LNG->getLanguage()));
        $this->show('ins_req');
    }

    /** Step 3: database form, prefilled from the query string or the unattended-install defaults. */
    private function stepDatabaseForm(): void
    {
        $query = $this->state->requestQuery;
        $this->assign(['form' => [
            'host' => $query['host'] ?? (($this->defaults['DB_HOST'] ?? null) ?: 'localhost'),
            'port' => $query['port'] ?? '3306',
            'user' => $query['user'] ?? ($this->defaults['DB_USER'] ?? ''),
            'password' => $this->defaults['DB_PASSWORD'] ?? '',
            'dbname' => $query['dbname'] ?? ($this->defaults['DB_NAME'] ?? ''),
            'prefix' => $query['prefix'] ?? 'uni1_',
            'createdb' => !isset($query['host']) || !empty($query['createdb']),
            'migrationpw' => $query['migrationpw'] ?? '',
        ]]);
        $this->show('ins_form');
    }

    /** see InstallService::prepareDatabase(); the checks are shown as a list */
    private function stepDatabase(Language $LNG): void
    {
        $form = [
            'host' => trim((string) $this->http()->_GP('host', '')),
            'port' => (string) (int) $this->http()->_GP('port', 3306),
            'user' => (string) $this->http()->_GP('user', '', true),
            'dbname' => trim((string) $this->http()->_GP('dbname', '', true)),
            'prefix' => trim((string) $this->http()->_GP('prefix', 'uni1_')),
            'createdb' => !empty($this->state->requestPost['createdb']) ? '1' : '',
            'migrationpw' => (string) $this->http()->_GP('migrationpw', '', true),
        ];
        $password = (string) ($this->state->requestPost['password'] ?? '');
        $result = $this->installer->prepareDatabase($form, $password, $LNG->getLanguage());
        $this->assign(['checks' => $result['checks'], 'passed' => $result['passed']]);
        if (!$result['passed']) {
            // the generated migration password is not carried back into the form
            $this->assign(['back' => http_build_query(['mode' => 'install', 'step' => 3] + $form)]);
        }
        $this->show('ins_step4');
    }

    /**
     * The progress page runs the tasks one request each (`&task=<name>`, JSON answer) and ticks them off;
     * `&task=all` (no JavaScript) runs everything and continues. A failed task removes config/game.php so
     * the database step can be repeated.
     */
    private function stepBuild(Language $LNG): void
    {
        $locale = $LNG->getLanguage();
        $tasks = $this->installer->buildTasks($locale);
        $task = (string) $this->http()->_GP('task', '');
        if ($task === '') {
            $this->assign(['tasks' => $tasks]);
            $this->show('ins_step5');
        }

        $run = $task === 'all' ? array_keys($tasks) : [$task];
        try {
            foreach ($run as $name) {
                if (!isset($tasks[$name])) {
                    throw new Exception('Unknown task: ' . $name);
                }
                $this->installer->runBuildTask($name, $locale);
            }
        } catch (\Throwable $e) {
            error_log('installer task ' . $task . ': ' . $e->getMessage());
            SafeFile::remove($this->state->runtime->rootPath . 'config/game.php');
            if ($task === 'all') {
                $this->assign(['tasks' => $tasks, 'error' => $LNG['step3_db_error'] . ' ' . $e->getMessage()]);
                $this->show('ins_step5');
            }
            $this->sendJson(['ok' => false, 'message' => $LNG['step3_db_error'] . ' ' . $e->getMessage()]);
        }

        if ($task === 'all') {
            $this->http()->redirectTo('./?mode=install&step=6');
        }
        $this->sendJson(['ok' => true]);
    }

    /** JSON answer of an AJAX build task; ends the request. */
    private function sendJson(array $data): never
    {
        $this->http()->sendHeader('Content-Type', 'application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        throw new \App\Game\Core\EndOfRequest();
    }

    /** game name, theme, universe, language, timezone, speed preset, registration, e-mails and bots */
    private function stepGame(Language $LNG): void
    {
        $locale = $LNG->getLanguage();
        $post = $this->state->requestPost;
        $values = [
            'game_name' => $post['game_name'] ?? 'Br0Game',
            'default_theme' => $post['default_theme'] ?? DEFAULT_THEME,
            'uni_name' => $post['uni_name'] ?? '',
            'language' => $post['language'] ?? $locale,
            'timezone' => $post['timezone'] ?? $this->installer->defaultTimezone(),
            'speed' => $post['speed'] ?? 'classic',
            'registration' => $post['registration'] ?? 'open',
            'mail' => isset($post['post']) ? !empty($post['mail']) : false,
            'bots' => $post['bots'] ?? 0,
        ];

        if (isset($post['post'])) {
            $checked = $this->installer->validateGameSettings($values, $locale);
            $values = $checked['values'];
            if ($checked['error'] === null) {
                $this->installer->applyGameSettings($values);
                $this->http()->redirectTo('./?mode=install&step=7&bots=' . $values['bots'] . '&lang=' . $values['language']);
            }
            $this->assign(['error' => $checked['error']]);
        }

        $this->assign([
            'values' => $values,
            'themes' => Theme::getAvailableCssThemes(),
            'languages' => $this->installer->allowedLanguages(),
            'timezones' => $this->installer->timezones(),
            'speeds' => array_keys($this->installer->speedPresets()),
            'presets' => $this->installer->speedPresets(),
        ]);
        $this->show('ins_game');
    }

    /** Step 7: administrator form (name, password, e-mail, number of bots to create, 0-50). */
    private function stepAdminForm(): void
    {
        $this->assign([
            'username' => $this->defaults['ADMIN_NAME'] ?? '',
            'password' => $this->defaults['ADMIN_PASSWORD'] ?? '',
            'mail' => $this->defaults['ADMIN_MAIL'] ?? '',
            'bots' => max(0, min(50, (int) ($this->state->requestQuery['bots'] ?? 0))),
            'minLength' => InstallService::MIN_PASSWORD_LENGTH,
        ]);
        $this->show('ins_acc');
    }

    /**
     * Step 8: validates and creates the admin account, logs it in (Symfony login plus a game session with admin
     * access), optionally spawns bots (a failure only sets a flag), locks the installer and shows the summary with the
     * cron line and the generated migration password.
     */
    private function stepAdmin(Language $LNG): void
    {
        $locale = $LNG->getLanguage();
        $username = $this->http()->_GP('username', '', UTF8_SUPPORT);
        $password = $this->http()->_GP('password', '', true);
        $confirm = $this->http()->_GP('password_confirm', '', true);
        $mail = $this->http()->_GP('email', '');
        $bots = max(0, min(50, (int) $this->http()->_GP('bots', 0)));

        $errors = $this->installer->validateAdmin((string) $username, (string) $password, (string) $confirm, (string) $mail, $locale);
        if ($errors !== []) {
            $this->assign([
                'errors' => $errors,
                'error' => implode(' ', $errors),
                'username' => $username,
                'mail' => $mail,
                'bots' => $bots,
                'minLength' => InstallService::MIN_PASSWORD_LENGTH,
                'currentStep' => 7,
            ]);
            $this->show('ins_acc');
        }

        $language = (string) $this->db->selectSingle('SELECT `value` FROM %%CONFIG_UNIVERSE%% WHERE `uni` = 1 AND `key` = \'lang\';', [], 'value') ?: $locale;
        [$accountId, $userId] = $this->installer->createAdmin($username, $password, $mail, $language);

        // the new admin is logged in right away (Symfony login + game session with admin access)
        $this->state->auth->login($accountId);
        $session = $this->sessions()->create();
        $session->accountId = $accountId;
        $session->userId = $userId;
        $session->universe = $this->universe()->current();
        $session->adminAccess = 1;
        $session->save();

        $botsCreated = 0;
        $botError = false;
        try {
            $botsCreated = $this->installer->spawnBots($bots);
        } catch (\Throwable $e) {
            error_log('installer bots: ' . $e->getMessage());
            $botError = true;
        }

        $this->installer->lock();
        $this->assign([
            'adminName' => $username,
            'adminUrl' => $this->state->runtime->protocol . $this->state->runtime->httpHost . preg_replace('#install/?$#', '', $this->state->runtime->httpRoot) . 'admin',
            'cronLine' => $this->installer->cronLine(),
            'migrationPw' => $this->installer->takeGeneratedSecret(),
            'botsCreated' => $botsCreated,
            'botError' => $botError,
            'docsUrl' => InstallService::DOCS_URL,
            'https' => $this->state->runtime->https,
        ]);
        $this->show('ins_step8');
    }
}
