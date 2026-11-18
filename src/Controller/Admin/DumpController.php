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
use App\Game\Core\Database;
use App\Game\Core\SQLDumper;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Dumps selected database tables into var/backups. */
class DumpController extends AbstractAdminController
{
    /**
     * Dumping is not available to plain players (authlevel check); no per-page right is needed beyond that.
     */
    protected function construct(): void
    {
        $USER = $this->state->USER;
        if ($USER['authlevel'] == AuthLevel::User->value) {
            $this->deny();
        }
        parent::construct();
    }
    /**
     * Lists the tables of the game (with the game prefix) for selection.
     */
    #[GameRoute('admin', 'admin/dump', 'dump')]
    public function show(): Response
    {
        $db = $this->db;
        $dumpData = [];

        $prefixCounts = strlen($db->getTablePrefix());

        $dumpData['sqlTables'] = [];
        $sqlTableRaw = $db->nativeQuery("SHOW TABLE STATUS FROM `" . $db->getDatabaseName() . "`;");
        foreach ($sqlTableRaw as $table) {
            if ($db->getTablePrefix() == substr($table['Name'], 0, $prefixCounts)) {
                $dumpData['sqlTables'][] = $table['Name'];
            }
        }

        return $this->render('admin/DumpPage.html.twig', [
            'dumpData'     => $dumpData,
        ]);
    }

    /**
     * Database backup: POST dbtables[] (table names with the game prefix) writes an SQL dump to var/backups/ and shows
     * the file name; without a selection a message is shown.
     */
    #[GameRoute('admin', 'admin/dump', 'dump', 'dump', ['POST'])]
    public function dump(): Response
    {
        $LNG = $this->state->LNG;
        $dbTables = $this->input()->stringList('dbtables');
        if (empty($dbTables)) {
            return $this->messageResponse($LNG['du_not_tables_selected']);
        }

        $fileName = 'v0gameBackup_' . date('d_m_Y_H_i_s', $this->state->runtime->timestamp) . '.sql';
        $filePath = 'var/backups/' . $fileName;

        $dump = new SQLDumper($this->db, $this->configs, $this->state);
        try {
            $dump->dumpTablesToFile($dbTables, $filePath);
        } catch (\InvalidArgumentException) {
            return $this->messageResponse($LNG['du_not_tables_selected']);
        }

        return $this->messageResponse($LNG->format('du_success', 'var/backups/' . $fileName));
    }
}
