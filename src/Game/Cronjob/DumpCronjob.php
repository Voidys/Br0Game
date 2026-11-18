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

namespace App\Game\Cronjob;

use Exception;
use App\Game\Core\Database;
use App\Game\Core\SQLDumper;

/**
 * Database backup (cronjob "databasedump", off by default; Monday 01:30): dumps all prefixed tables into var/backups/.
 */
class DumpCronjob extends AbstractCronjobTask
{
    /**
     * Writes var/backups/v0gameBackup_<dd_mm_YYYY_HH_ii_ss>.sql with all tables of the configured prefix.
     * @throws Exception when no table with the prefix exists.
     */
    public function run(): void
    {
        $prefixCounts	= strlen($this->db->getTablePrefix());
        $dbTables		= [];
        $tableNames		= $this->db->nativeQuery('SHOW TABLE STATUS FROM ' . $this->db->getDatabaseName() . ';');

        foreach ($tableNames as $table) {
            if ($this->db->getTablePrefix() == substr($table['Name'], 0, $prefixCounts)) {
                $dbTables[]	= $table['Name'];
            }
        }

        if (empty($dbTables)) {
            throw new Exception('No tables found for dump.');
        }

        $fileName	= 'v0gameBackup_' . date('d_m_Y_H_i_s', $this->state->runtime->timestamp) . '.sql';
        $filePath	= 'var/backups/' . $fileName;

        $dump	= new SQLDumper($this->db, $this->configs, $this->state);
        $dump->dumpTablesToFile($dbTables, $filePath);
    }
}
