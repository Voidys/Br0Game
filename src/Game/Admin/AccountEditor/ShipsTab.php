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
        $LNG = & $this->state->LNG;
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
        $db = $this->db;
        if ($this->state->requestPost) {
            $id = $this->http()->_GP('id', 0);
            foreach ($reslist['fleet'] as $ID) {
                $fields[] = "`" . $resource[$ID] . "`";
            }
            $sql = "SELECT " . implode(", ", $fields) . " FROM %%PLANETS%% WHERE `id` = :planetID;";
            $beforeShips = $db->selectSingle($sql, [
                ':planetID' => $id,
            ]);
            $afterShips = [];
            if (isset($this->state->requestPost['add'])) {
                $QryUpdate = [];
                $sql = "UPDATE %%PLANETS%% SET `eco_hash` = '', ";
                foreach ($reslist['fleet'] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` + :" . $resource[$ID];
                        $params[':' . $resource[$ID]] = $count;
                        $afterShips[$ID] = ($beforeShips[$resource[$ID]] ?? 0) + $count;
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
                $sql = "UPDATE %%PLANETS%% SET `eco_hash` = '', ";
                foreach ($reslist['fleet'] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` - :" . $resource[$ID];
                        $params[':' . $resource[$ID]] = $count;
                        $afterShips[$ID] = max(0, $beforeShips[$ID] - $count);
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
        foreach ($reslist['fleet'] as $ID) {
            $INPUT[$ID] = [
                'type' => $resource[$ID],
            ];
        }

        $this->responder->assign([
            'inputlist' => $INPUT,
        ]);

        return $this->responder->render('admin/AccountEditorPageShips.html.twig');
    }
}
