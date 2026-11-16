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
use App\Game\Core\AccountUtil;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Log;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Quick editor for single planets and players (resources, buildings, fleets, authlevel, ...).
 */
#[\App\Routing\GamePage('admin.php', 'quickEditor')]
#[\App\Routing\GamePage('admin.php', 'quickEditor', uniPrefix: true)]
class QuickEditorController extends AbstractAdminController
{
    /**
     * Quick editor (admin?page=quickEditor, loaded as a dialog). Request: edit (`planet` or `player`), id, action=send. Without `action` renders the form
     * (planet: resources, field limit, buildings, fleet, defense; player: name, password, attack flag, research) with the current values. With action=send the
     * changes are saved by savePlanet() / savePlayer(): only fields present in the POST are written. Any other `edit` value returns an empty page.
     */
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $action = $this->http()->_GP('action', '');
        $edit = $this->http()->_GP('edit', '');
        $id = $this->http()->_GP('id', 0);

        $db = $this->db;

        switch ($edit) {
            case 'planet':
                $DataIDs = array_merge($reslist['fleet'], $reslist['build'], $reslist['defense']);
                $SpecifyItemsPQ = "";

                foreach ($DataIDs as $ID) {
                    $SpecifyItemsPQ .= "`" . $resource[$ID] . "`,";
                }
                $sql = "SELECT " . $SpecifyItemsPQ . " `name`, `id_owner`, `planet_type`, `galaxy`, `system`, `planet`, `destruyed`,
                `diameter`, `field_current`, `field_max`, `temp_min`, `temp_max`, `metal`, `crystal`, `deuterium`
                FROM %%PLANETS%% WHERE `id` = :planetID;";
                $PlanetData = $db->selectSingle($sql, [
                    ':planetID' => $id,
                ]);

                if ($action == 'send') {
                    $this->savePlanet((int) $id, $PlanetData, $DataIDs);
                }

                $sql = "SELECT `username` FROM %%USERS%%
                WHERE `id` = :userID AND universe = :universe;";
                $UserInfo = $db->selectSingle($sql, [
                    ':userID'   => $PlanetData['id_owner'],
                    ':universe' => $this->universe()->getEmulated(),
                ]);

                $build = $defense = $fleet = [];

                foreach ($reslist['allow'][$PlanetData['planet_type']] as $ID) {
                    $build[] = [
                        'type'  => $resource[$ID],
                        'name'  => $LNG['tech'][$ID],
                        'count' => $this->viewHelpers()->prettyNumber($PlanetData[$resource[$ID]]),
                        'input' => $PlanetData[$resource[$ID]],
                    ];
                }

                foreach ($reslist['fleet'] as $ID) {
                    $fleet[] = [
                        'type'  => $resource[$ID],
                        'name'  => $LNG['tech'][$ID],
                        'count' => $this->viewHelpers()->prettyNumber($PlanetData[$resource[$ID]]),
                        'input' => $PlanetData[$resource[$ID]],
                    ];
                }

                foreach ($reslist['defense'] as $ID) {
                    $defense[] = [
                        'type'  => $resource[$ID],
                        'name'  => $LNG['tech'][$ID],
                        'count' => $this->viewHelpers()->prettyNumber($PlanetData[$resource[$ID]]),
                        'input' => $PlanetData[$resource[$ID]],
                    ];
                }

                $this->assign([
                    'build'        => $build,
                    'fleet'        => $fleet,
                    'defense'      => $defense,
                    'id'           => $id,
                    'ownerid'      => $PlanetData['id_owner'],
                    'ownername'    => $UserInfo['username'],
                    'name'         => $PlanetData['name'],
                    'galaxy'       => $PlanetData['galaxy'],
                    'system'       => $PlanetData['system'],
                    'planet'       => $PlanetData['planet'],
                    'field_min'    => $PlanetData['field_current'],
                    'field_max'    => $PlanetData['field_max'],
                    'temp_min'     => $PlanetData['temp_min'],
                    'temp_max'     => $PlanetData['temp_max'],
                    'metal'        => floatToString($PlanetData['metal']),
                    'crystal'      => floatToString($PlanetData['crystal']),
                    'deuterium'    => floatToString($PlanetData['deuterium']),
                    'metal_c'      => $this->viewHelpers()->prettyNumber($PlanetData['metal']),
                    'crystal_c'    => $this->viewHelpers()->prettyNumber($PlanetData['crystal']),
                    'deuterium_c'  => $this->viewHelpers()->prettyNumber($PlanetData['deuterium']),
                ]);
                return $this->render('admin/QuickEditorPlanet.html.twig');
            case 'player':
                $DataIDs = $reslist['tech'];
                $SpecifyItemsPQ = "";

                foreach ($DataIDs as $ID) {
                    $SpecifyItemsPQ .= "`" . $resource[$ID] . "`,";
                }
                $sql = "SELECT " . $SpecifyItemsPQ . " `username`, `authlevel`, `galaxy`, `system`, `planet`, `id_planet`, `authattack`, `authlevel`
                FROM %%USERS%%
                WHERE `id` = :userID;";
                $UserData = $db->selectSingle($sql, [':userID' => $id]);
                $ChangePW = $USER['id'] == ROOT_USER || ($id != ROOT_USER && $USER['authlevel'] > $UserData['authlevel']);

                if ($action == 'send') {
                    $this->savePlayer((int) $id, $UserData, $DataIDs, $ChangePW);
                }
                $sql = "SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetID AND `universe` = :universe;";
                $PlanetInfo = $db->selectSingle($sql, [
                    ':planetID' => $UserData['id_planet'],
                    ':universe' => $this->universe()->getEmulated(),
                ]);

                $tech = [];

                foreach ($reslist['tech'] as $ID) {
                    $tech[] = [
                        'type'  => $resource[$ID],
                        'name'  => $LNG['tech'][$ID],
                        'count' => $this->viewHelpers()->prettyNumber($UserData[$resource[$ID]]),
                        'input' => $UserData[$resource[$ID]],
                    ];
                }

                $this->assign([
                    'tech'         => $tech,
                    'id'           => $id,
                    'planetid'     => $UserData['id_planet'],
                    'planetname'   => $PlanetInfo['name'],
                    'name'         => $UserData['username'],
                    'galaxy'       => $UserData['galaxy'],
                    'system'       => $UserData['system'],
                    'planet'       => $UserData['planet'],
                    'authlevel'    => $UserData['authlevel'],
                    'qe_change'    => '',
                    'authattack'   => $UserData['authattack'],
                    'ChangePW'     => $ChangePW,
                    'yesorno'      => [1 => $LNG['one_is_yes_1'], 0 => $LNG['one_is_yes_0']],
                ]);
                return $this->render('admin/QuickEditorUser.html.twig');
        }

        // nothing selected to edit: empty page, as before
        return new Response();
    }

    /** True when the request carries a value for $name (a missing field means "leave unchanged"). */
    private function has(string $name): bool
    {
        return isset(($this->state->requestInput ?? [])[$name]);
    }

    /**
     * Formats a non-negative number as plain digits for binding (no exponent notation), capped at the
     * unsigned 64-bit maximum of the counter columns.
     */
    private function digits(float $value): string
    {
        return $value >= 1.8446744073709552E+19 ? '18446744073709551615' : sprintf('%.0f', $value);
    }

    /**
     * Saves the planet form (action=send): only the fields present in the request are written, everything else keeps
     * its value. Building levels are clamped to 0..255, fleet/defense counts to 0..2^64-1, resources to >= 0.
     * Column names come from the $resource whitelist (build/fleet/defense ids), all values are bound parameters.
     * Recalculates `field_current` from the changed buildings that use fields on this planet type, clears `eco_hash`
     * and writes an admin log entry (type 2) with old and new values. Ends the request with the success message.
     *
     * @param array<string, mixed> $PlanetData the planet row as loaded before the edit
     * @param int[] $DataIDs element ids (fleet, buildings, defense) that the form may edit
     */
    private function savePlanet(int $id, array $PlanetData, array $DataIDs): never
    {
        $LNG = $this->state->LNG;
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;

        $sets = [];
        $params = [];
        $old = [];
        $new = [];
        $fields = $PlanetData['field_current'];

        foreach ($DataIDs as $ID) {
            $column = $resource[$ID];
            $old[$ID] = $PlanetData[$column];
            $new[$ID] = $PlanetData[$column];
            if (!$this->has($column)) {
                continue;
            }

            $level = max(0, round($this->http()->_GP($column, 0.0)));
            $level = in_array($ID, $reslist['build']) ? min($level, 255) : $level;
            if (in_array($ID, $reslist['allow'][$PlanetData['planet_type']])) {
                $fields += $level - $PlanetData[$column];
            }

            $sets[] = '`' . $column . '` = :res' . $ID;
            $params[':res' . $ID] = $this->digits($level);
            $new[$ID] = $params[':res' . $ID];
        }

        foreach (['metal', 'crystal', 'deuterium'] as $column) {
            $ID = array_search($column, $resource, true);
            $old[$ID] = $PlanetData[$column];
            $new[$ID] = $PlanetData[$column];
            if ($this->has($column)) {
                $sets[] = '`' . $column . '` = :' . $column;
                $params[':' . $column] = $this->digits(max(0, round($this->http()->_GP($column, 0.0))));
                $new[$ID] = $params[':' . $column];
            }
        }

        $old['field_max'] = $new['field_max'] = $PlanetData['field_max'];
        if ($this->has('field_max')) {
            $sets[] = '`field_max` = :field_max';
            $params[':field_max'] = $new['field_max'] = $this->http()->_GP('field_max', 0);
        }
        if ($this->has('name')) {
            $sets[] = '`name` = :name';
            $params[':name'] = $this->http()->_GP('name', '', UTF8_SUPPORT);
        }

        $sets[] = '`field_current` = :fields';
        $params[':fields'] = (int) $fields;
        $sets[] = "`eco_hash` = ''";
        $params[':planetID'] = $id;
        $params[':universe'] = $this->universe()->getEmulated();
        $this->db->update(
            'UPDATE %%PLANETS%% SET ' . implode(', ', $sets) . ' WHERE `id` = :planetID AND `universe` = :universe;',
            $params,
        );

        $LOG = new Log($this->db, $this->configs, $this->state, 2);
        $LOG->target = $id;
        $LOG->old = $old;
        $LOG->new = $new;
        $LOG->save();

        throw new \App\Game\Core\EndOfRequest($LNG->format(
            'qe_edit_planet_sucess',
            $PlanetData['name'],
            $PlanetData['galaxy'],
            $PlanetData['system'],
            $PlanetData['planet'],
        ));
    }

    /**
     * Saves the player form (action=send): only fields present in the request are written. Tech levels are clamped
     * to 0..255 (column names from the $resource whitelist), the password is hashed, stored on the player's account and only changed when the
     * acting admin may (root, or an admin of higher authlevel than the target), the "attack allowed" checkbox
     * stores the player's own authlevel (0 for plain users or when off). The checkbox is absent from the POST when
     * unchecked, so it counts as submitted whenever the form's `name` field is present too. Writes an admin log
     * entry (type 1) with old and new values; skips update and log when the request carries no editable field.
     * Ends the request with the success message.
     *
     * @param array<string, mixed> $UserData the user row as loaded before the edit
     * @param int[] $DataIDs research element ids the form may edit
     */
    private function savePlayer(int $id, array $UserData, array $DataIDs, bool $ChangePW): never
    {
        $LNG = $this->state->LNG;
        $resource = $this->state->resource;

        $sets = [];
        $params = [];
        $old = [];
        $new = [];
        $newPasswordHash = null;

        foreach ($DataIDs as $ID) {
            $column = $resource[$ID];
            $old[$ID] = $UserData[$column];
            $new[$ID] = $UserData[$column];
            if ($this->has($column)) {
                $sets[] = '`' . $column . '` = :tech' . $ID;
                $params[':tech' . $ID] = $new[$ID] = min(abs($this->http()->_GP($column, 0)), 255);
            }
        }

        if (!empty($this->state->requestPost['password']) && $ChangePW) {
            $newPasswordHash = $this->accountUtil()->cryptPassword($this->http()->_GP('password', '', true));
        }

        $old['username'] = $new['username'] = $UserData['username'];
        if ($this->has('name')) {
            $sets[] = '`username` = :username';
            $params[':username'] = $new['username'] = $this->http()->_GP('name', '', UTF8_SUPPORT);
        }

        $old['authattack'] = $new['authattack'] = $UserData['authattack'];
        if ($this->has('authattack') || $this->has('name')) {
            $sets[] = '`authattack` = :authattack';
            $params[':authattack'] = $new['authattack'] = ($UserData['authlevel'] != AUTH_USR && $this->http()->_GP('authattack', '') == 'on' ? $UserData['authlevel'] : 0);
        }

        if ($newPasswordHash !== null) {
            // the password belongs to the account (shared by all of its universes), not to the user row
            $this->db->update(
                'UPDATE %%ACCOUNTS%% a INNER JOIN %%ACCOUNTS_TO_USERS%% au ON au.account_id = a.id SET a.`password` = :password WHERE au.user_id = :userID;',
                [':password' => $newPasswordHash, ':userID' => $id],
            );
        }

        if ($sets !== []) {
            $params[':userID'] = $id;
            $params[':universe'] = $this->universe()->getEmulated();
            $this->db->update(
                'UPDATE %%USERS%% SET ' . implode(', ', $sets) . ' WHERE `id` = :userID AND `universe` = :universe;',
                $params,
            );

            $LOG = new Log($this->db, $this->configs, $this->state, 1);
            $LOG->target = $id;
            $LOG->old = $old;
            $LOG->new = $new;
            $LOG->save();
        }

        throw new \App\Game\Core\EndOfRequest($LNG->format('qe_edit_player_sucess', $UserData['username'], $id));
    }
}
