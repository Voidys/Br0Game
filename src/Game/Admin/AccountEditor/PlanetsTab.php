<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Core\Log;
use Symfony\Component\HttpFoundation\Response;

/** Account editor tab "planets": edits the planet data: name, size, wipes, position change, moon swap. */
class PlanetsTab extends AccountEditorTab
{
    /** Shows the form, or applies the posted changes (logged) and redirects with a message. */
    /** Shows the form, or saves the posted changes. */
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
        $LNG = & $this->state->LNG;
        $db = $this->db;
        $id = $this->http()->_GP('id', 0);
        $change_pos = $this->http()->_GP('change_position', '');
        $galaxy = $this->http()->_GP('galaxy', 0);
        $system = $this->http()->_GP('system', 0);
        $planet = $this->http()->_GP('planet', 0);

        $sql = "SELECT p.*, u.`authlevel` FROM %%PLANETS%% p
            JOIN %%USERS%% u ON u.`id` = p.`id_owner`
            WHERE p.`id` = :planetID AND p.`universe` = :universe;";
        $beforePlanet = $db->selectSingle($sql, [
            ':planetID' => $id,
            ':universe' => $this->universe()->getEmulated(),
        ]);

        if (empty($beforePlanet)) {
            $this->responder->message($LNG['ad_pla_error_planets2'], '?page=accountEditor&edit=planets');
        }

        // update plan: 'QryUpdate'/'params'/'afterPlanet' for the planet itself, the suffix 2 and 3 for the second and
        // third planet touched (with their 'beforePlanet2/3')
        $plan = [
            'QryUpdate' => [], 'params' => [], 'afterPlanet' => $beforePlanet,
            'QryUpdate2' => [], 'params2' => [], 'beforePlanet2' => [], 'afterPlanet2' => [],
            'QryUpdate3' => [], 'params3' => [], 'beforePlanet3' => [], 'afterPlanet3' => [],
        ];
        $this->collectFieldChanges($plan);
        if ($change_pos == 'on') {
            $this->collectPositionChange($plan, $beforePlanet, $galaxy, $system, $planet);
        }
        $this->writePlan($plan, $beforePlanet, $id);

        $this->responder->message($LNG['ad_pla_succes'], '?page=accountEditor&edit=planets');
    }

    /**
     * Collects the plain field changes of the form (name, wipes of buildings/ships/defenses/queues, size, fields) into
     * the plan of the planet itself.
     *
     * @param array<string, mixed> $plan update plan (modified)
     */
    private function collectFieldChanges(array &$plan): void
    {
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
        $name = $this->http()->_GP('name', '', UTF8_SUPPORT);
        $diameter = $this->http()->_GP('diameter', 0);
        $fields = $this->http()->_GP('fields', 0);
        $buildings = $this->http()->_GP('0_buildings', '');
        $ships = $this->http()->_GP('0_ships', '');
        $defenses = $this->http()->_GP('0_defenses', '');
        $c_hangar = $this->http()->_GP('0_c_hangar', '');
        $c_buildings = $this->http()->_GP('0_c_buildings', '');


        if (!empty($name)) {
            $plan['QryUpdate'][] = "`name` = :name";
            $plan['params'][':name'] = $name;
            $plan['afterPlanet']['name'] = $name;
        }

        if ($buildings == 'on') {
            foreach ($reslist['build'] as $ID) {
                $plan['QryUpdate'][] = "`" . $resource[$ID] . "` = '0'";
                $plan['afterPlanet'][$ID] = 0;
            }
        }

        if ($ships == 'on') {
            foreach ($reslist['fleet'] as $ID) {
                $plan['QryUpdate'][] = "`" . $resource[$ID] . "` = '0'";
                $plan['afterPlanet'][$ID] = 0;
            }
        }

        if ($defenses == 'on') {
            foreach ($reslist['defense'] as $ID) {
                $plan['QryUpdate'][] = "`" . $resource[$ID] . "` = '0'";
                $plan['afterPlanet'][$ID] = 0;
            }
        }

        if ($c_hangar == 'on') {
            $plan['QryUpdate'][] = "`b_hangar` = 0";
            $plan['QryUpdate'][] = "`b_hangar_plus` = 0";
            $plan['QryUpdate'][] = "`b_hangar_id` = ''";
            $plan['afterPlanet']['b_hangar'] = 0;
            $plan['afterPlanet']['b_hangar_plus'] = 0;
            $plan['afterPlanet']['b_hangar_id'] = '';
        }

        if ($c_buildings == 'on') {
            $plan['QryUpdate'][] = "`b_building` = 0";
            $plan['QryUpdate'][] = "`b_building_id` = ''";
            $plan['afterPlanet']['b_building'] = 0;
            $plan['afterPlanet']['b_building_id'] = '';
        }

        if (!empty($diameter)) {
            $plan['QryUpdate'][] = "`diameter` = :diameter";
            $plan['params'][':diameter'] = $diameter;
            $plan['afterPlanet']['diameter'] = $diameter;
        }

        if (!empty($fields)) {
            $plan['QryUpdate'][] = "`field_max` = :field_max";
            $plan['params'][':field_max'] = $fields;
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
        $LNG = & $this->state->LNG;
        $db = $this->db;
        if (
            !$this->playerUtil()->checkPosition(
                $this->universe()->getEmulated(),
                $galaxy,
                $system,
                $planet,
            ) && $beforePlanet['authlevel'] == AUTH_USR
        ) {
            $this->responder->message($LNG['ad_pla_error_planets6'], '?page=accountEditor&edit=planets');
        }

        if ($beforePlanet['planet_type'] == TYPE_PLANET) {
            if (
                !$this->playerUtil()->isPositionFree(
                    $this->universe()->getEmulated(),
                    $galaxy,
                    $system,
                    $planet,
                    $beforePlanet['planet_type'],
                )
            ) {
                $this->responder->message($LNG['ad_pla_error_planets3'], '?page=accountEditor&edit=planets');
            }

            $plan['QryUpdate'][] = "`galaxy` = :galaxy";
            $plan['QryUpdate'][] = "`system` = :system";
            $plan['QryUpdate'][] = "`planet` = :planet";
            $plan['params'][':galaxy'] = $galaxy;
            $plan['params'][':system'] = $system;
            $plan['params'][':planet'] = $planet;
            $plan['afterPlanet']['galaxy'] = $galaxy;
            $plan['afterPlanet']['system'] = $system;
            $plan['afterPlanet']['planet'] = $planet;

            if ($beforePlanet['id_luna'] > 0) {
                $sql = "SELECT * FROM %%PLANETS%%
                    WHERE `id` = :id_luna AND `planet_type` = :type_moon;";
                $plan['beforePlanet2'] = $db->selectSingle($sql, [
                    ':id_luna' => $beforePlanet['id_luna'],
                    ':type_moon' => TYPE_MOON,
                ]);
                $plan['afterPlanet2'] = $plan['beforePlanet2'];

                $plan['QryUpdate2'][] = "`galaxy` = :galaxy";
                $plan['QryUpdate2'][] = "`system` = :system";
                $plan['QryUpdate2'][] = "`planet` = :planet";
                $plan['params2'][':galaxy'] = $galaxy;
                $plan['params2'][':system'] = $system;
                $plan['params2'][':planet'] = $planet;
                $plan['params2'][':id'] = $plan['beforePlanet2']['id'];
                $plan['afterPlanet2']['galaxy'] = $galaxy;
                $plan['afterPlanet2']['system'] = $system;
                $plan['afterPlanet2']['planet'] = $planet;
            }
        } else {
            $sql = "SELECT * FROM %%PLANETS%%
                WHERE `galaxy` = :galaxy AND `system` = :system
                AND `planet` = :planet AND `planet_type` = :type_planet
                AND `universe` = :universe;";
            $plan['beforePlanet2'] = $db->selectSingle($sql, [
                ':galaxy'   => $galaxy,
                ':system'   => $system,
                ':planet'   => $planet,
                ':universe' => $this->universe()->getEmulated(),
                ':type_planet' => TYPE_PLANET,
            ]);
            $plan['afterPlanet2'] = $plan['beforePlanet2'];

            if (empty($plan['beforePlanet2'])) {
                $this->responder->message($LNG['ad_pla_error_planets5'], '?page=accountEditor&edit=planets');
            }

            if ($plan['beforePlanet2']['id_owner'] != $beforePlanet['id_owner']) {
                $this->responder->message($LNG['ad_pla_error_planets'], '?page=accountEditor&edit=planets');
            }

            if ($plan['beforePlanet2']['id_luna'] > 0) {
                $this->responder->message($LNG['ad_pla_error_planets4'], '?page=accountEditor&edit=planets');
            }

            $plan['QryUpdate'][] = "`galaxy` = :galaxy";
            $plan['QryUpdate'][] = "`system` = :system";
            $plan['QryUpdate'][] = "`planet` = :planet";
            $plan['params'][':galaxy'] = $galaxy;
            $plan['params'][':system'] = $system;
            $plan['params'][':planet'] = $planet;
            $plan['afterPlanet']['galaxy'] = $galaxy;
            $plan['afterPlanet']['system'] = $system;
            $plan['afterPlanet']['planet'] = $planet;

            $plan['QryUpdate2'][] = "`id_luna` = :id_luna";
            $plan['params2'][':id_luna'] = $beforePlanet['id'];
            $plan['params2'][':id'] = $plan['beforePlanet2']['id'];
            $plan['afterPlanet2']['id_luna'] = $beforePlanet['id'];

            $sql = "SELECT * FROM %%PLANETS%%
                WHERE `id_luna` = :id AND `planet_type` = :type_planet;";
            $plan['beforePlanet3'] = $db->selectSingle($sql, [
                ':id' => $beforePlanet['id'],
                ':type_planet' => TYPE_PLANET,
            ]);
            $plan['afterPlanet3'] = $plan['beforePlanet3'];

            $plan['QryUpdate3'][] = "`id_luna` = :id_luna";
            $plan['params3'][':id_luna'] = 0;
            $plan['params3'][':id'] = $plan['beforePlanet3']['id'];
            $plan['afterPlanet3']['id_luna'] = 0;
        }
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
        if (!empty($plan['QryUpdate'])) {
            $sql = "UPDATE %%PLANETS%% SET ";
            $sql .= implode(", ", $plan['QryUpdate']);
            $sql .= " WHERE `id` = :id AND `universe` = :universe;";
            $db->update($sql, array_merge($plan['params'], [
                ':id'       => $id,
                ':universe' => $this->universe()->getEmulated(),
            ]));

            $LOG = new Log($this->db, $this->configs, $this->state, 2);
            $LOG->target = $id;
            $LOG->universe = $this->universe()->getEmulated();
            $LOG->old = $beforePlanet;
            $LOG->new = $plan['afterPlanet'];
            $LOG->save();
        }
        if (!empty($plan['beforePlanet2']) && !empty($plan['QryUpdate2'])) {
            $sql = "UPDATE %%PLANETS%% SET ";
            $sql .= implode(", ", $plan['QryUpdate2']);
            $sql .= " WHERE `id` = :id AND `universe` = :universe;";
            $db->update($sql, array_merge($plan['params2'], [
                ':universe' => $this->universe()->getEmulated(),
            ]));

            $LOG = new Log($this->db, $this->configs, $this->state, 2);
            $LOG->target = $plan['beforePlanet2']['id'];
            $LOG->universe = $this->universe()->getEmulated();
            $LOG->old = $plan['beforePlanet2'];
            $LOG->new = $plan['afterPlanet2'];
            $LOG->save();
        }
        if (!empty($plan['beforePlanet3']) && !empty($plan['QryUpdate3'])) {
            $sql = "UPDATE %%PLANETS%% SET ";
            $sql .= implode(", ", $plan['QryUpdate3']);
            $sql .= " WHERE `id` = :id AND `universe` = :universe;";
            $db->update($sql, array_merge($plan['params3'], [
                ':universe' => $this->universe()->getEmulated(),
            ]));

            $LOG = new Log($this->db, $this->configs, $this->state, 2);
            $LOG->target = $plan['beforePlanet3']['id'];
            $LOG->universe = $this->universe()->getEmulated();
            $LOG->old = $plan['beforePlanet3'];
            $LOG->new = $plan['afterPlanet3'];
            $LOG->save();
        }
    }
}
