<?php

namespace App\Game\Admin\Creator;

use Symfony\Component\HttpFoundation\Response;

/** Creator tab "moon": creates a moon for a planet. */
class MoonCreator extends CreatorTab
{
    /** Shows the creation form, or creates the object from the posted form and shows the result. */
    public function handle(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        if ($this->state->requestPost) {
            $db = $this->db;

            $PlanetID = $this->input()->int('add_moon');
            $MoonName = $this->input()->string('name', '', UTF8_SUPPORT);
            $Diameter = $this->input()->int('diameter');

            $MoonPlanet = $db->selectSingle(
                "SELECT temp_max, temp_min, id_luna, galaxy, `system`, planet, planet_type, destruyed, id_owner FROM %%PLANETS%% WHERE id = :planetId AND universe = :universe AND planet_type = 1 AND destruyed = 0;",
                [
                    ':planetId' => $PlanetID,
                    ':universe' => $this->universe()->getEmulated(),
                ],
            );

            if (!isset($MoonPlanet)) {
                $this->responder->message($LNG['mo_planet_doesnt_exist'], 'admin/creator/moon', 3, true);
            }

            $moonId = $this->planetManager()->createMoon(
                $this->universe()->getEmulated(),
                $MoonPlanet['galaxy'],
                $MoonPlanet['system'],
                $MoonPlanet['planet'],
                $MoonPlanet['id_owner'],
                20,
                $MoonName,
                (($this->state->requestPost['diameter_check'] ?? '') === 'on' ? null : $Diameter),
            );

            if ($moonId > 0) { // createMoon() returns 0 when the planet already has a moon
                $this->responder->message($LNG['mo_moon_added'], 'admin/creator/moon', 3, true);
            } else {
                $this->responder->message($LNG['mo_moon_unavaible'], 'admin/creator/moon', 3, true);
            }
        }

        $this->responder->assign([
            'admin_auth'          => $USER['authlevel'],
            'universum'           => $LNG['mu_universe'],
            'po_add_moon'         => $LNG['po_add_moon'],
            'input_id_planet'     => $LNG['input_id_planet'],
            'mo_moon_name'        => $LNG['mo_moon_name'],
            'mo_diameter'         => $LNG['mo_diameter'],
            'mo_temperature'      => $LNG['mo_temperature'],
            'mo_fields_avaibles'  => $LNG['mo_fields_avaibles'],
            'button_add'          => $LNG['button_add'],
            'new_creator_refresh' => $LNG['new_creator_refresh'],
            'mo_moon'             => $LNG['fcm_moon'],
            'new_creator_go_back' => $LNG['new_creator_go_back'],
        ]);

        return $this->responder->render('admin/CreatePageMoon.html.twig');
    }
}
