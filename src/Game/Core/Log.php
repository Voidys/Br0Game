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

namespace App\Game\Core;

/**
 * Audit log entry builder: create with a mode (log type id), set properties (target, old, new, universe, ...), then
 * save() or saveTr(). The acting user is taken from the session (0 for CLI/cron). Used by admin actions and by
 * transports to other players (type 5).
 */
class Log
{
    use \App\Game\Core\GameUtilities;

    private array $data = [];

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
        string $mode,
    ) {
        $this->data['mode']     = $mode;
        $this->data['admin']    = $this->actingUserId();
        $this->data['uni']      = $this->universe()->getEmulated();
    }
    /**
     * Id of the user who causes the log entry: the logged-in user of the current request, or 0 (system)
     * when there is no web session (CLI, cron, background processing).
     */
    private function actingUserId(): int
    {
        if (($this->state->session ?? null) instanceof Session) {
            return (int) $this->state->session->userId;
        }
        if (PHP_SAPI === 'cli') {
            return 0;
        }

        return (int) (new SessionManager($this->db, $this->configs, $this->state))->load()->userId;
    }

    /**
     * Sets a log field (mode, admin, target, old, new, universe, ...).
     */
    public function __set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
    /**
     * Reads a log field, null when unset.
     */
    public function __get(string $key): mixed
    {
        return $this->__isset($key) ? $this->data[$key] : null;
    }
    /**
     * True if the log field is set.
     */
    public function __isset(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Writes a transaction-style entry to `log`: only the `new` value is stored (no before/after pair).
     */
    public function saveTr(): void
    {
        $db = $this->db;
        $uni = (empty($this->data['universe']) ? $this->data['uni'] : $this->data['universe']);

        $sql = "INSERT INTO %%LOG%% SET
            `mode` = :mode,
            `admin` = :admin,
            `target` = :target,
            `time` = :time,
            `data` = :data,
            `universe` = :universe;";

        $db->insert($sql, [
            ':mode'     => $this->data['mode'],
            ':admin'    => $this->data['admin'],
            ':target'   => $this->data['target'],
            ':time'     => $this->state->runtime->timestamp,
            ':data'     => StoredData::encode($this->data['new']),
            ':universe' => $uni,
        ]);
    }

    /**
     * Writes an entry to `log` with the old and new values (JSON via StoredData) for the universe given by `universe` or the emulated universe.
     */
    public function save(): void
    {
        $db = $this->db;
        $uni = (empty($this->data['universe']) ? $this->data['uni'] : $this->data['universe']);

        $sql = "INSERT INTO %%LOG%% SET
            `mode` = :mode,
            `admin` = :admin,
            `target` = :target,
            `time` = :time,
            `data` = :data,
            `universe` = :universe;";

        $db->insert($sql, [
            ':mode'     => $this->data['mode'],
            ':admin'    => $this->data['admin'],
            ':target'   => $this->data['target'],
            ':time'     => $this->state->runtime->timestamp,
            ':data'     => StoredData::encode([$this->data['old'], $this->data['new']]),
            ':universe' => $uni,
        ]);
    }
}
