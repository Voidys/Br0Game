<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Core\Log;
use Symfony\Component\HttpFoundation\Response;

/** Account editor tab "buildings": edits the building levels of a planet. */
class BuildingsTab extends AccountEditorTab
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
            $sql = "SELECT * FROM %%PLANETS%% WHERE `id` = :planetID;";
            $PlanetData = $db->selectSingle($sql, [
                ':planetID' => $id,
            ]);
            if (!isset($PlanetData)) {
                $this->responder->message($LNG['ad_add_not_exist'], '?page=accountEditor&edit=buildings');
            }
            $beforeBuildings = [];
            $afterBuildings = [];
            foreach ($reslist['allow'][$PlanetData['planet_type']] as $ID) {
                $beforeBuildings[$ID] = $PlanetData[$resource[$ID]];
            }
            if (isset($this->state->requestPost['add'])) {
                $QryUpdate = [];
                $fields = 0;
                $sql = "UPDATE %%PLANETS%% SET `eco_hash` = '', ";
                foreach ($reslist['allow'][$PlanetData['planet_type']] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` + :" . $resource[$ID];
                        $params[':' . $resource[$ID]] = $count;
                        $afterBuildings[$ID] = $beforeBuildings[$ID] + $count;
                        $fields += $count;
                    }
                }
                if (!empty($QryUpdate)) {
                    $sql .= implode(", ", $QryUpdate);
                    $sql .= ", `field_current` = `field_current` + :fields WHERE `id` = :id AND `universe` = :universe;";
                    $db->update($sql, array_merge($params, [
                        ':fields'   => $fields,
                        ':id'       => $id,
                        ':universe' => $this->universe()->getEmulated(),
                    ]));
                }
            } elseif (isset($this->state->requestPost['delete'])) {
                $QryUpdate = [];
                $fields = 0;
                $sql = "UPDATE %%PLANETS%% SET `eco_hash` = '', ";
                foreach ($reslist['allow'][$PlanetData['planet_type']] as $ID) {
                    $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                    if ($count > 0) {
                        $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` - :" . $resource[$ID];
                        $params[':' . $resource[$ID]] = $count;
                        $afterBuildings[$ID] = max($beforeBuildings[$ID] - $count, 0);
                        $fields += $count;
                    }
                }
                if (!empty($QryUpdate)) {
                    $sql .= implode(", ", $QryUpdate);
                    $sql .= ", `field_current` = `field_current` - :fields WHERE `id` = :id AND `universe` = :universe;";
                    $db->update($sql, array_merge($params, [
                        ':fields'   => $fields,
                        ':id'       => $id,
                        ':universe' => $this->universe()->getEmulated(),
                    ]));
                }
            }

            if (!empty($QryUpdate)) {
                $LOG = new Log($this->db, $this->configs, $this->state, 2);
                $LOG->target = $id;
                $LOG->universe = $this->universe()->getEmulated();
                $LOG->old = $beforeBuildings;
                $LOG->new = $afterBuildings;
                $LOG->save();
            }

            if (isset($this->state->requestPost['add'])) {
                $this->responder->message($LNG['ad_add_build_success'], '?page=accountEditor&edit=buildings');
            } elseif (isset($this->state->requestPost['delete'])) {
                $this->responder->message($LNG['ad_delete_build_success'], '?page=accountEditor&edit=buildings');
            }
        }

        foreach ($reslist['build'] as $ID) {
            $INPUT[$ID] = ['type' => $resource[$ID]];
        }

        $this->responder->assign([
            'inputlist' => $INPUT,
        ]);

        return $this->responder->render('admin/AccountEditorPageBuilds.html.twig');
    }
}
