<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Enum\PlanetType;
use App\Game\Enum\AuthLevel;
use App\Game\Core\Log;
use Symfony\Component\HttpFoundation\Response;

/** Account editor tab "planets": edits the planet data: name, size, wipes, position change, moon swap. */
class PlanetsTab extends AccountEditorTab
{
    /** Shows the form, or applies the posted changes (logged) and redirects with a message. */
    public function handle(): Response
    {
        if ($this->state->requestPost) {
            $this->save();
        }
        return $this->responder->render('admin/AccountEditorPagePlanets.html.twig');
    }

    /**
     * Saves the posted planet changes: loads the planet (error message when unknown), collects the field changes and the
     * optional position change, writes up to three planets (the planet, the moon or the planet it swaps with, and the
     * planet that loses its moon), logs every written planet and ends with a success message.
     */
    private function save(): never
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $id = $this->input()->int('id');
        $change_pos = $this->input()->string('change_position');
        $galaxy = $this->input()->int('galaxy');
        $system = $this->input()->int('system');
        $planet = $this->input()->int('planet');

        $sql = "SELECT p.*, u.`authlevel` FROM %%PLANETS%% p
            JOIN %%USERS%% u ON u.`id` = p.`id_owner`
            WHERE p.`id` = :planetID AND p.`universe` = :universe;";
        $beforePlanet = $db->selectSingle($sql, [
            ':planetID' => $id,
            ':universe' => $this->universe()->getEmulated(),
        ]);

        if (empty($beforePlanet)) {
            $this->responder->message($LNG['ad_pla_error_planets2'], 'admin/accounts/edit/planets');
        }

        // update plan: 'values' (column => bound value) / 'expr' (column => fixed SQL expression) / 'afterPlanet' for the planet itself, the suffix 2 and 3 for the second and
        // third planet touched (with their 'beforePlanet2/3')
        $plan = [
            'values' => [], 'expr' => [], 'afterPlanet' => $beforePlanet,
            'values2' => [], 'expr2' => [], 'beforePlanet2' => [], 'afterPlanet2' => [],
            'values3' => [], 'expr3' => [], 'beforePlanet3' => [], 'afterPlanet3' => [],
        ];
        $this->collectFieldChanges($plan);
        if ($change_pos == 'on') {
            $this->collectPositionChange($plan, $beforePlanet, $galaxy, $system, $planet);
        }
        $this->writePlan($plan, $beforePlanet, $id);

        $this->responder->message($LNG['ad_pla_succes'], 'admin/accounts/edit/planets');
    }

    /**
     * Collects the plain field changes of the form (name, wipes of buildings/ships/defenses/queues, size, fields) into
     * the plan of the planet itself.
     *
     * @param array<string, mixed> $plan update plan (modified)
     */
    private function collectFieldChanges(array &$plan): void
    {
        $e = $this->elements();
        $name = $this->input()->string('name', '', UTF8_SUPPORT);
        $diameter = $this->input()->int('diameter');
        $fields = $this->input()->int('fields');
        $buildings = $this->input()->string('0_buildings');
        $ships = $this->input()->string('0_ships');
        $defenses = $this->input()->string('0_defenses');
        $c_hangar = $this->input()->string('0_c_hangar');
        $c_buildings = $this->input()->string('0_c_buildings');


        if (!empty($name)) {
            $plan['values']['name'] = $name;
            $plan['afterPlanet']['name'] = $name;
        }

        if ($buildings == 'on') {
            foreach ($e->ids('build') as $ID) {
                $plan['expr'][$e->column($ID)] = "'0'";
                $plan['afterPlanet'][$ID] = 0;
            }
        }

        if ($ships == 'on') {
            foreach ($e->ids('fleet') as $ID) {
                $plan['expr'][$e->column($ID)] = "'0'";
                $plan['afterPlanet'][$ID] = 0;
            }
        }

        if ($defenses == 'on') {
            foreach ($e->ids('defense') as $ID) {
                $plan['expr'][$e->column($ID)] = "'0'";
                $plan['afterPlanet'][$ID] = 0;
            }
        }

        if ($c_hangar == 'on') {
            $plan['expr']['b_hangar'] = "0";
            $plan['expr']['b_hangar_plus'] = "0";
            $plan['expr']['b_hangar_id'] = "''";
            $plan['afterPlanet']['b_hangar'] = 0;
            $plan['afterPlanet']['b_hangar_plus'] = 0;
            $plan['afterPlanet']['b_hangar_id'] = '';
        }

        if ($c_buildings == 'on') {
            $plan['expr']['b_building'] = "0";
            $plan['expr']['b_building_id'] = "''";
            $plan['afterPlanet']['b_building'] = 0;
            $plan['afterPlanet']['b_building_id'] = '';
        }

        if (!empty($diameter)) {
            $plan['values']['diameter'] = $diameter;
            $plan['afterPlanet']['diameter'] = $diameter;
        }

        if (!empty($fields)) {
            $plan['values']['field_max'] = $fields;
            $plan['afterPlanet']['field_max'] = $fields;
        }
    }

    /**
     * Collects a change of position into the plan: a planet moves to a free position (its moon moves along); a moon
     * swaps to the planet at the target position of the same owner (that planet must have no moon yet and gives up the
     * moon relation of the old planet). Refuses (message) invalid, occupied or foreign positions.
     *
     * @param array<string, mixed> $plan         update plan (modified)
     * @param array<string, mixed> $beforePlanet the edited planet incl. owner's authlevel
     */
    private function collectPositionChange(array &$plan, array $beforePlanet, mixed $galaxy, mixed $system, mixed $planet): void
    {
        $LNG = $this->state->LNG;
        if (
            !$this->planetPositions()->checkPosition(
                $this->universe()->getEmulated(),
                $galaxy,
                $system,
                $planet,
            ) && $beforePlanet['authlevel'] == AuthLevel::User->value
        ) {
            $this->responder->message($LNG['ad_pla_error_planets6'], 'admin/accounts/edit/planets');
        }

        if ($beforePlanet['planet_type'] == PlanetType::Planet->value) {
            $this->planPlanetMove($plan, $beforePlanet, $galaxy, $system, $planet);
        } else {
            $this->planMoonSwap($plan, $beforePlanet, $galaxy, $system, $planet);
        }
    }

    /**
     * A planet moves to a free position: the planet is updated and its moon (if any) moves along.
     *
     * @param array<string, mixed> $plan         update plan (modified)
     * @param array<string, mixed> $beforePlanet the edited planet
     */
    private function planPlanetMove(array &$plan, array $beforePlanet, mixed $galaxy, mixed $system, mixed $planet): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        if (
            !$this->planetPositions()->isPositionFree(
                $this->universe()->getEmulated(),
                $galaxy,
                $system,
                $planet,
                $beforePlanet['planet_type'],
            )
        ) {
            $this->responder->message($LNG['ad_pla_error_planets3'], 'admin/accounts/edit/planets');
        }

        $plan['values']['galaxy'] = $galaxy;
        $plan['values']['system'] = $system;
        $plan['values']['planet'] = $planet;
        $plan['afterPlanet']['galaxy'] = $galaxy;
        $plan['afterPlanet']['system'] = $system;
        $plan['afterPlanet']['planet'] = $planet;

        if ($beforePlanet['id_luna'] > 0) {
            $sql = "SELECT * FROM %%PLANETS%%
                WHERE `id` = :id_luna AND `planet_type` = :type_moon;";
            $plan['beforePlanet2'] = $db->selectSingle($sql, [
                ':id_luna' => $beforePlanet['id_luna'],
                ':type_moon' => PlanetType::Moon->value,
            ]);
            $plan['afterPlanet2'] = $plan['beforePlanet2'];

            $plan['values2']['galaxy'] = $galaxy;
            $plan['values2']['system'] = $system;
            $plan['values2']['planet'] = $planet;
            $plan['afterPlanet2']['galaxy'] = $galaxy;
            $plan['afterPlanet2']['system'] = $system;
            $plan['afterPlanet2']['planet'] = $planet;
        }
    }

    /**
     * A moon swaps to the planet at the target position of the same owner: that planet must exist, belong to the same
     * owner and have no moon yet; it takes over the moon relation of the old planet, which loses it.
     *
     * @param array<string, mixed> $plan         update plan (modified)
     * @param array<string, mixed> $beforePlanet the edited moon
     */
    private function planMoonSwap(array &$plan, array $beforePlanet, mixed $galaxy, mixed $system, mixed $planet): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $sql = "SELECT * FROM %%PLANETS%%
            WHERE `galaxy` = :galaxy AND `system` = :system
            AND `planet` = :planet AND `planet_type` = :type_planet
            AND `universe` = :universe;";
        $plan['beforePlanet2'] = $db->selectSingle($sql, [
            ':galaxy'   => $galaxy,
            ':system'   => $system,
            ':planet'   => $planet,
            ':universe' => $this->universe()->getEmulated(),
            ':type_planet' => PlanetType::Planet->value,
        ]);
        $plan['afterPlanet2'] = $plan['beforePlanet2'];

        if (empty($plan['beforePlanet2'])) {
            $this->responder->message($LNG['ad_pla_error_planets5'], 'admin/accounts/edit/planets');
        }

        if ($plan['beforePlanet2']['id_owner'] != $beforePlanet['id_owner']) {
            $this->responder->message($LNG['ad_pla_error_planets'], 'admin/accounts/edit/planets');
        }

        if ($plan['beforePlanet2']['id_luna'] > 0) {
            $this->responder->message($LNG['ad_pla_error_planets4'], 'admin/accounts/edit/planets');
        }

        $plan['values']['galaxy'] = $galaxy;
        $plan['values']['system'] = $system;
        $plan['values']['planet'] = $planet;
        $plan['afterPlanet']['galaxy'] = $galaxy;
        $plan['afterPlanet']['system'] = $system;
        $plan['afterPlanet']['planet'] = $planet;

        $plan['values2']['id_luna'] = $beforePlanet['id'];
        $plan['afterPlanet2']['id_luna'] = $beforePlanet['id'];

        $sql = "SELECT * FROM %%PLANETS%%
            WHERE `id_luna` = :id AND `planet_type` = :type_planet;";
        $plan['beforePlanet3'] = $db->selectSingle($sql, [
            ':id' => $beforePlanet['id'],
            ':type_planet' => PlanetType::Planet->value,
        ]);
        $plan['afterPlanet3'] = $plan['beforePlanet3'];

        $plan['values3']['id_luna'] = 0;
        $plan['afterPlanet3']['id_luna'] = 0;
    }

    /**
     * Writes the planned updates and logs each written planet (log type 2: planet edit).
     *
     * @param array<string, mixed> $plan         update plan
     * @param array<string, mixed> $beforePlanet the edited planet before the change
     */
    private function writePlan(array $plan, array $beforePlanet, mixed $id): void
    {
        $db = $this->db;
        if (!empty($plan['values']) || !empty($plan['expr'])) {
            $db->updateRows('PLANETS', $plan['values'], ['id' => $id, 'universe' => $this->universe()->getEmulated()], $plan['expr']);

            $LOG = new Log($this->db, $this->configs, $this->state, 2);
            $LOG->target = $id;
            $LOG->universe = $this->universe()->getEmulated();
            $LOG->old = $beforePlanet;
            $LOG->new = $plan['afterPlanet'];
            $LOG->save();
        }
        if (!empty($plan['beforePlanet2']) && (!empty($plan['values2']) || !empty($plan['expr2']))) {
            $db->updateRows('PLANETS', $plan['values2'], ['id' => $plan['beforePlanet2']['id'], 'universe' => $this->universe()->getEmulated()], $plan['expr2']);

            $LOG = new Log($this->db, $this->configs, $this->state, 2);
            $LOG->target = $plan['beforePlanet2']['id'];
            $LOG->universe = $this->universe()->getEmulated();
            $LOG->old = $plan['beforePlanet2'];
            $LOG->new = $plan['afterPlanet2'];
            $LOG->save();
        }
        if (!empty($plan['beforePlanet3']) && (!empty($plan['values3']) || !empty($plan['expr3']))) {
            $db->updateRows('PLANETS', $plan['values3'], ['id' => $plan['beforePlanet3']['id'], 'universe' => $this->universe()->getEmulated()], $plan['expr3']);

            $LOG = new Log($this->db, $this->configs, $this->state, 2);
            $LOG->target = $plan['beforePlanet3']['id'];
            $LOG->universe = $this->universe()->getEmulated();
            $LOG->old = $plan['beforePlanet3'];
            $LOG->new = $plan['afterPlanet3'];
            $LOG->save();
        }
    }
}
