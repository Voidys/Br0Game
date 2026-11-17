<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Core\Log;
use Symfony\Component\HttpFoundation\Response;

/** Account editor tab "ships": edits the ships of a planet. */
class ShipsTab extends AccountEditorTab
{
    /** Shows the form, or applies the posted changes (logged) and redirects with a message. */
    public function handle(): Response
    {
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $db = $this->db;
        if ($this->state->requestPost) {
            $id = $this->http()->_GP('id', 0);
            foreach ($e->ids('fleet') as $ID) {
                $fields[] = "`" . $e->column($ID) . "`";
            }
            // the columns come from the game's resource table
            $beforeShips = $db->fetchRow(
                $db->qb()
                    ->select(...$fields)
                    ->from($db->table('PLANETS'))
                    ->where('`id` = :planetID')
                    ->setParameter('planetID', $id),
            );
            $afterShips = [];
            if (isset($this->state->requestPost['add'])) {
                $QryUpdate = [];
                foreach ($e->ids('fleet') as $ID) {
                    $count = max(0, round($this->http()->_GP($e->column($ID), 0.0)));
                    if ($count > 0) {
                        $QryUpdate[$e->column($ID)] = $count;
                        $afterShips[$ID] = ($beforeShips[$e->column($ID)] ?? 0) + $count;
                    }
                }
                if (!empty($QryUpdate)) {
                    $db->updateRows('PLANETS', [], ['id' => $id, 'universe' => $this->universe()->getEmulated()], ['eco_hash' => "''"], $QryUpdate);
                }
            } elseif (isset($this->state->requestPost['delete'])) {
                $QryUpdate = [];
                foreach ($e->ids('fleet') as $ID) {
                    $count = max(0, round($this->http()->_GP($e->column($ID), 0.0)));
                    if ($count > 0) {
                        $QryUpdate[$e->column($ID)] = -$count;
                        $afterShips[$ID] = max(0, ($beforeShips[$e->column($ID)] ?? 0) - $count);
                    }
                }
                if (!empty($QryUpdate)) {
                    $db->updateRows('PLANETS', [], ['id' => $id, 'universe' => $this->universe()->getEmulated()], ['eco_hash' => "''"], $QryUpdate);
                }
            }

            if (!empty($QryUpdate)) {
                $LOG = new Log($this->db, $this->configs, $this->state, 2);
                $LOG->target = $id;
                $LOG->universe = $this->universe()->getEmulated();
                $LOG->old = $beforeShips;
                $LOG->new = $afterShips;
                $LOG->save();
            }
            if (isset($this->state->requestPost['add'])) {
                $this->responder->message($LNG['ad_add_ships_sucess'], '?page=accountEditor&edit=ships');
            } elseif (isset($this->state->requestPost['delete'])) {
                $this->responder->message($LNG['ad_delete_ships_sucess'], '?page=accountEditor&edit=ships');
            }
        }

        $parse['ships'] = "";
        foreach ($e->ids('fleet') as $ID) {
            $INPUT[$ID] = [
                'type' => $e->column($ID),
            ];
        }

        $this->responder->assign([
            'inputlist' => $INPUT,
        ]);

        return $this->responder->render('admin/AccountEditorPageShips.html.twig');
    }
}
