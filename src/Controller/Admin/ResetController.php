<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Resets parts of the universe (players, planets, research, fleets, ...).
 */
#[\App\Routing\GamePage('admin.php', 'reset')]
#[\App\Routing\GamePage('admin.php', 'reset', uniPrefix: true)]
class ResetController extends AbstractAdminController
{
    /**
     * Only the root user (ROOT_USER) may reset a universe; everyone else ends in EndOfRequest.
     */
    protected function construct(): void
    {
        $USER = $this->state->USER;

        if ($USER['id'] != ROOT_USER) {
            throw new \App\Game\Core\EndOfRequest();
        }

        parent::construct();
    }

    /**
     * Resets selected parts of the emulated universe. The POST checkboxes (value `on`) select the parts: players, planets, moons,
     * defenses, ships, h_d (ships and defenses), edif_p / edif_l / edif (buildings on planets / moons / both), inves / inves_c (research,
     * officers), dark (dark matter), resources, notes, rw (battle reports), friends, alliances, fleets, banneds, messages, statpoints.
     * Reset values come from the universe's `<resource>_start` config; the columns are taken from $reslist/$resource, never from the
     * request. Everything runs against the emulated universe only. Ends with a confirmation message; without POST it renders the form.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $USER = $this->state->USER;
        $config = $this->configs->get($this->universe()->getEmulated());
        $db = $this->db;

        if ($this->state->requestPost) {
            foreach ($reslist['build'] as $ID) {
                $dbcol['build'][$ID] = "`" . $resource[$ID] . "` = '0'";
            }

            foreach ($reslist['tech'] as $ID) {
                $dbcol['tech'][$ID] = "`" . $resource[$ID] . "` = '0'";
            }

            foreach ($reslist['fleet'] as $ID) {
                $dbcol['fleet'][$ID] = "`" . $resource[$ID] . "` = '0'";
            }

            foreach ($reslist['defense'] as $ID) {
                $dbcol['defense'][$ID] = "`" . $resource[$ID] . "` = '0'";
            }

            foreach ($reslist['resstype'][1] as $ID) {
                if (isset($config->{$resource[$ID] . '_start'})) {
                    $dbcol['resource_planet_start'][$ID] = "`" . $resource[$ID] . "` = " . $config->{$resource[$ID]
                        . '_start'};
                }
            }

            foreach ($reslist['resstype'][3] as $ID) {
                if (isset($config->{$resource[$ID] . '_start'})) {
                    $dbcol['resource_user_start'][$ID] = "`" . $resource[$ID] . "` = " . $config->{$resource[$ID]
                        . '_start'};
                }
            }

            // Players and Planets

            if (($this->state->requestPost['players'] ?? '') === 'on') {
                $ID = $db->selectSingle("SELECT `id_owner` FROM %%USERS%% WHERE `universe` = :universe AND `galaxy` = 1 AND `system` = 1 AND `planet` = 1;", [
                    ':universe' => $this->universe()->getEmulated(),
                ], 'id_owner');

                $db->delete("DELETE FROM %%USERS%% WHERE `universe` = :universe AND `id` != :ownerID;", [
                    ':universe' => $this->universe()->getEmulated(),
                    ':ownerID'  => $ID,
                ]);

                $db->delete("DELETE FROM %%PLANETS%% WHERE `universe` = :universe AND `id_owner` != :ownerID;", [
                    ':universe' => $this->universe()->getEmulated(),
                    ':ownerID'  => $ID,
                ]);

            }

            if (($this->state->requestPost['planets'] ?? '') === 'on') {
                $db->delete("DELETE FROM %%PLANETS%% WHERE `universe` = :universe AND `id` NOT IN (SELECT `id_planet` FROM %%USERS%% WHERE `universe` = :universe);", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
                $db->update("UPDATE %%PLANETS%% SET `id_luna` = 0 WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['moons'] ?? '') === 'on') {
                $db->delete("DELETE FROM %%PLANETS%% WHERE `universe` = :universe AND `planet_type` = 3;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);

                $db->update("UPDATE %%PLANETS%% SET `id_luna` = 0 WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            // HANGARES Y DEFENSAS
            if (($this->state->requestPost['defenses'] ?? '') === 'on') {
                $db->update("UPDATE %%PLANETS%% SET " . implode(", ", $dbcol['defense']) . " WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['ships'] ?? '') === 'on') {
                $db->update("UPDATE %%PLANETS%% SET " . implode(", ", $dbcol['fleet']) . " WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['h_d'] ?? '') === 'on') {
                $db->update("UPDATE %%PLANETS%% SET `b_hangar` = '0', `b_hangar_id` = '' WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            // EDIFICIOS
            if (($this->state->requestPost['edif_p'] ?? '') === 'on') {
                $db->update("UPDATE %%PLANETS%% SET " . implode(", ", $dbcol['build']) . ", `field_current` = '0' WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['edif_l'] ?? '') === 'on') {
                $db->update("UPDATE %%PLANETS%% SET " . implode(", ", $dbcol['build']) . ", `field_current` = '0' WHERE `universe` = :universe AND `planet_type` = '3';", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['edif'] ?? '') === 'on') {
                $db->update("UPDATE %%PLANETS%% SET `b_building` = '0', `b_building_id` = '' WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);

            }

            // INVESTIGACIONES Y OFICIALES
            if (($this->state->requestPost['inves'] ?? '') === 'on') {
                $db->update("UPDATE %%USERS%% SET " . implode(", ", $dbcol['tech']) . " WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['inves_c'] ?? '') === 'on') {
                $db->update("UPDATE %%USERS%% SET `b_tech_planet` = '0', `b_tech` = '0', `b_tech_id` = '0', `b_tech_queue` = '' WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            // RECURSOS
            if (($this->state->requestPost['dark'] ?? '') === 'on') {
                $db->update("UPDATE %%USERS%% SET " . implode(", ", $dbcol['resource_user_start']) . " WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['resources'] ?? '') === 'on') {
                $db->update("UPDATE %%PLANETS%% SET " . implode(", ", $dbcol['resource_planet_start']) . " WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            // GENERAL
            if (($this->state->requestPost['notes'] ?? '') === 'on') {
                $db->delete("DELETE FROM %%NOTES%% WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['rw'] ?? '') === 'on') {
                $db->delete("DELETE FROM %%TOPKB%% WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['friends'] ?? '') === 'on') {
                $db->delete("DELETE FROM %%BUDDY%% WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['alliances'] ?? '') === 'on') {
                $db->delete("DELETE FROM %%ALLIANCE%% WHERE `ally_universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
                $db->update("UPDATE %%USERS%% SET `ally_id` = '0', `ally_register_time` = '0', `ally_rank_id` = '0' WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['fleets'] ?? '') === 'on') {
                $db->delete("DELETE FROM %%FLEETS%% WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['banneds'] ?? '') === 'on') {
                $db->delete("DELETE FROM %%BANNED%% WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);

                $db->update("UPDATE %%USERS%% SET `bana` = '0', `banaday` = '0' WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['messages'] ?? '') === 'on') {
                $db->delete("DELETE FROM %%MESSAGES%% WHERE `message_universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }

            if (($this->state->requestPost['statpoints'] ?? '') === 'on') {
                $db->delete("DELETE FROM %%STATPOINTS%% WHERE `universe` = :universe;", [
                    ':universe' => $this->universe()->getEmulated(),
                ]);
            }
            $this->message($LNG['re_reset_excess'], '?page=reset', 3);
        }

        $this->assign([
            'button_submit'                  => $LNG['button_submit'],
            're_reset_universe_confirmation' => $LNG['re_reset_universe_confirmation'],
            're_reset_all'                   => $LNG['re_reset_all'],
            're_reset_all'                   => $LNG['re_reset_all'],
            're_defenses_and_ships'          => $LNG['re_defenses_and_ships'],
            're_reset_buldings'              => $LNG['re_reset_buldings'],
            're_buildings_lu'                => $LNG['re_buildings_lu'],
            're_buildings_pl'                => $LNG['re_buildings_pl'],
            're_buldings'                    => $LNG['re_buldings'],
            're_reset_hangar'                => $LNG['re_reset_hangar'],
            're_ships'                       => $LNG['re_ships'],
            're_defenses'                    => $LNG['re_defenses'],
            're_resources_met_cry'           => $LNG['re_resources_met_cry'],
            're_resources'                   => $LNG['re_resources'],
            're_reset_invest'                => $LNG['re_reset_invest'],
            're_investigations'              => $LNG['re_investigations'],
            're_inve'                        => $LNG['re_inve'],
            're_reset_statpoints'            => $LNG['re_reset_statpoints'],
            're_reset_messages'              => $LNG['re_reset_messages'],
            're_reset_banned'                => $LNG['re_reset_banned'],
            're_reset_errors'                => $LNG['re_reset_errors'],
            're_reset_fleets'                => $LNG['re_reset_fleets'],
            're_reset_allys'                 => $LNG['re_reset_allys'],
            're_reset_buddies'               => $LNG['re_reset_buddies'],
            're_reset_rw'                    => $LNG['re_reset_rw'],
            're_reset_notes'                 => $LNG['re_reset_notes'],
            're_reset_moons'                 => $LNG['re_reset_moons'],
            're_reset_planets'               => $LNG['re_reset_planets'],
            're_reset_player'                => $LNG['re_reset_player'],
            're_player_and_planets'          => $LNG['re_player_and_planets'],
            're_general'                     => $LNG['re_general'],
        ]);

        return $this->render('admin/ResetPage.html.twig');
    }
}
