<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Admin;

use App\Game\Enum\AuthLevel;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Config;
use App\Game\Core\Config\ConfigFieldSchema;
use App\Game\Core\HTTP;
use App\Game\Core\Universe;

/**
 * Universe administration: list, open/close, create and delete universes.
 */
class UniverseController extends AbstractAdminController
{
    private \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient;

    /** HTTP client for the multi-universe (rewrite rule) probe on create. */
    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setHttpClient(\Symfony\Contracts\HttpClient\HttpClientInterface $httpClient): void
    {
        $this->httpClient = $httpClient;
    }

    /**
     * Only authlevel ADM may manage universes.
     */
    protected function construct(): void
    {
        $USER = $this->state->USER;

        if ($USER['authlevel'] != AuthLevel::Admin->value) {
            $this->deny();
        }

        parent::construct();

    }

    /** Lists the universes with their status, size and actions. */
    #[GameRoute('admin', 'admin/universes', 'universe')]
    public function show(): Response
    {
        return $this->renderList();
    }

    /** Opens the universe `uniID` (STATUS_OPEN in its config) and lists the universes. */
    #[GameRoute('admin', 'admin/universes/{uniID}/open', 'universe', 'open', ['POST'], ['uniID' => '\d+'])]
    public function open(): Response
    {
        $config = $this->configs->get($this->input()->int('uniID'));
        $config->uni_status = STATUS_OPEN;
        $config->save();

        return $this->renderList();
    }

    /** Closes the universe `uniID` (STATUS_CLOSED in its config) and lists the universes. */
    #[GameRoute('admin', 'admin/universes/{uniID}/close', 'universe', 'close', ['POST'], ['uniID' => '\d+'])]
    public function close(): Response
    {
        $config = $this->configs->get($this->input()->int('uniID'));
        $config->uni_status = STATUS_CLOSED;
        $config->save();

        return $this->renderList();
    }

    /**
     * Removes the universe `uniID` with all its data (not the root universe and not the universe the admin is currently in); afterwards the
     * admin is redirected (to the root URL layout when only one universe remains). Otherwise lists the universes.
     */
    #[GameRoute('admin', 'admin/universes/{uniID}/delete', 'universe', 'delete', ['POST'], ['uniID' => '\d+'])]
    public function delete(): Response
    {
        $universe = $this->input()->int('uniID');
        if (!empty($universe) && $universe != ROOT_UNI && $universe != $this->universe()->current()) {
            return $this->deleteUniverse($universe);
        }

        return $this->renderList();
    }

    /**
     * Creates the next universe: first checks that the web server rewrites /uniN/ URLs (a request that must answer 302, otherwise the nginx
     * hint is shown), inserts the default config for the next universe id (schema defaults, all modules on, a marketplace row), creates an ADM
     * player for the acting admin at 1:1:1 and switches the admin session to it.
     */
    #[GameRoute('admin', 'admin/universes/create', 'universe', 'create', ['POST'])]
    public function create(): Response
    {
        return $this->createUniverse();
    }

    private function renderList(): Response
    {
        return $this->render('admin/UniversePage.html.twig', [
            'uniList'      => $this->universeList(),
        ]);
    }

    /**
     * Deletes a universe with all its data (community, fleet and player tables, marketplace, config), fixes the
     * emulated universe and redirects the admin. The redirect target depends on whether only one
     * universe remains (then the root URL layout is used again and the session cookie is re-issued at the site root).
     */
    private function deleteUniverse(mixed $universe): Response
    {
        $db = $this->db;
        $this->deleteUniverseCommunityData($universe);
        $this->deleteUniverseFleetData($universe);
        $this->deleteUniversePlayerData($universe);

        $sql = "DELETE FROM %%MARKETPLACE%% WHERE `universeId` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%CONFIG_UNIVERSE%% WHERE `uni` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%CONFIG_UNIVERSE_MODULES%% WHERE `uni` = :universe;";
        $db->delete($sql, [':universe' => $universe]);
        $this->configs->invalidateCache();

        if ($this->universe()->getEmulated() == $universe) {
            $this->universe()->setEmulated($this->universe()->current());
        }

        // Drop deleted uni from the in-request cache so remaining
        // count matches DB (mirrors create always reloading).
        $this->universe()->remove($universe);

        if (count($this->universe()->availableUniverses()) === 1) {
            // Transition multi-uni URL layout -> single-uni.
            // setcookie() expires is a Unix timestamp (not a relative
            // lifetime). Passing SESSION_LIFETIME (43200) expires the
            // cookie in 1970 and logs the admin out (./?code=3).
            // Session::init() already uses path=/; keep the cookie alive
            // at site root after leaving /uniN/.
            $this->http()->sendCookie(
                $this->sessions()->name(),
                $this->sessions()->id(),
                time() + SESSION_LIFETIME,
                '/',
                null,
                $this->state->runtime->https,
                true,
            );

            return $this->redirectResponse("../admin?reload=r");
        }

        // Still multi-uni: reload admin so the list refreshes
        return $this->redirectResponse("admin?reload=r");
    }

    /**
     * Deletes alliances (with ranks and requests), log, bans, buddies and diplomacy of a universe.
     */
    private function deleteUniverseCommunityData(mixed $universe): void
    {
        $db = $this->db;
        $sql = "DELETE FROM %%ALLIANCE_RANK%% WHERE `allianceID` IN (SELECT `id` AS 'allianceID' FROM %%ALLIANCE%% WHERE `ally_universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%ALLIANCE_REQUEST%% WHERE `allianceID` IN (SELECT `id` AS 'allianceID' FROM %%ALLIANCE%% WHERE `ally_universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%ALLIANCE%% WHERE `ally_universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%LOG%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%USERS_BANNED%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%BUDDY_REQUEST%% WHERE `id` IN (SELECT `id` FROM %%BUDDY%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%BUDDY%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%DIPLO%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);
    }

    /**
     * Deletes fleets and their events/AKS/trades, fleet log, messages, notes, planets with wreck fields, statistics,
     * multi-IP records, tickets and top battle reports of a universe.
     */
    private function deleteUniverseFleetData(mixed $universe): void
    {
        $db = $this->db;
        $sql = "DELETE FROM %%FLEETS_EVENT%% WHERE `fleetID` IN (SELECT `id` AS 'fleetID' FROM %%FLEETS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%AKS%% WHERE `id` IN (SELECT `group` AS 'id' FROM %%FLEETS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%TRADES%% WHERE `seller_fleet_id` IN (SELECT `id` AS 'seller_fleet_id' FROM %%FLEETS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%FLEETS%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%LOG_FLEETS%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%USERS_TO_MESSAGES%% WHERE `message_id` IN (SELECT `message_id` FROM %%MESSAGES%% WHERE `message_universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%MESSAGES%% WHERE `message_universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%NOTES%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%PLANET_WRECKFIELD%% WHERE `planetID` IN (SELECT `id` FROM %%PLANETS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%PLANETS%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%STATPOINTS%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%STATPOINTS_HISTORY%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%MULTI_TO_USERS%% WHERE `multiID` IN (SELECT `multiID` FROM %%MULTI%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%MULTI%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%TICKETS_ANSWER%% WHERE `ticketID` IN (SELECT `ticketID` FROM %%TICKETS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%TICKETS%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);

        $sql = "DELETE FROM %%RW%% WHERE `rid` IN (SELECT `rid` FROM %%TOPKB%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%TOPKB%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);
    }

    /**
     * Deletes everything that hangs off the players of a universe (ACS, sessions, shortcuts, records, achievements,
     * comments, advanced stats, settings, account links) and finally the players themselves.
     */
    private function deleteUniversePlayerData(mixed $universe): void
    {
        $db = $this->db;
        $sql = "DELETE FROM %%USERS_TO_ACS%% WHERE `userID` IN (SELECT `id` as 'userID' FROM %%USERS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%TOPKB_USERS%% WHERE `uid` IN (SELECT `id` as 'uid' FROM %%USERS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%SESSION%% WHERE `userID` IN (SELECT `id` as 'userID' FROM %%USERS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%SHORTCUTS%% WHERE `ownerID` IN (SELECT `id` as 'ownerID' FROM %%USERS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%RECORDS%% WHERE `userID` IN (SELECT `id` as 'userID' FROM %%USERS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%USERS_TO_ACHIEVEMENTS%% WHERE `userID` IN (SELECT `id` as 'userID' FROM %%USERS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%USERS_COMMENTS%% WHERE `id` IN (SELECT `id` as 'id' FROM %%USERS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%ADVANCED_STATS%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%ADVANCED_STATS_HISTORY%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "DELETE FROM %%USERS_SETTINGS%% WHERE `user_id` IN (SELECT `id` as 'user_id' FROM %%USERS%% WHERE `universe` = :universe);";
        $db->delete($sql, [':universe' => $universe]);
        $sql = "SELECT `id` as 'id' FROM %%USERS%% WHERE `universe` = :universe;";
        $users = $db->select($sql, [':universe' => $universe]);
        foreach ($users as $user) {
            $sql = "DELETE FROM %%ACCOUNTS_TO_USERS%% WHERE `user_id` = :id;";
            $db->delete($sql, [':id' => $user['id']]);
        }
        $sql = "DELETE FROM %%USERS%% WHERE `universe` = :universe;";
        $db->delete($sql, [':universe' => $universe]);
    }

    /**
     * Creates the next universe: checks that /uniN/ URLs are rewritten by the web server (ends with a hint message
     * otherwise), inserts config and modules, creates an ADM player for the acting admin at 1:1:1 and redirects into the
     * new universe.
     */
    private function createUniverse(): Response
    {
        $USER = $this->state->USER;
        $db = $this->db;

        $universeCount = max($this->universe()->availableUniverses());
        if ($hint = $this->assertMultiUniverseSupport($universeCount)) {
            return $hint;
        }

        $newUniverse = $universeCount + 1;
        $this->insertUniverseConfig($newUniverse);

        $this->configs->reload();
        $sql = "INSERT INTO %%MARKETPLACE%% (`universeId`) VALUES (:universe);";
        $db->insert($sql, [
            ':universe' => $newUniverse,
        ]);
        $accountId = $this->playerLookup()->get_account_id($USER['id']);
        [$userID, $planetID] = $this->playerRegistration()->createPlayer(
            $accountId,
            $newUniverse,
            $USER['username'],
            $USER['lang'],
            1,
            1,
            1,
            null,
            AuthLevel::Admin->value,
        );

        if ($universeCount === 1) {
            // Hack The Session
            $this->http()->sendCookie(
                $this->sessions()->name(),
                $this->sessions()->id(),
                SESSION_LIFETIME,
                $this->state->runtime->httpRoot . 'uni' . $USER['universe'] . '/',
                null,
                $this->state->runtime->https,
                true,
            );
        }

        return $this->redirectResponse("uni" . $USER['universe'] . "/admin?reload=r");
    }

    /**
     * Requests /uni(N+1)/ from this server; anything but a 302 means the rewrite rule is missing: the nginx hint message is returned.
     */
    private function assertMultiUniverseSupport(int $universeCount): ?Response
    {
        $LNG = $this->state->LNG;
        $options = [
            'max_redirects' => 0,
            'headers' => [
                'User-Agent' => "Mozilla/5.0 (compatible; Br0Game/" . $this->configs->get()->version
                    . "; +https://github.com/Voidys/Br0Game)",
                'Accept' => "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                'Accept-Charset' => "ISO-8859-1,utf-8;q=0.7,*;q=0.3",
                'Accept-Language' => "de-DE,de;q=0.8,en-US;q=0.6,en;q=0.4",
            ],
        ];
        if (str_contains($this->state->runtime->httpHost, '127.0.0.1') || str_contains($this->state->runtime->httpHost, 'localhost')) {
            $options['verify_peer'] = false;
            $options['verify_host'] = false;
        }
        try {
            $httpCode = $this->httpClient->request(
                'GET',
                $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->runtime->httpBase . "uni" . ($universeCount + 1) . "/",
                $options,
            )->getStatusCode();
        } catch (\Throwable) {
            $httpCode = 0;
        }
        if ($httpCode != 302) {
            // static information text with HTML (no user input)
            return $this->messageResponse(
                new \Twig\Markup(str_replace(
                    [
                        '{NGINX-CODE}',
                    ],
                    [
                        #'rewrite '.$this->state->runtime->httpRoot.'uni[0-9]+/?(.*)?$ '.$this->state->runtime->httpRoot.'$2 break;'
                        'rewrite /(.*)/?uni[0-9]+/?(.*) /$1/$2 break;',
                    ],
                    $LNG->getTemplate('createUniverseInfo'),
                )
                . '<a href="#" data-action="history-back"><button>' . $LNG['uvs_back'] . '</button></a>'
                . '<a href="#" data-action="reload"><button>' . $LNG['uvs_reload'] . '</button></a>', 'UTF-8'),
            );
        }

        return null;
    }

    /**
     * Inserts the default config (schema defaults, the universe name `<fcm_universe> N`) and all modules (enabled) of
     * the new universe.
     */
    private function insertUniverseConfig(int $newUniverse): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;

        $schema = new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_UNI, $this->state, $this->configs);
        $sql = 'INSERT INTO %%CONFIG_UNIVERSE%% (`uni`, `key`, `value`) VALUES (:uni, :key, :value);';
        foreach ($schema->getAllFields() as $fieldName => $fieldConfig) {
            $value = $fieldConfig['default'] ?? '';
            if ($fieldName == 'uni_name') {
                $value = $LNG['fcm_universe'] . ' ' . $newUniverse;
            }
            $db->insert($sql, [
                ':uni' => $newUniverse,
                ':key' => $fieldName,
                ':value' => $value,
            ]);
        }

        $modules = $this->state->modules;
        foreach ($modules as $moduleKey => $moduleName) {
            $sql = 'INSERT INTO %%CONFIG_UNIVERSE_MODULES%% (`uni`, `module`, `module_name`, `state`)
            VALUES (:uni, :module, :module_name, :state)';
            $db->insert($sql, [
                ':uni'         => $newUniverse,
                ':module'      => $moduleKey,
                ':module_name' => $moduleName,
                ':state'       => 1,
            ]);
        }
        $this->configs->invalidateCache();
    }

    /**
     * One row per available universe for the list: its config values plus counts of inactive players and planets.
     *
     * @return array<mixed>
     */
    private function universeList(): array
    {
        $db = $this->db;
        $uniList = [];

        foreach ($this->universe()->availableUniverses() as $universeId) {
            $config = $this->configs->get($universeId);
            $uniList[$universeId] = [
                'uni' => $universeId,
                'uni_name' => $config->uni_name,
                'uni_status' => $config->uni_status,
                'users_amount' => $config->users_amount,
                'energy_multiplier' => $config->energy_multiplier,
                'expo_hold_multiplier' => $config->expo_hold_multiplier,
                'resource_multiplier' => $config->resource_multiplier,
                'fleet_speed' => $config->fleet_speed,
                'building_speed' => $config->building_speed,
                'shipyard_speed' => $config->shipyard_speed,
                'research_speed' => $config->research_speed,
            ];

            $sql = "SELECT COUNT(`id`) as 'inactive'
                FROM %%USERS%%
                WHERE `universe` = :universe AND `onlinetime` < :inactive;";
            $inactiveResult = $db->select($sql, [
                ':universe' => $universeId,
                ':inactive' => $this->state->runtime->timestamp - INACTIVE,
            ]);
            $uniList[$universeId]['inactive'] = $inactiveResult[0]['inactive'];
            $sql = "SELECT COUNT(`id`) as 'planet'
                FROM %%PLANETS%%
                WHERE `universe` = :universe;";
            $planetResult = $db->select($sql, [
                ':universe' => $universeId,
            ]);
            $uniList[$universeId]['planet'] = $planetResult[0]['planet'];
        }

        return $uniList;
    }
}
