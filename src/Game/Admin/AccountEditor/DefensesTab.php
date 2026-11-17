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
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $db = $this->db;
        if ($this->state->requestPost) {
            $id = $this->http()->_GP('id', 0);
            foreach ($reslist['defense'] as $ID) {
                $fields[] = "`" . $resource[$ID] . "`";
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
                foreach ($reslist['defense'] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[$resource[$ID]] = $count;
                        $afterDef[$ID] = $beforeDef[$resource[$ID]] + $count;
                    }
                }
                if (!empty($QryUpdate)) {
                    $db->updateRows('PLANETS', [], ['id' => $id, 'universe' => $this->universe()->getEmulated()], [], $QryUpdate);
                }
            } elseif (isset($this->state->requestPost['delete'])) {
                $QryUpdate = [];
                foreach ($reslist['defense'] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[$resource[$ID]] = -$count;
                        $afterDef[$ID] = max(0, $beforeDef[$resource[$ID]] - $count);
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
                $this->responder->message($LNG['ad_add_defenses_success'], '?page=accountEditor&edit=defenses');
            } elseif (isset($this->state->requestPost['delete'])) {
                $this->responder->message($LNG['ad_delete_defenses_success'], '?page=accountEditor&edit=defenses');
            }
        }

        foreach ($reslist['defense'] as $ID) {
            $INPUT[$ID] = ['type' => $resource[$ID]];
        }

        $this->responder->assign([
            'inputlist' => $INPUT,
        ]);

        return $this->responder->render('admin/AccountEditorPageDefenses.html.twig');
    }
}
