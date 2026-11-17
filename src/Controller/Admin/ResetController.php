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

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Config;
use App\Game\Core\Universe;

/**
 * Resets parts of the universe (players, planets, research, fleets, ...).
 */
class ResetController extends AbstractAdminController
{
    /**
     * Only the root user (ROOT_USER) may reset a universe; everyone else gets the permission page.
     */
    protected function construct(): void
    {
        $USER = $this->state->USER;

        if ($USER['id'] != ROOT_USER) {
            $this->deny();
        }

        parent::construct();
    }

    /** The reset form. */
    #[GameRoute('admin', 'admin/reset', 'reset')]
    public function show(): Response
    {
        return $this->render('admin/ResetPage.html.twig', $this->formTexts());
    }

    /**
     * Resets selected parts of the emulated universe. The POST checkboxes (value `on`) select the parts: players, planets, moons,
     * defenses, ships, h_d (ships and defenses), edif_p / edif_l / edif (buildings on planets / moons / both), inves / inves_c (research,
     * officers), dark (dark matter), resources, notes, rw (battle reports), friends, alliances, fleets, banneds, messages, statpoints.
     * Reset values come from the universe's `<resource>_start` config; the columns are taken from $reslist/$resource, never from the
     * request. Everything runs against the emulated universe only. Answers with a confirmation message.
     */
    #[GameRoute('admin', 'admin/reset', 'reset', 'run', ['POST'])]
    public function run(): Response
    {
        $dbcol = $this->resetColumns();
        // all or nothing: a failing step must not leave a half reset universe
        $this->db->transactional(function () use ($dbcol): void {
            $this->resetPlayersAndPlanets();
            $this->resetHangarsAndDefenses($dbcol);
            $this->resetBuildings($dbcol);
            $this->resetResearch($dbcol);
            $this->resetResources($dbcol);
            $this->resetGeneral();
        });

        return $this->messageResponse($this->state->LNG['re_reset_excess'], $this->router->generate('admin_reset_show'), 3);
    }

    /**
     * Builds the column => value maps of the reset: zero for every building, research, fleet and defense column and the
     * configured `<resource>_start` values for planet and player resources (columns come from $reslist/$resource).
     *
     * @return array<string, array<string, mixed>> keys build, tech, fleet, defense, resource_planet_start, resource_user_start
     */
    private function resetColumns(): array
    {
        $e = $this->elements();
        $config = $this->configs->get($this->universe()->getEmulated());

        foreach ($e->ids('build') as $ID) {
            $dbcol['build'][$e->column($ID)] = '0';
        }

        foreach ($e->ids('tech') as $ID) {
            $dbcol['tech'][$e->column($ID)] = '0';
        }

        foreach ($e->ids('fleet') as $ID) {
            $dbcol['fleet'][$e->column($ID)] = '0';
        }

        foreach ($e->ids('defense') as $ID) {
            $dbcol['defense'][$e->column($ID)] = '0';
        }

        foreach ($e->resourceIds(1) as $ID) {
            if (isset($config->{$e->column($ID) . '_start'})) {
                $dbcol['resource_planet_start'][$e->column($ID)] = $config->{$e->column($ID) . '_start'};
            }
        }

        foreach ($e->resourceIds(3) as $ID) {
            if (isset($config->{$e->column($ID) . '_start'})) {
                $dbcol['resource_user_start'][$e->column($ID)] = $config->{$e->column($ID) . '_start'};
            }
        }

        return $dbcol;
    }

    /**
     * Checkboxes `players`, `planets`, `moons`: deletes all players (except the owner of 1:1:1) with their planets,
     * all planets that are not a player's main planet, or all moons (the other planets lose their moon link).
     */
    private function resetPlayersAndPlanets(): void
    {
        $db = $this->db;

        if (($this->state->requestPost['players'] ?? '') === 'on') {
            $ID = $db->selectSingle("SELECT `id_owner` FROM %%USERS%% WHERE `universe` = :universe AND `galaxy` = 1 AND `system` = 1 AND `planet` = 1;", [
                ':universe' => $this->universe()->getEmulated(),
            ], 'id_owner');

            // through the player deletion, so fleets, messages, stats, settings and the account link go too (user 1 stays)
            $ids = $db->select('SELECT `id` FROM %%USERS%% WHERE `universe` = :universe AND `id` != :ownerID AND `id` != :root;', [
                ':universe' => $this->universe()->getEmulated(),
                ':ownerID'  => (int) $ID,
                ':root'     => ROOT_USER,
            ]);
            foreach ($ids as $row) {
                $this->playerDeletion()->deletePlayer((int) $row['id']);
            }
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
    }

    /**
     * Checkboxes `defenses`, `ships`, `h_d`: zeroes defense / fleet columns on all planets, or clears the shipyard queue.
     *
     * @param array<mixed> $dbcol
     */
    private function resetHangarsAndDefenses(array $dbcol): void
    {
        $db = $this->db;

        if (($this->state->requestPost['defenses'] ?? '') === 'on') {
            $db->updateRows('PLANETS', $dbcol['defense'], ['universe' => $this->universe()->getEmulated()]);
        }

        if (($this->state->requestPost['ships'] ?? '') === 'on') {
            $db->updateRows('PLANETS', $dbcol['fleet'], ['universe' => $this->universe()->getEmulated()]);
        }

        if (($this->state->requestPost['h_d'] ?? '') === 'on') {
            $db->update("UPDATE %%PLANETS%% SET `b_hangar` = '0', `b_hangar_id` = '' WHERE `universe` = :universe;", [
                ':universe' => $this->universe()->getEmulated(),
            ]);
        }
    }

    /**
     * Checkboxes `edif_p`, `edif_l`, `edif`: zeroes the building levels (on all planets or on moons only) or clears the
     * building queue.
     *
     * @param array<mixed> $dbcol
     */
    private function resetBuildings(array $dbcol): void
    {
        $db = $this->db;

        if (($this->state->requestPost['edif_p'] ?? '') === 'on') {
            $db->updateRows('PLANETS', $dbcol['build'] + ['field_current' => '0'], ['universe' => $this->universe()->getEmulated()]);
        }

        if (($this->state->requestPost['edif_l'] ?? '') === 'on') {
            $db->updateRows('PLANETS', $dbcol['build'] + ['field_current' => '0'], ['universe' => $this->universe()->getEmulated(), 'planet_type' => '3']);
        }

        if (($this->state->requestPost['edif'] ?? '') === 'on') {
            $db->update("UPDATE %%PLANETS%% SET `b_building` = '0', `b_building_id` = '' WHERE `universe` = :universe;", [
                ':universe' => $this->universe()->getEmulated(),
            ]);

        }
    }

    /**
     * Checkboxes `inves`, `inves_c`: zeroes the research levels of all players or clears the research queue.
     *
     * @param array<mixed> $dbcol
     */
    private function resetResearch(array $dbcol): void
    {
        $db = $this->db;

        if (($this->state->requestPost['inves'] ?? '') === 'on') {
            $db->updateRows('USERS', $dbcol['tech'], ['universe' => $this->universe()->getEmulated()]);
        }

        if (($this->state->requestPost['inves_c'] ?? '') === 'on') {
            $db->update("UPDATE %%USERS%% SET `b_tech_planet` = '0', `b_tech` = '0', `b_tech_id` = '0', `b_tech_queue` = '' WHERE `universe` = :universe;", [
                ':universe' => $this->universe()->getEmulated(),
            ]);
        }
    }

    /**
     * Checkboxes `dark`, `resources`: sets dark matter (players) / metal, crystal, deuterium (planets) back to the
     * configured start values.
     *
     * @param array<mixed> $dbcol
     */
    private function resetResources(array $dbcol): void
    {
        $db = $this->db;

        if (($this->state->requestPost['dark'] ?? '') === 'on') {
            $db->updateRows('USERS', $dbcol['resource_user_start'], ['universe' => $this->universe()->getEmulated()]);
        }

        if (($this->state->requestPost['resources'] ?? '') === 'on') {
            $db->updateRows('PLANETS', $dbcol['resource_planet_start'], ['universe' => $this->universe()->getEmulated()]);
        }
    }

    /**
     * Checkboxes `notes`, `rw`, `friends`, `alliances`, `fleets`, `banneds`, `messages`, `statpoints`: deletes the
     * respective universe data (alliances and bans also reset the players' flags).
     */
    private function resetGeneral(): void
    {
        $db = $this->db;

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
            // the events go with their fleets (a fleet row alone leaves the event behind until the cleaner cron runs)
            $db->delete("DELETE %%FLEETS%%, %%FLEETS_EVENT%% FROM %%FLEETS%% LEFT JOIN %%FLEETS_EVENT%% ON %%FLEETS%%.`id` = %%FLEETS_EVENT%%.`fleetID` WHERE %%FLEETS%%.`universe` = :universe;", [
                ':universe' => $this->universe()->getEmulated(),
            ]);
        }

        if (($this->state->requestPost['banneds'] ?? '') === 'on') {
            $db->delete("DELETE FROM %%USERS_BANNED%% WHERE `universe` = :universe;", [
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
    }

    /**
     * Translated labels of the reset form.
     *
     * @return array<string, string>
     */
    private function formTexts(): array
    {
        $LNG = $this->state->LNG;

        return [
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
        ];
    }
}
