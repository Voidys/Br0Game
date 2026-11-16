<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use App\Controller\GameControllerInterface;
use App\Controller\PageModeDispatch;
use App\Controller\RendersPages;
use App\Game\Core\EndOfRequest;
use DateTime;
use DateTimeZone;
use Exception;
use App\Game\Core\NumberAbbreviation;
use App\Game\Core\ResourceUpdate;
use App\Game\Tutorials\TutorialUtil;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base for the in-game controllers (/game?page=...).
 *
 * initialize() runs after the game is bootstrapped: it locks and updates the current player and
 * planet (resource production since the last request) unless $disableEcoSystem is set, and picks
 * the window (full page, or "ajax" for XHR requests). ?mode= is dispatched by PageModeDispatch.
 *
 * Pages render with RendersPages; the game layout gets the navigation (planet list, resources,
 * counters) for full pages and the player/clock settings for every non-ajax page.
 */
abstract class AbstractGameController implements GameControllerInterface
{
    use \App\Game\Core\GameUtilities;

    use \App\Controller\GameServices;

    use PageModeDispatch;

    use RendersPages {
        render as private renderPage;
    }

    /** popups requested by the modal loader (X-Game-Fragment, ?ajax=1) render as fragments (layout.ajax) */
    protected function setWindow(string $window): void
    {
        $this->window = $window === 'popup' && $this->state->runtime->ajax ? 'ajax' : $window;
    }

    public static function getGameMode(): string
    {
        return 'INGAME';
    }

    public static function getEntryScript(): string
    {
        return 'game.php';
    }

    protected static function templateArea(): string
    {
        return 'game';
    }

    protected function printPageError(string $message): never
    {
        $this->printMessage($message);
    }

    /** resource calculation of the current request (null for ajax requests and $disableEcoSystem) */
    protected ?ResourceUpdate $ecoObj = null;

    /** true: skip the resource update of the current planet (pages that do not show resources) */
    protected $disableEcoSystem = false;

    /**
     * Runs before every in-game page. Full page loads (not XHR) lock the player row and then the current planet row (always in that
     * order, like research, empire and fleet code, to avoid deadlocks), recalculate the planet's resources up to now (ResourceUpdate::CalcResource,
     * including finished queues) from the locked rows and commit. Pages that set $disableEcoSystem skip this. XHR requests only select the ajax layout.
     */
    public function initialize(): void
    {
        // pages (also in XHR/fragment mode) debit and credit through it; only the recalculation below is skipped for XHR
        $this->ecoObj = new ResourceUpdate($this->db, $this->configs, $this->state);
        if (!$this->state->runtime->ajax) {
            $this->setWindow('full');
            if (!$this->disableEcoSystem) {

                if (!empty($this->state->USER) && !empty($this->state->PLANET)) {
                    $db = $this->db;
                    $db->startTransaction();
                    // User row first, then planets: the same order as the
                    // research, Empire, vacation and fleet mission paths.
                    $sql    = "SELECT * FROM %%USERS%% WHERE id = :userId FOR UPDATE;";
                    $u   = $db->selectSingle($sql, [
                        ':userId'   => $this->state->USER['id'],
                    ]);
                    $sql    = "SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;";
                    $p = $db->selectSingle($sql, [
                        ':planetId' => $this->state->PLANET['id'],
                    ]);

                    // Calc from the locked rows. Session copies are stale under
                    // concurrent requests (PREVENT_MULTISESSIONS is false) and
                    // SavePlanetToDB applies resource deltas additively.
                    if (!empty($p) && !empty($u)) {
                        $this->state->USER = array_merge($this->state->USER, $u);
                        $this->state->PLANET = $p;
                    }

                    [$this->state->USER, $this->state->PLANET] = $this->ecoObj->CalcResource($this->state->USER, $this->state->PLANET, true);
                    $db->commit();
                }
            }
        } else {
            $this->setWindow('ajax');
        }
    }

    /**
     * "You have x new messages" once per login and player (first full page; the Symfony session is
     * invalidated on logout). Afterwards the badge on the messages icon in the top bar shows the count.
     *
     * @return array{text: string, url: string}|null
     */
    private function newMessagesNotice(int $count): ?array
    {
        $USER = $this->state->USER;
        $session = $this->sessions()->symfonySession();
        $key = 'messages_notice_' . (int) $USER['id'];
        if ($session->get($key)) {
            return null;
        }
        $session->set($key, true);
        if ($count < 1) {
            return null;
        }

        $LNG = $this->state->LNG;
        $category = (int) ($USER['showMessageCategory'] ?? 0) === 1 && is_numeric($USER['message_type'] ?? null)
            ? '&category=' . (int) $USER['message_type'] : '';

        return [
            'text' => $count === 1 ? $LNG['ov_have_new_message'] : $LNG->format('ov_have_new_messages', $count),
            'url'  => 'game?page=messages' . $category,
        ];
    }

    /** @var array<int, int> account id per user id, looked up once per request (navigation and page data) */
    private array $accountIds = [];

    private function accountIdOfUser(int $userId): int
    {
        return $this->accountIds[$userId] ??= $this->playerUtil()->get_account_id($userId);
    }

    /**
     * Query string (page, mode, id) of the current request, e.g. for the language/theme switch links.
     */
    protected function getQueryString()
    {
        $queryString = [];
        $page = $this->http()->_GP('page', '');

        if (!empty($page)) {
            $queryString['page'] = $page;
        }

        $mode = $this->http()->_GP('mode', '');
        if (!empty($mode)) {
            $queryString['mode'] = $mode;
        }

        $id = $this->http()->_GP('id', '');
        if (!empty($id)) {
            $queryString['id'] = $id;
        }

        return http_build_query($queryString);
    }

    /**
     * Navigation of full pages: planet list, resource bar, counters, account notices.
     *
     * @return array<string, mixed>
     */
    protected function navigationData(): array
    {
        $PLANET = $this->state->PLANET;
        $THEME = $this->state->THEME;
        $resource = $this->state->resource;
        $config = $this->configs->get();
        $db = $this->db;

        $PlanetSelect = $this->planetSelectOptions();
        $resourceTable = $this->resourceBar($config);

        $themeSettings = $THEME->getStyleSettings();

        $commit = '';
        $commitShort = '';
        if (file_exists('.git/FETCH_HEAD')) {
            $commit = explode('	', file_get_contents('.git/FETCH_HEAD'))[0];
            $commitShort = substr($commit, 0, 7);
        }

        $avatar = 'styles/resource/images/user.webp';
        if ($this->sessions()->load()->data !== null) {
            try {
                $avatar = json_decode($this->sessions()->load()->data->account->json_metadata)->profile->profile_image;
            } catch (Exception $e) {
            }
        }

        $allyrequests = $this->allianceRequestCount();
        $vacation = $this->vacationNotice();

        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID;";
        $wreckfieldData = $db->selectSingle($sql, [':planetID' => $PLANET['id']]);
        $wreckfield = $wreckfieldData && empty($wreckfieldData['repair_order']) && !empty($wreckfieldData['ships']);

        $sql = "SELECT COUNT(b.`id`) as count FROM %%BUDDY%% b
            JOIN %%BUDDY_REQUEST%% br ON br.`id` = b.`id`
            WHERE b.`sender` = :userID OR b.`owner` = :userID;";
        $buddyRequests = $db->selectSingle($sql, [':userID' => $this->state->USER['id']], 'count');

        $deletion = $this->deletionNotice($config);

        $tutorialDetails = [];
        if ($this->viewHelpers()->isModuleAvailable(MODULE_TUTORIAL)) {
            $tutorialUtil = new TutorialUtil($this->db, $this->configs, $this->state);
            $tutorialDetails = $tutorialUtil->getTutorialStepDetails();
        }


        return [
            'PlanetSelect' => $PlanetSelect,
            'wreckfield' => $wreckfield,
            'new_message' => $this->state->USER['messages'],
            'loginNotice' => $this->newMessagesNotice((int) $this->state->USER['messages']),
            'userRank' => (int) ($this->state->USER['total_rank'] ?? 0),
            'userPoints' => (float) ($this->state->USER['total_points'] ?? 0),
            'new_allyrequests' => $allyrequests,
            'new_buddyrequests' => $buddyRequests,
            'commit' => $commit,
            'commitShort' => $commitShort,
            'vacation' => $vacation === false ? false : new \Twig\Markup((string) $vacation, 'UTF-8'),
            'delete' => $deletion === false ? false : new \Twig\Markup((string) $deletion, 'UTF-8'),
            'current_pid' => $PLANET['id'],
            'image' => $PLANET['image'],
            'planet_style' => $PLANET['planet_style'],
            'username' => $this->state->USER['username'],
            'avatar' => $avatar,
            'resourceTable' => $resourceTable,
            'shortlyNumber' => $themeSettings['TOPNAV_SHORTLY_NUMBER'],
            'uni_status' => $config->uni_status,
            'hasBoard' => true,
            'hasAdminAccess' => !empty($this->sessions()->load()->adminAccess),
            'hasRebuildProtection' => $config->rebuild_protection == 1 && $this->state->USER['rebuild_protection'] > 0,
            'rebuildProtectionUntil' => $this->state->USER['rebuild_protection'] + TIME_24_HOURS * 2 - $this->state->runtime->timestamp,
            'hasGate' => $PLANET[$resource[43]] > 0,
            'communityNav' => $this->communityNav(!empty($tutorialDetails['id'] ?? null)),
            'tutorial' => !$tutorialDetails || empty($tutorialDetails) ? false : $tutorialDetails,
            'noobInterface' => $this->state->USER['noob_interface'],
        ];
    }


    /**
     * Options of the planet selector ("name (Moon) [g:s:p]" per planet id). Ends the request with the banned message for
     * banned players and loads the player's planet list into the USER state on first use.
     *
     * @return array<int, string> planet id => label
     */
    private function planetSelectOptions(): array
    {
        $LNG = $this->state->LNG;
        $PlanetSelect = [];

        if ($this->state->USER['bana'] == 1) {
            throw new EndOfRequest($LNG['banned_message']);
        }

        if (!isset($this->state->USER['PLANETS'])) {
            $this->state->USER['PLANETS'] = $this->playerUtil()->getPlanets($this->state->USER);
        }

        foreach ($this->state->USER['PLANETS'] as $PlanetQuery) {
            $PlanetSelect[$PlanetQuery['id']] = $PlanetQuery['name'] . (($PlanetQuery['planet_type'] == 3) ? " (" . $LNG['fcm_moon'] . ")" : "") . " [" . $PlanetQuery['galaxy'] . ":" . $PlanetQuery['system'] . ":" . $PlanetQuery['planet'] . "]";
        }

        return $PlanetSelect;
    }

    /**
     * The resource bar of the current planet: metal/crystal/deuterium with stock, capacity and hourly production (incl. the basic
     * income, except in vacation mode and on moons), energy with use and capacity, and the third resource group (dark matter) with its amount.
     *
     * @return array<int, array<string, mixed>> resource id => name, current, max, production / used
     */
    private function resourceBar(object $config): array
    {
        $PLANET = $this->state->PLANET;
        $resource = $this->state->resource;
        $reslist = $this->state->reslist;

        $resourceTable = [];
        $resourceSpeed = $config->resource_multiplier / 2500;
        foreach ($reslist['resstype'][1] as $resourceID) {
            $resourceTable[$resourceID]['name'] = $resource[$resourceID];
            $resourceTable[$resourceID]['current'] = floor($PLANET[$resource[$resourceID]]);
            $resourceTable[$resourceID]['max'] = $PLANET[$resource[$resourceID] . '_max'];
            if ($this->state->USER['urlaubs_modus'] == 1 || $PLANET['planet_type'] != 1) {
                $resourceTable[$resourceID]['production'] = $PLANET[$resource[$resourceID] . '_perhour'];
            } else {
                $resourceTable[$resourceID]['production'] = $PLANET[$resource[$resourceID] . '_perhour'] + $config->{$resource[$resourceID] . '_basic_income'} * $resourceSpeed;
            }
        }

        foreach ($reslist['resstype'][2] as $resourceID) {
            $resourceTable[$resourceID]['name'] = $resource[$resourceID];
            $resourceTable[$resourceID]['used'] = $PLANET[$resource[$resourceID] . '_used'];
            $resourceTable[$resourceID]['max'] = $PLANET[$resource[$resourceID]];
        }

        foreach ($reslist['resstype'][3] as $resourceID) {
            $resourceTable[$resourceID]['name'] = $resource[$resourceID];
            $resourceTable[$resourceID]['current'] = $this->state->USER[$resource[$resourceID]];
        }

        return $resourceTable;
    }

    /**
     * Number of open alliance matters the player may handle: applications (owner or MANAGEAPPLY right) plus pending
     * diplomacy requests (owner or DIPLOMATIC right). Players without an alliance have none (no query).
     */
    private function allianceRequestCount(): int|string
    {
        $db = $this->db;
        // players without an alliance have no requests to manage (no alliance has id 0)
        $allyApplyRequests = 0;
        $allyDiploRequests = 0;
        if (!empty($this->state->USER['ally_id'])) {
            $sql = "SELECT COUNT(r.applyID) AS applies
                FROM %%ALLIANCE_REQUEST%% r
                JOIN %%USERS%% u ON u.ally_id = r.allianceID
                JOIN %%ALLIANCE%% a ON a.id = u.ally_id
                LEFT JOIN %%ALLIANCE_RANK%% ra ON ra.allianceID = u.ally_id AND ra.rankID = u.ally_rank_id
                WHERE r.allianceID = :allianceID AND u.id = :userID AND (u.id = a.ally_owner OR ra.MANAGEAPPLY = 1);";
            $allyApplyRequests = $db->selectSingle($sql, [
                ':allianceID' => $this->state->USER['ally_id'],
                ':userID' => $this->state->USER['id'],
            ], 'applies');

            $sql = "SELECT COUNT(d.id) AS diplos
                FROM %%DIPLO%% d
                JOIN %%USERS%% u ON u.ally_id = d.owner_2
                JOIN %%ALLIANCE%% a ON a.id = u.ally_id
                LEFT JOIN %%ALLIANCE_RANK%% ra ON ra.allianceID = u.ally_id AND ra.rankID = u.ally_rank_id
                WHERE d.owner_2 = :allianceID AND accept = 0 AND u.id = :userID AND (u.id = a.ally_owner OR ra.DIPLOMATIC = 1);";
            $allyDiploRequests = $db->selectSingle($sql, [
                ':allianceID' => $this->state->USER['ally_id'],
                ':userID' => $this->state->USER['id'],
            ], 'diplos');
        }

        return $allyApplyRequests + $allyDiploRequests;
    }

    /**
     * Vacation mode banner text (until when, or "may end now", plus the kick hint when the module is on), false outside vacation mode.
     */
    private function vacationNotice(): string|false
    {
        $LNG = $this->state->LNG;
        $vacation = false;
        if ($this->state->USER['urlaubs_modus']) {
            if ($this->state->USER['urlaubs_until'] > $this->state->runtime->timestamp) {
                $vacation = $LNG->format('tn_vacation_mode_until', $this->viewHelpers()->formatDate($LNG['php_tdformat'], $this->state->USER['urlaubs_until'], $this->state->USER['timezone']));
            } else {
                $vacation = $LNG['tn_vacation_mode'];
            }
            if ($this->viewHelpers()->isModuleAvailable(MODULE_VMODE_KICK, $this->state->USER['universe'])) {
                $vacation .= $LNG['tn_vacation_mode_kick'];
            }
        }

        return $vacation;
    }

    /**
     * Account deletion banner text with the planned deletion date (of the game account or of the whole account), false when none is pending.
     */
    private function deletionNotice(object $config): string|false
    {
        $LNG = $this->state->LNG;
        $deletion = false;
        $accountId = $this->accountIdOfUser((int) $this->state->USER['id']);
        $ACCOUNT = $this->accountUtil()->get_account($accountId);
        if ($this->state->USER['db_deaktjava'] || $ACCOUNT['db_deaktjava']) {
            $timestamp = $this->state->USER['db_deaktjava'] ? $this->state->USER['db_deaktjava'] : $ACCOUNT['db_deaktjava'];
            $deletion = $LNG->format('tn_delete_mode', $this->viewHelpers()->formatDate($LNG['php_tdformat'], $this->accountUtil()->getDeletionDate($timestamp, $config), $ACCOUNT['timezone']));
        }

        return $deletion;
    }

    /**
     * Community entries of the navigation (Discord, wiki, donate), see CommunityLinks::navLinks(). The wiki entry
     * keeps going through the wiki SSO route while `$wikiSsoUrl` is set in config/game.php (as WikiLoginController reads it).
     *
     * @return array<string, string>
     */
    private function communityNav(bool $tutorialActive): array
    {
        $wikiSsoUrl = (static function (string $rootPath): string {
            require $rootPath . 'config/game.php';

            return (string) ($wikiSsoUrl ?? '');
        })($this->state->runtime->rootPath);
        $config = $this->configs->get();
        $urls = [];
        foreach (\App\Game\Core\CommunityLinks::KEYS as $key) {
            $urls[$key] = (string) ($config->$key ?? '');
        }

        return \App\Game\Core\CommunityLinks::navLinks($urls, !empty($wikiSsoUrl), $tutorialActive);
    }

    /**
     * Player, clock and theme settings of every non-ajax page.
     *
     * @return array<string, mixed>
     */
    protected function pageData(): array
    {
        $USER = $this->state->USER;
        $THEME = $this->state->THEME;

        $dateTimeServer = new DateTime("now");
        if (isset($USER['timezone'])) {
            try {
                $dateTimeUser = new DateTime("now", new DateTimeZone($USER['timezone']));
            } catch (Exception $e) {
                $dateTimeUser = $dateTimeServer;
            }
        } else {
            $dateTimeUser = $dateTimeServer;
        }
        $accountId = $this->accountIdOfUser((int) $USER['id']);
        $config = $this->configs->get();

        return [
            'vmode' => $USER['urlaubs_modus'],
            'authlevel' => $USER['authlevel'],
            'account_id' => $accountId,
            'userID' => $USER['id'],
            'bodyclass' => $this->getWindow(),
            'game_name' => $config->game_name,
            'uni_name' => $config->uni_name,
            'debug' => $config->debug,
            'version' => $config->version,
            'date' => explode("|", date('Y\|n\|j\|G\|i\|s\|Z', $this->state->runtime->timestamp)),
            'isPlayerCardActive' => $this->viewHelpers()->isModuleAvailable(MODULE_PLAYERCARD),
            'REV' => substr($config->version, -4),
            'Offset' => $dateTimeUser->getOffset() - $dateTimeServer->getOffset(),
            'queryString' => $this->getQueryString(),
            'themeSettings' => $THEME->getStyleSettings(),
        ];
    }

    /**
     * Shows a message page and ends the request.
     *
     * @param string|\Twig\Markup $message plain text is escaped, pass Twig\Markup for HTML
     * @param list<array{url: string, label: string}>|null $redirectButtons links under the message
     * @param array{0: string, 1: int}|null $redirect [url, seconds] automatic redirect
     * @param bool $fullSide false: popup window
     */
    protected function printMessage($message, $redirectButtons = null, $redirect = null, $fullSide = true): never
    {
        $this->assign([
            'message' => $message,
            'redirectButtons' => $redirectButtons,
        ]);

        if (isset($redirect)) {
            $this->assign(['goto' => $redirect[0], 'gotoinsec' => $redirect[1]]);
        }

        if (!$fullSide) {
            $this->setWindow('popup');
        }

        $this->display('game/error.default.html.twig');
    }

    /**
     * Sets `last_update` of the current planet to the request time (used by pages that change the planet without a resource update).
     */
    protected function updatePlanetTime()
    {
        $PLANET = $this->state->PLANET;

        if (!isset($PLANET['id'])) {
            return;
        }
        $db = $this->db;
        $sql = "UPDATE %%PLANETS%% SET last_update = :lastUpdateTime WHERE id = :planetId";
        $db->update($sql, [
            ':lastUpdateTime' => $this->state->runtime->timestamp,
            ':planetId' => $PLANET['id'],
        ]);
    }

    /**
     * Variables of the game shell for full pages (top bar, planet list, resources, new-message badge, theme and clock). Popup and ajax
     * windows get no shell data.
     */
    protected function layoutData(string $template): array
    {
        $LNG = $this->state->LNG;
        $THEME = $this->state->THEME;

        $data = [];
        if ($this->getWindow() === 'full') {
            $data = $this->navigationData();
        }
        if ($this->getWindow() !== 'ajax') {
            $data = array_replace($data, $this->pageData());
        }

        return array_replace($data, [
            'lang'              => $LNG->getLanguage(),
            'dpath'             => $THEME->getTheme(),
            'themeCssPath'      => $THEME->getThemeCssPath(),
            'basepath'          => $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->runtime->httpBase,
            'TIMEZONESTRING'    => $this->state->USER['timezone'],
            'signalColors'      => $this->state->USER['signalColors'],
            'decimalSeparator'  => $this->state->USER['decimal_separator'],
            'numberSuffixes'    => (new NumberAbbreviation())->suffixesForLang($LNG->getLanguage()),
            'captchakey'        => $this->configs->get()->recaptcha_pub_key,
        ]);
    }

    /** RendersPages::render() plus the Server-Timing header (bootstrap, page, rendering, database) */
    protected function render(string $template): Response
    {
        $this->state->time['end_page'] = microtime(true);
        $response = $this->renderPage($template);
        $this->state->time['end_template'] = microtime(true);

        $ms = static fn(float $from, float $to): float => ($to - $from) * 1000;
        $response->headers->set('Server-Timing', sprintf(
            'total;dur=%s, common;dur=%s, page;dur=%s, rendering;dur=%s, db;desc=query;dur=%s',
            $ms($this->state->time['start'], $this->state->time['end_template']),
            $ms($this->state->time['start'], $this->state->time['end_common']),
            $ms($this->state->time['end_common'], $this->state->time['end_page']),
            $ms($this->state->time['end_page'], $this->state->time['end_template']),
            ($this->state->time['db'] ?? 0) * 1000,
        ));

        return $response;
    }

    /**
     * Sends $data as a JSON response and ends the request (EndOfRequest).
     */
    protected function sendJSON($data): never
    {
        // $this->updatePlanetTime();
        echo json_encode($data);
        throw new EndOfRequest();
    }

    /**
     * Redirects to $url and ends the request.
     */
    protected function redirectTo($url): never
    {
        // $this->updatePlanetTime();
        $this->http()->redirectTo($url);
        throw new EndOfRequest();
    }
}
