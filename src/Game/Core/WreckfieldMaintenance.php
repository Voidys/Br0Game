<?php

namespace App\Game\Core;

/**
 * Housekeeping of a planet's wreckfield during an economy update: auto-deploys repair jobs that finished more than
 * 72 h ago and removes (or empties) wreckfields older than 72 h.
 *
 * Collaborators: Database (PLANET_WRECKFIELD, locking reads), ViewHelpers::isModuleAvailable (repair dock module),
 * Singleton (resource table). Both operations are no-ops while the repair dock module is disabled.
 * Holds the wreckfield row read by applyFinishedRepair() so expire() does not query it a second time.
 */
class WreckfieldMaintenance
{
    use \App\Game\Core\GameUtilities;

    /** @var array{0: int, 1: array|false}|null wreckfield row read by applyFinishedRepair() for expire() */
    private $repairJobWreckfield = null;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Automatic deployment of a finished repair job (repair_order_end older than 72 h): the repaired ships are added to
     * the planet and to the built/repaired deltas, the job is cleared in the DB. Locks the wreckfield row (FOR UPDATE).
     *
     * @param array<string, mixed> $planet
     * @param array<int|string, float|int> $builded delta per element id, extended by the repaired ships
     * @param array<int|string, float|int> $repaired amounts that came from repairs, extended
     * @return array<string, mixed> the planet with the repaired ships added
     */
    public function applyFinishedRepair(array $planet, int|float $time, array &$builded, array &$repaired): array
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK)) {
            return $planet;
        }
        $elements = $this->elements();
        $db = $this->db;

        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID FOR UPDATE;";
        $wreckfield = $db->selectSingle($sql, [':planetID' => $planet['id']]);
        $this->repairJobWreckfield = [$planet['id'], $wreckfield];

        if (!empty($wreckfield['repair_order']) && $wreckfield['repair_order_end'] <= $time - TIME_72_HOURS) {
            $ElementQueue = StoredData::decode($wreckfield['repair_order']);

            foreach ($ElementQueue as $elementID => $amount) {
                if (!isset($repaired[$elementID])) {
                    $repaired[$elementID] = 0;
                }
                if (!isset($builded[$elementID])) {
                    $builded[$elementID] = 0;
                }

                $repaired[$elementID]           += $amount;
                $builded[$elementID]            += $amount;
                $planet[$elements->column($elementID)]  += $amount;
            }

            $sql = "UPDATE %%PLANET_WRECKFIELD%% SET `repair_order` = '', `repair_order_start` = 0, `repair_order_end` = 0
                WHERE `planetID` = :planetID;";
            $db->update($sql, [':planetID' => $planet['id']]);

            $this->repairJobWreckfield[1]['repair_order'] = '';
            $this->repairJobWreckfield[1]['repair_order_start'] = 0;
            $this->repairJobWreckfield[1]['repair_order_end'] = 0;
        }

        return $planet;
    }

    /**
     * Checks the wreckfield for deletion after 72 h (also called from the galaxy view). A wreckfield with a running
     * repair order only loses its ships, otherwise the row is deleted.
     *
     * @return bool true, when the wreckfield was deleted or emptied
     */
    public function expire(int $planetID, int|float $time, bool $vmode = false): bool
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK) || $vmode) {
            return false;
        }
        $db = $this->db;

        if ($this->repairJobWreckfield !== null && $this->repairJobWreckfield[0] == $planetID) {
            // applyFinishedRepair() just read (and locked) this very row: no second query
            $wreckfield = $this->repairJobWreckfield[1];
        } else {
            $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID FOR UPDATE;";
            $wreckfield = $db->selectSingle($sql, [':planetID' => $planetID]);
        }
        $this->repairJobWreckfield = null;

        if (!empty($wreckfield) && $wreckfield['created'] <= $time - TIME_72_HOURS) {
            if (empty($wreckfield['repair_order']) || empty(StoredData::decode((string) $wreckfield['repair_order']))) {
                $sql = "DELETE FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID;";
                $db->delete($sql, [':planetID' => $planetID]);
            } else {
                $sql = "UPDATE %%PLANET_WRECKFIELD%% SET `ships` = ''
                    WHERE `planetID` = :planetID;";
                $db->update($sql, [':planetID' => $planetID]);
            }
            return true;
        }
        return false;
    }
}
