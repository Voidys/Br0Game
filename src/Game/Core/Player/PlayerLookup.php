<?php

namespace App\Game\Core\Player;

use App\Game\Core\Database;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;
use App\Game\Core\Language;
use App\Game\Core\GameUtilities;
use App\Game\Settings\ColorSetting;

/**
 * Read-only player queries and settings: mission/stb/signal colours, buddy and block status, auth level,
 * account id, player rows and language objects. Collaborators: Database, ConfigRegistry (Language).
 */
class PlayerLookup
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /** Default mission colours of a player (users_settings column => CSS colour), in display order. */
    private const DEFAULT_COLORS = [
        'colorMission2friend' => '#ff00ff',

        'colorMission1Own' => '#66cc33',
        'colorMission2Own' => '#339966',
        'colorMission3Own' => '#5bf1c2',
        'colorMission4Own' => '#cf79de',
        'colorMission5Own' => '#80a0c0',
        'colorMission6Own' => '#ffcc66',
        'colorMission7Own' => '#c1c1c1',
        'colorMission7OwnReturn' => '#cf79de',
        'colorMission8Own' => '#ceff68',
        'colorMission9Own' => '#ffff99',
        'colorMission10Own' => '#ffcc66',
        'colorMission15Own' => '#5bf1c2',
        'colorMission16Own' => '#5bf1c2',
        'colorMission17Own' => '#5bf1c2',

        'colorMissionReturnOwn' => '#6e8eea',

        'colorMission1Foreign' => '#ff0000',
        'colorMission2Foreign' => '#aa0000',
        'colorMission3Foreign' => '#00ff00',
        'colorMission4Foreign' => '#ad57bc',
        'colorMission5Foreign' => '#3399cc',
        'colorMission6Foreign' => '#ff6600',
        'colorMission7Foreign' => '#00ff00',
        'colorMission8Foreign' => '#acdd46',
        'colorMission9Foreign' => '#dddd77',
        'colorMission10Foreign' => '#ff6600',
        'colorMission15Foreign' => '#39d0a0',
        'colorMission16Foreign' => '#39d0a0',
        'colorMission17Foreign' => '#39d0a0',

        'colorMissionReturnForeign' => '#6e8eea',

        'colorStaticTimer' => '#ffff00',
    ];

    /**
     * Mission colour settings of a player.
     *
     * Without $USER the defaults are returned; with a user row either its cached `colors` entry or the colour
     * columns of the row.
     *
     * @param array<string, mixed>|null $USER user row (with the color* settings columns)
     * @return array<string, string> colour name => CSS colour
     */
    public function player_colors(?array $USER = null): array
    {
        if (!isset($USER)) {
            return self::DEFAULT_COLORS;
        }

        // the values end up in inline CSS: anything but a hex colour (stored by older versions) falls back to the default
        $colors = [];
        foreach (self::DEFAULT_COLORS as $key => $default) {
            $colors[$key] = ColorSetting::sanitize($USER['colors'][$key] ?? $USER[$key] ?? null, $default);
        }

        return $colors;
    }

    /**
     * Small/medium/big thresholds of the stb (quick fleet) display. Defaults without a user; otherwise the user's
     * cached `stb_settings` entry or the stb_* columns.
     *
     * @param array<string, mixed>|null $USER user row
     * @return array<string, int>
     */
    public function player_stb_settings(?array $USER = null): array
    {
        if (!isset($USER)) {
            return [
                'stb_small_ress'    => 500,
                'stb_med_ress'      => 3000,
                'stb_big_ress'      => 7000,
                'stb_small_time'    => 1,
                'stb_med_time'      => 2,
                'stb_big_time'      => 3,
                'stb_enabled'       => 0,
            ];
        }

        if (isset($USER['stb_settings'])) {
            return $USER['stb_settings'];
        }

        return [
            'stb_small_ress'    => $USER['stb_small_ress'],
            'stb_med_ress'      => $USER['stb_med_ress'],
            'stb_big_ress'      => $USER['stb_big_ress'],
            'stb_small_time'    => $USER['stb_small_time'],
            'stb_med_time'      => $USER['stb_med_time'],
            'stb_big_time'      => $USER['stb_big_time'],
            'stb_enabled'       => $USER['stb_enabled'],
        ];
    }

    /**
     * Positive/negative/neutral signal colours. Defaults without a user; otherwise the cached `signalColors` entry
     * or the color* columns.
     *
     * @param array<string, mixed>|null $USER user row
     * @return array<string, string>
     */
    public function player_signal_colors(?array $USER = null): array
    {
        if (!isset($USER)) {
            return [
                'colorPositive' => '#00ff00',
                'colorNegative' => '#ff0000',
                'colorNeutral' => '#ffd600',
            ];
        }

        if (isset($USER['signalColors'])) {
            return $USER['signalColors'];
        }

        return [
            'colorPositive' => $USER['colorPositive'],
            'colorNegative' => $USER['colorNegative'],
            'colorNeutral' => $USER['colorNeutral'],
        ];
    }

    /**
     * Whether two players are buddies (accepted relation in either direction).
     *
     * @return bool
     */
    public function get_buddy_status(int $userId, int $targetUserId): bool
    {
        $db = $this->db;

        $sql = 'SELECT id FROM %%BUDDY%% WHERE ((`sender` = :userID AND `owner` = :targetID) OR (`sender` = :targetID AND `owner` = :userID)) AND id NOT IN (SELECT id FROM %%BUDDY_REQUEST%%);';
        $buddy = $db->selectSingle($sql, [
            ':userID'       => $userId,
            ':targetID'     => $targetUserId,
        ]);
        if (empty($buddy)) {
            return false;
        }
        return true;
    }

    /**
     * Whether $targetUserId blocked $userId for direct messages and trade.
     *
     * @return array{block_dm: int, block_trade: int}
     */
    public function get_block_status(int $userId, int $targetUserId): array
    {
        $db = $this->db;

        $sql = 'SELECT `block_dm`, `block_trade` FROM %%USERS_BLOCKLIST%% WHERE `blocked_user` = :userID AND `blocking_user` = :targetID;';
        $blocked = $db->selectSingle($sql, [
            ':userID'       => $userId,
            ':targetID'     => $targetUserId,
        ]);
        if (empty($blocked)) {
            return [
                'block_dm' => 0,
                'block_trade' => 0,
            ];
        }
        return $blocked;
    }

    /**
     * Auth level of a user (0 = player, higher = staff).
     *
     * @return int
     */
    public function get_authlevel(int $userId): int
    {
        $db = $this->db;

        $sql = 'SELECT `authlevel` FROM %%USERS%% WHERE `id` = :userID;';
        $authlevel = $db->selectSingle($sql, [
            ':userID'       => $userId,
        ], 'authlevel');

        return $authlevel;
    }

    /**
     * Account id a user belongs to, 0 if there is none.
     *
     * @return int
     */
    public function get_account_id(int $userId): int
    {
        $db = $this->db;

        $sql = 'SELECT `account_id` FROM %%ACCOUNTS_TO_USERS%% WHERE `user_id` = :userID;';
        $accountId = $db->selectSingle($sql, [
            ':userID'       => $userId,
        ], 'account_id');
        if ($accountId === false) {
            $accountId = 0;
        }
        return $accountId;
    }

    /**
     * User row joined with the player's total statpoints row (stat_type 1).
     *
     * @return array<mixed>|null null/false-like if the user or stats do not exist
     */
    public function getPlayerById(int $playerId): ?array
    {
        $db = $this->db;

        $sql = 'SELECT u.*, s.*
            FROM %%USERS%% u
            INNER JOIN %%STATPOINTS%% s ON s.`id_owner` = u.`id`
            WHERE u.`id` = :playerId AND s.`stat_type` = 1;';
        return $db->selectSingle($sql, [
            ':playerId' => $playerId,
        ]);
    }

    /**
     * Universe id of a user.
     *
     * @return int
     */
    public function getUniverse(int $userId): int
    {
        $db = $this->db;

        $sql = 'SELECT `universe` FROM %%USERS%% WHERE `id` = :userID;';
        return (int) $db->selectSingle($sql, [
            ':userID' => $userId,
        ], 'universe');
    }

    /**
     * Language object with the ingame data domains loaded, for the given language or the language of the given user.
     *
     * @param string|null $language language code
     * @param int|null $userID used when no language is given
     * @return Language
     */
    public function getLanguage($language = null, $userID = null)
    {
        if (is_null($language) && !is_null($userID)) {
            $language = $this->db->selectSingle('SELECT lang FROM %%USERS_SETTINGS%% WHERE user_id = :id;', [
                ':id' => $userID,
            ])['lang'];
        }

        $LNG = new Language($this->configs, $language);
        $LNG->includeData(['L18N', 'FLEET', 'TECH', 'CUSTOM', 'INGAME']);
        return $LNG;
    }
}
