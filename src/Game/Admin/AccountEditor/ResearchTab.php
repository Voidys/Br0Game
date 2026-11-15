<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Core\Log;
use Symfony\Component\HttpFoundation\Response;

/** Account editor tab "researchs": edits the research levels of a player. */
class ResearchTab extends AccountEditorTab
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
            foreach ($reslist['tech'] as $ID) {
                $fields[] = "`" . $resource[$ID] . "`";
            }
            $sql = "SELECT " . implode(", ", $fields) . " FROM %%USERS%% WHERE `id` = :userID;";
            $beforeResearch = $db->selectSingle($sql, [
                ':userID' => $id,
            ]);
            $afterResearch = [];
            if (isset($this->state->requestPost['add'])) {
                $QryUpdate = [];
                $sql = "UPDATE %%USERS%% SET ";
                foreach ($reslist['tech'] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` + :" . $resource[$ID];
                        $params[':' . $resource[$ID]] = $count;
                        $afterResearch[$ID] = $beforeResearch[$resource[$ID]] + $count;
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
                $sql = "UPDATE %%USERS%% SET ";
                foreach ($reslist['tech'] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` - :" . $resource[$ID];
                        $params[':' . $resource[$ID]] = $count;
                        $afterResearch[$ID] = max(0, $beforeResearch[$resource[$ID]] - $count);
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
                $LOG = new Log($this->db, $this->configs, $this->state, 1);
                $LOG->target = $id;
                $LOG->universe = $this->universe()->getEmulated();
                $LOG->old = $beforeResearch;
                $LOG->new = $afterResearch;
                $LOG->save();
            }
            if (isset($this->state->requestPost['add'])) {
                $this->responder->message($LNG['ad_add_tech_success'], '?page=accountEditor&edit=researchs');
            } elseif (isset($this->state->requestPost['delete'])) {
                $this->responder->message($LNG['ad_delete_tech_success'], '?page=accountEditor&edit=researchs');
            }
        }

        foreach ($reslist['tech'] as $ID) {
            $INPUT[$ID] = ['type' => $resource[$ID]];
        }

        $this->responder->assign([
            'inputlist' => $INPUT,
        ]);

        return $this->responder->render('admin/AccountEditorPageResearch.html.twig');
    }
}
