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
        $LNG = & $this->state->LNG;
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
        $db = $this->db;
        if ($this->state->requestPost) {
            $id = $this->http()->_GP('id', 0);
            foreach ($reslist['defense'] as $ID) {
                $fields[] = "`" . $resource[$ID] . "`";
            }
            $sql = "SELECT " . implode(", ", $fields) . " FROM %%PLANETS%% WHERE `id` = :planetID;";
            $beforeDef = $db->selectSingle($sql, [
                ':planetID' => $id,
            ]);
            $afterDef = [];
            if (isset($this->state->requestPost['add'])) {
                $QryUpdate = [];
                $sql = "UPDATE %%PLANETS%% SET ";
                foreach ($reslist['defense'] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` + :" . $resource[$ID];
                        $params[':' . $resource[$ID]] = $count;
                        $afterDef[$ID] = $beforeDef[$resource[$ID]] + $count;
                    }
                }
                if (!empty($QryUpdate)) {
                    $sql .= implode(", ", $QryUpdate);
                    $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                    $db->update($sql, array_merge($params, [
                        ':id'       => $id,
                        ':universe' => $this->universe()->getEmulated(),
                    ]));
                }
            } elseif (isset($this->state->requestPost['delete'])) {
                $QryUpdate = [];
                $sql = "UPDATE %%PLANETS%% SET ";
                foreach ($reslist['defense'] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` - :" . $resource[$ID];
                        $params[':' . $resource[$ID]] = $count;
                        $afterDef[$ID] = max(0, $beforeDef[$resource[$ID]] - $count);
                    }
                }
                if (!empty($QryUpdate)) {
                    $sql .= implode(", ", $QryUpdate);
                    $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                    $db->update($sql, array_merge($params, [
                        ':id'       => $id,
                        ':universe' => $this->universe()->getEmulated(),
                    ]));
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
