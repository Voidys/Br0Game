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
        $e = $this->elements();
        $db = $this->db;
        if ($this->state->requestPost) {
            $id = $this->input()->int('id');
            $sql = "SELECT * FROM %%PLANETS%% WHERE `id` = :planetID;";
            $PlanetData = $db->selectSingle($sql, [
                ':planetID' => $id,
            ]);
            if (!isset($PlanetData)) {
                $this->responder->message($LNG['ad_add_not_exist'], 'admin/accounts/edit/buildings');
            }
            $beforeBuildings = [];
            $afterBuildings = [];
            foreach ($e->allowedOn($PlanetData['planet_type']) as $ID) {
                $beforeBuildings[$ID] = $PlanetData[$e->column($ID)];
            }
            if (isset($this->state->requestPost['add'])) {
                $QryUpdate = [];
                $fields = 0;
                foreach ($e->allowedOn($PlanetData['planet_type']) as $ID) {
                    $count = max(0, round($this->input()->float($e->column($ID))));
                    if ($count > 0) {
                        $QryUpdate[$e->column($ID)] = $count;
                        $afterBuildings[$ID] = $beforeBuildings[$ID] + $count;
                        $fields += $count;
                    }
                }
                if (!empty($QryUpdate)) {
                    $db->updateRows('PLANETS', [], ['id' => $id, 'universe' => $this->universe()->getEmulated()], ['eco_hash' => "''"], $QryUpdate + ['field_current' => $fields]);
                }
            } elseif (isset($this->state->requestPost['delete'])) {
                $QryUpdate = [];
                $fields = 0;
                foreach ($e->allowedOn($PlanetData['planet_type']) as $ID) {
                    $count = max(0, round($this->input()->float($e->column($ID))));
                    if ($count > 0) {
                        $QryUpdate[$e->column($ID)] = -$count;
                        $afterBuildings[$ID] = max($beforeBuildings[$ID] - $count, 0);
                        $fields += $count;
                    }
                }
                if (!empty($QryUpdate)) {
                    $db->updateRows('PLANETS', [], ['id' => $id, 'universe' => $this->universe()->getEmulated()], ['eco_hash' => "''"], $QryUpdate + ['field_current' => -$fields]);
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
                $this->responder->message($LNG['ad_add_build_success'], 'admin/accounts/edit/buildings');
            } elseif (isset($this->state->requestPost['delete'])) {
                $this->responder->message($LNG['ad_delete_build_success'], 'admin/accounts/edit/buildings');
            }
        }

        foreach ($e->ids('build') as $ID) {
            $INPUT[$ID] = ['type' => $e->column($ID)];
        }

        $this->responder->assign([
            'inputlist' => $INPUT,
        ]);

        return $this->responder->render('admin/AccountEditorPageBuilds.html.twig');
    }
}
