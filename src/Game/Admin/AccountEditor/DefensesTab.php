<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Core\Log;
use Symfony\Component\HttpFoundation\Response;

/** Account editor tab "defenses": edits the defense units of a planet. */
class DefensesTab extends AccountEditorTab
{
    /** Shows the form, or applies the posted changes (logged) and redirects with a message. */
    public function handle(): Response
    {
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $db = $this->db;
        if ($this->state->requestPost) {
            $id = $this->input()->int('id');
            foreach ($e->ids('defense') as $ID) {
                $fields[] = "`" . $e->column($ID) . "`";
            }
            // the columns come from the game's resource table
            $beforeDef = $db->fetchRow(
                $db->qb()
                    ->select(...$fields)
                    ->from($db->table('PLANETS'))
                    ->where('`id` = :planetID')
                    ->setParameter('planetID', $id),
            );
            $afterDef = [];
            if (isset($this->state->requestPost['add'])) {
                $QryUpdate = [];
                foreach ($e->ids('defense') as $ID) {
                    $count = max(0, round($this->input()->float($e->column($ID))));
                    if ($count > 0) {
                        $QryUpdate[$e->column($ID)] = $count;
                        $afterDef[$ID] = $beforeDef[$e->column($ID)] + $count;
                    }
                }
                if (!empty($QryUpdate)) {
                    $db->updateRows('PLANETS', [], ['id' => $id, 'universe' => $this->universe()->getEmulated()], [], $QryUpdate);
                }
            } elseif (isset($this->state->requestPost['delete'])) {
                $QryUpdate = [];
                foreach ($e->ids('defense') as $ID) {
                    $count = max(0, round($this->input()->float($e->column($ID))));
                    if ($count > 0) {
                        $QryUpdate[$e->column($ID)] = -$count;
                        $afterDef[$ID] = max(0, $beforeDef[$e->column($ID)] - $count);
                    }
                }
                if (!empty($QryUpdate)) {
                    $db->updateRows('PLANETS', [], ['id' => $id, 'universe' => $this->universe()->getEmulated()], [], $QryUpdate);
                }
            }

            if (!empty($QryUpdate)) {
                $LOG = new Log($this->db, $this->configs, $this->state, 2);
                $LOG->target = $id;
                $LOG->universe = $this->universe()->getEmulated();
                $LOG->old = $beforeDef;
                $LOG->new = $afterDef;
                $LOG->save();
            }
            if (isset($this->state->requestPost['add'])) {
                $this->responder->message($LNG['ad_add_defenses_success'], 'admin/accounts/edit/defenses');
            } elseif (isset($this->state->requestPost['delete'])) {
                $this->responder->message($LNG['ad_delete_defenses_success'], 'admin/accounts/edit/defenses');
            }
        }

        foreach ($e->ids('defense') as $ID) {
            $INPUT[$ID] = ['type' => $e->column($ID)];
        }

        $this->responder->assign([
            'inputlist' => $INPUT,
        ]);

        return $this->responder->render('admin/AccountEditorPageDefenses.html.twig');
    }
}
