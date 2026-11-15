<?php

namespace App\Game\Admin\Creator;

use Symfony\Component\HttpFoundation\Response;

/** Creator tab "planet": creates an additional planet for a player. */
class PlanetCreator extends CreatorTab
{
    /** Shows the creation form, or creates the object from the posted form and shows the result. */
    public function handle(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        if ($this->state->requestPost) {
            $db = $this->db;

            $id = $this->http()->_GP('id', 0);
            $Galaxy = $this->http()->_GP('galaxy', 0);
            $System = $this->http()->_GP('system', 0);
            $Planet = $this->http()->_GP('planet', 0);
            $name = $this->http()->_GP('name', '', UTF8_SUPPORT);
            $field_max = $this->http()->_GP('field_max', 0);

            $config = $this->configs->get($this->universe()->getEmulated());

            if ($Galaxy > $config->max_galaxy || $System > $config->max_system || $Planet > $config->max_planets) {
                $this->responder->message($LNG['po_complete_all2'], '?page=creator&mode=planet', 3, true);
            }


            $ISUser = $db->selectSingle(
                "SELECT id, authlevel FROM %%USERS%% WHERE id = :id AND universe = :universe;",
                [
                    ':id'       => $id,
                    ':universe' => $this->universe()->getEmulated(),
                ],
            );
            if (!$this->playerUtil()->checkPosition($this->universe()->getEmulated(), $Galaxy, $System, $Planet) || !isset($ISUser)) {
                $this->responder->message($LNG['po_complete_all'], '?page=creator&mode=planet', 3, true);
            }

            $planetId = $this->playerUtil()->createPlanet(
                $Galaxy,
                $System,
                $Planet,
                $this->universe()->getEmulated(),
                $id,
                null,
                false,
                $ISUser['authlevel'],
            );

            // optional field limit and name (the former string building produced "SET , name" without a field limit)
            $set = [];
            $params = [':planetId' => $planetId];
            if ($field_max > 0) {
                $set[] = 'field_max = :fieldMax';
                $params[':fieldMax'] = (int) $field_max;
            }
            if (!empty($name)) {
                $set[] = 'name = :name';
                $params[':name'] = $name;
            }
            if ($set !== []) {
                $db->update('UPDATE %%PLANETS%% SET ' . implode(', ', $set) . ' WHERE id = :planetId;', $params);
            }

            $this->responder->message($LNG['po_complete_succes'], '?page=creator&mode=planet', 3, true);
        }

        $this->responder->assign([
            'admin_auth'          => $USER['authlevel'],
            'po_add_planet'       => $LNG['po_add_planet'],
            'po_galaxy'           => $LNG['po_galaxy'],
            'po_system'           => $LNG['po_system'],
            'po_planet'           => $LNG['po_planet'],
            'input_id_user'       => $LNG['input_id_user'],
            'new_creator_coor'    => $LNG['new_creator_coor'],
            'po_name_planet'      => $LNG['po_name_planet'],
            'po_fields_max'       => $LNG['po_fields_max'],
            'button_add'          => $LNG['button_add'],
            'po_colony'           => $LNG['fcp_colony'],
            'new_creator_refresh' => $LNG['new_creator_refresh'],
            'new_creator_go_back' => $LNG['new_creator_go_back'],
        ]);

        return $this->responder->render('admin/CreatePagePlanet.html.twig');
    }
}
