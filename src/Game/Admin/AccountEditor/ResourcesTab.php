<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Core\Log;
use Symfony\Component\HttpFoundation\Response;

/** Account editor tab "resources": edits the resources (metal, crystal, deuterium) of a planet: add, subtract or set. */
class ResourcesTab extends AccountEditorTab
{
    /** Shows the form, or applies the posted changes (logged) and redirects with a message. */
    public function handle(): Response
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $id = $this->input()->int('id');
        $metal = max(0, round($this->input()->float('metal')));
        $cristal = max(0, round($this->input()->float('cristal')));
        $deut = max(0, round($this->input()->float('deut')));

        if ($this->state->requestPost) {
            if (!empty($id)) {
                $sql = "SELECT `metal`, `crystal`, `deuterium` FROM %%PLANETS%%
                WHERE `id` = :planetID;";
                $beforeRess = $db->selectSingle($sql, [
                    ':planetID' => $id,
                ]);
            }
            if (isset($this->state->requestPost['add'])) {
                if (!empty($id)) {
                    $sql = "UPDATE %%PLANETS%% SET `metal` = `metal` + :metal, `crystal` = `crystal` + :cristal,
                    `deuterium` = `deuterium` + :deut
                    WHERE `id` = :id AND `universe` = :universe;";
                    $db->update($sql, [
                        ':metal'    => $metal,
                        ':cristal'  => $cristal,
                        ':deut'     => $deut,
                        ':id'       => $id,
                        ':universe' => $this->universe()->getEmulated(),
                    ]);
                    $afterRess = [
                        'metal'     => ($beforeRess['metal'] + $metal),
                        'crystal'   => ($beforeRess['crystal'] + $cristal),
                        'deuterium' => ($beforeRess['deuterium'] + $deut),
                    ];
                }
            } elseif (isset($this->state->requestPost['delete'])) {
                if (!empty($id)) {
                    $sql = "UPDATE %%PLANETS%% SET `metal` = `metal` - :metal, `crystal` = `crystal` - :cristal,
                    `deuterium` = `deuterium` - :deut
                    WHERE `id` = :id AND `universe` = :universe;";
                    $db->update($sql, [
                        ':metal'    => $metal,
                        ':cristal'  => $cristal,
                        ':deut'     => $deut,
                        ':id'       => $id,
                        ':universe' => $this->universe()->getEmulated(),
                    ]);
                    $afterRess = [
                        'metal'     => ($beforeRess['metal'] - $metal),
                        'crystal'   => ($beforeRess['crystal'] - $cristal),
                        'deuterium' => ($beforeRess['deuterium'] - $deut),
                    ];
                }
            }

            if (!empty($id)) {
                $LOG = new Log($this->db, $this->configs, $this->state, 2);
                $LOG->target = $id;
                $LOG->universe = $this->universe()->getEmulated();
                $LOG->old = $beforeRess;
                $LOG->new = $afterRess;
                $LOG->save();
            }
            if (isset($this->state->requestPost['add'])) {
                $this->responder->message($LNG['ad_add_res_sucess'], 'admin/accounts/edit/resources');
            } elseif (isset($this->state->requestPost['delete'])) {
                $this->responder->message($LNG['ad_delete_res_sucess'], 'admin/accounts/edit/resources');
            }
        }

        return $this->responder->render('admin/AccountEditorPageResources.html.twig');
    }
}
