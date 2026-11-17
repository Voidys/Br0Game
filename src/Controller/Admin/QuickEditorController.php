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
        $action = $this->http()->_GP('action', '');
        $edit = $this->http()->_GP('edit', '');
        $id = $this->http()->_GP('id', 0);

        switch ($edit) {
            case 'planet':
                return $this->planetForm($action, $id);
            case 'player':
                return $this->playerForm($action, $id);
        }

        // nothing selected to edit: empty page, as before
        return new Response();
    }

    /**
     * Planet editor: loads the planet (element columns from the resource table), saves it first on action=send (ends the
     * request), then renders the form with owner name, resources and the building/fleet/defense lists.
     */
    private function planetForm(mixed $action, mixed $id): Response
    {
        $e = $this->elements();
        $db = $this->db;

        $DataIDs = array_merge($e->ids('fleet'), $e->ids('build'), $e->ids('defense'));
        // the element columns come from the game's resource table
        $planetColumns = [];
        foreach ($DataIDs as $ID) {
            $planetColumns[] = '`' . $e->column($ID) . '`';
        }
        $PlanetData = $db->fetchRow(
            $db->qb()
                ->select(...$planetColumns)
                ->addSelect('`name`', '`id_owner`', '`planet_type`', '`galaxy`', '`system`', '`planet`', '`destruyed`', '`diameter`', '`field_current`', '`field_max`', '`temp_min`', '`temp_max`', '`metal`', '`crystal`', '`deuterium`')
                ->from($db->table('PLANETS'))
                ->where('`id` = :planetID')
                ->setParameter('planetID', $id),
        );

        if ($action == 'send') {
            if (!is_array($PlanetData) || !$this->mayActOn((int) $PlanetData['id_owner'], true)) {
                $this->deny();
            }
            $this->savePlanet((int) $id, $PlanetData, $DataIDs);
        }

        $sql = "SELECT `username` FROM %%USERS%%
        WHERE `id` = :userID AND universe = :universe;";
        $UserInfo = $db->selectSingle($sql, [
            ':userID'   => $PlanetData['id_owner'],
            ':universe' => $this->universe()->getEmulated(),
        ]);

        $build = $this->elementRows($e->allowedOn($PlanetData['planet_type']), $PlanetData);
        $fleet = $this->elementRows($e->ids('fleet'), $PlanetData);
        $defense = $this->elementRows($e->ids('defense'), $PlanetData);

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
    }

    /**
     * Player editor: loads the player (research columns from the resource table), saves on action=send (ends the
     * request), then renders the form with the home planet name and the research list.
     */
    private function playerForm(mixed $action, mixed $id): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $db = $this->db;

        $DataIDs = $e->ids('tech');
        $userColumns = [];
        foreach ($DataIDs as $ID) {
            $userColumns[] = '`' . $e->column($ID) . '`';
        }
        $UserData = $db->fetchRow(
            $db->qb()
                ->select(...$userColumns)
                ->addSelect('`username`', '`authlevel`', '`galaxy`', '`system`', '`planet`', '`id_planet`', '`authattack`')
                ->from($db->table('USERS'))
                ->where('`id` = :userID')
                ->setParameter('userID', $id),
        );
        // the password belongs to the account, so every player of the account (all universes) must rank below the actor
        $accountTop = (int) $db->selectSingle(
            'SELECT MAX(u.`authlevel`) AS top FROM %%ACCOUNTS_TO_USERS%% au INNER JOIN %%ACCOUNTS_TO_USERS%% other ON other.account_id = au.account_id INNER JOIN %%USERS%% u ON u.id = other.user_id WHERE au.user_id = :userID;',
            [':userID' => $id],
            'top',
        );
        $ChangePW = $USER['id'] == ROOT_USER || ($id != ROOT_USER && $USER['authlevel'] > max((int) $UserData['authlevel'], $accountTop));

        if ($action == 'send') {
            if (!$this->mayActOn((int) $id, true)) {
                $this->deny();
            }
            $this->savePlayer((int) $id, $UserData, $DataIDs, $ChangePW);
        }
        $sql = "SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetID AND `universe` = :universe;";
        $PlanetInfo = $db->selectSingle($sql, [
            ':planetID' => $UserData['id_planet'],
            ':universe' => $this->universe()->getEmulated(),
        ]);

        $tech = $this->elementRows($e->ids('tech'), $UserData);

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

    /**
     * Rows of an element list for the form: column name, translated name, formatted count and raw input value.
     *
     * @param int[] $ids element ids
     * @param array<string, mixed> $row the planet/user row holding the element columns
     * @return list<array{type: string, name: string, count: string, input: mixed}>
     */
    private function elementRows(array $ids, array $row): array
    {
        $LNG = $this->state->LNG;
        $e = $this->elements();

        $rows = [];
        foreach ($ids as $ID) {
            $rows[] = [
                'type'  => $e->column($ID),
                'name'  => $LNG['tech'][$ID],
                'count' => $this->viewHelpers()->prettyNumber($row[$e->column($ID)]),
                'input' => $row[$e->column($ID)],
            ];
        }
        return $rows;
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
        $e = $this->elements();

        // columns to write (column => value, bound by Database::updateRows)
        $values = [];
        $old = [];
        $new = [];
        $fields = $PlanetData['field_current'];

        foreach ($DataIDs as $ID) {
            $column = $e->column($ID);
            $old[$ID] = $PlanetData[$column];
            $new[$ID] = $PlanetData[$column];
            if (!$this->has($column)) {
                continue;
            }

            $level = max(0, round($this->http()->_GP($column, 0.0)));
            $level = $e->isBuilding($ID) ? min($level, 255) : $level;
            if ($e->isAllowedOn($ID, (int) $PlanetData['planet_type'])) {
                $fields += $level - $PlanetData[$column];
            }

            $values[$column] = $this->digits($level);
            $new[$ID] = $values[$column];
        }

        foreach (['metal', 'crystal', 'deuterium'] as $column) {
            $ID = $e->idOfColumn($column);
            $old[$ID] = $PlanetData[$column];
            $new[$ID] = $PlanetData[$column];
            if ($this->has($column)) {
                $values[$column] = $this->digits(max(0, round($this->http()->_GP($column, 0.0))));
                $new[$ID] = $values[$column];
            }
        }

        $old['field_max'] = $new['field_max'] = $PlanetData['field_max'];
        if ($this->has('field_max')) {
            $values['field_max'] = $new['field_max'] = $this->http()->_GP('field_max', 0);
        }
        if ($this->has('name')) {
            $values['name'] = $this->http()->_GP('name', '', UTF8_SUPPORT);
        }

        $values['field_current'] = (int) $fields;
        // eco_hash is cleared so the economy is recalculated
        $this->db->updateRows('PLANETS', $values, ['id' => $id, 'universe' => $this->universe()->getEmulated()], ['eco_hash' => "''"]);

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
        $e = $this->elements();

        $values = [];
        $old = [];
        $new = [];
        $newPasswordHash = null;

        foreach ($DataIDs as $ID) {
            $column = $e->column($ID);
            $old[$ID] = $UserData[$column];
            $new[$ID] = $UserData[$column];
            if ($this->has($column)) {
                $values[$column] = $new[$ID] = min(abs($this->http()->_GP($column, 0)), 255);
            }
        }

        if (!empty($this->state->requestPost['password']) && $ChangePW) {
            $newPasswordHash = $this->accountUtil()->cryptPassword($this->http()->rawInput('password'));
        }

        $old['username'] = $new['username'] = $UserData['username'];
        if ($this->has('name')) {
            $values['username'] = $new['username'] = $this->http()->_GP('name', '', UTF8_SUPPORT);
        }

        $old['authattack'] = $new['authattack'] = $UserData['authattack'];
        if ($this->has('authattack') || $this->has('name')) {
            $values['authattack'] = $new['authattack'] = ($UserData['authlevel'] != AUTH_USR && $this->http()->_GP('authattack', '') == 'on' ? $UserData['authlevel'] : 0);
        }

        if ($newPasswordHash !== null) {
            // the password belongs to the account (shared by all of its universes), not to the user row
            $this->db->update(
                'UPDATE %%ACCOUNTS%% a INNER JOIN %%ACCOUNTS_TO_USERS%% au ON au.account_id = a.id SET a.`password` = :password WHERE au.user_id = :userID;',
                [':password' => $newPasswordHash, ':userID' => $id],
            );
        }

        if ($values !== []) {
            $this->db->updateRows('USERS', $values, ['id' => $id, 'universe' => $this->universe()->getEmulated()]);
        }

        if ($values !== [] || $newPasswordHash !== null) {
            $LOG = new Log($this->db, $this->configs, $this->state, 1);
            $LOG->target = $id;
            $LOG->old = $old;
            $LOG->new = $new;
            $LOG->save();
        }

        throw new \App\Game\Core\EndOfRequest($LNG->format('qe_edit_player_sucess', $UserData['username'], $id));
    }
}
