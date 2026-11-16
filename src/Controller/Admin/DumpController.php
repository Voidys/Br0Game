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

use Exception;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\SQLDumper;
use Symfony\Component\HttpFoundation\Response;

/** Dumps selected database tables into var/backups. */
#[\App\Routing\GamePage('admin.php', 'dump')]
#[\App\Routing\GamePage('admin.php', 'dump', uniPrefix: true)]
class DumpController extends AbstractAdminController
{
    /**
     * Dumping is not available to plain players (authlevel check); no per-page right is needed beyond that.
     */
    protected function construct(): void
    {
        $USER = $this->state->USER;
        if ($USER['authlevel'] == AUTH_USR) {
            $this->deny();
        }
        parent::construct();
    }
    /**
     * Database backup. POST action=dump with dbtables[] (table names with the game prefix) writes an SQL dump to var/backups/ and shows
     * the file name; without a selection a message is shown. Otherwise lists the tables of the game for selection.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;
        if (!isset($this->state->requestPost['action'])) {
            $this->state->requestPost['action'] = '';
        }
        switch ($this->state->requestPost['action']) {
            case 'dump':
                $dbTables = $this->http()->_GP('dbtables', []);
                if (empty($dbTables)) {
                    $this->message($LNG['du_not_tables_selected']);
                }

                $fileName = 'br0gameBackup_' . date('d_m_Y_H_i_s', $this->state->runtime->timestamp) . '.sql';
                $filePath = 'var/backups/' . $fileName;

                $dump = new SQLDumper($this->db, $this->configs, $this->state);
                try {
                    $dump->dumpTablesToFile($dbTables, $filePath);
                } catch (\InvalidArgumentException) {
                    $this->message($LNG['du_not_tables_selected']);
                }
                $this->message($LNG->format('du_success', 'var/backups/' . $fileName));
                // no break
            default:
                $dumpData['perRequest'] = 100;

                $dumpData = [];

                $prefixCounts = strlen($db->getTablePrefix());

                $dumpData['sqlTables'] = [];
                $sqlTableRaw = $db->nativeQuery("SHOW TABLE STATUS FROM `" . $db->getDatabaseName() . "`;");
                foreach ($sqlTableRaw as $table) {
                    if ($db->getTablePrefix() == substr($table['Name'], 0, $prefixCounts)) {
                        $dumpData['sqlTables'][] = $table['Name'];
                    }
                }

                $this->assign([
                    'dumpData'     => $dumpData,
                ]);

                return $this->render('admin/DumpPage.html.twig');
        }
    }
}
