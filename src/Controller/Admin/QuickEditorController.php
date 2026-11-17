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

use App\Game\Enum\AuthLevel;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Database;
use App\Game\Core\Log;

/**
 * Quick editor for single planets and players (resources, buildings, fleets, authlevel, ...).
 */
class QuickEditorController extends AbstractAdminController
{
    /**
     * Quick editor form (loaded as a dialog). Request: edit (`planet` or `player`), id. Renders the form (planet: resources, field limit,
     * buildings, fleet, defense; player: name, password, attack flag, research) with the current values. Any other `edit` value returns an
     * empty page.
     */
    #[GameRoute('admin', 'admin/quick-edit/{edit}/{id}', 'quickEditor', 'show', requirements: ['id' => '\d+'])]
    public function show(): Response
    {
        $id = $this->input()->int('id');

        switch ($this->input()->string('edit')) {
            case 'planet':
                return $this->planetForm($id);
            case 'player':
                return $this->playerForm($id);
        }

        // nothing selected to edit: empty page, as before
        return new Response();
    }

    /**
     * Saves the quick editor form (POST, same request as the form): the changes are written by savePlanet() / savePlayer(), only fields
     * present in the POST are written. Answers with the success message (shown in the dialog).
     */
    #[GameRoute('admin', 'admin/quick-edit/{edit}/{id}', 'quickEditor', 'save', ['POST'], ['id' => '\d+'])]
    public function save(): Response
    {
        $id = $this->input()->int('id');

        switch ($this->input()->string('edit')) {
            case 'planet':
                return $this->saveFromPlanetForm($id);
            case 'player':
                return $this->saveFromPlayerForm($id);
        }

        return new Response();
    }

    /**
     * The planet row with the element columns (from the game's resource table) and the element ids the form may edit.
     *
     * @return array{0: array<string, mixed>|false, 1: int[]}
     */
    private function loadPlanet(int $id): array
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

        return [$PlanetData, $DataIDs];
    }

    /** Planet form POST: saves the planet (the actor must be allowed to act on its owner) and answers with the success message. */
    private function saveFromPlanetForm(int $id): Response
    {
        [$PlanetData, $DataIDs] = $this->loadPlanet($id);

        if (!is_array($PlanetData) || !$this->mayActOn((int) $PlanetData['id_owner'], true)) {
            return $this->denyResponse();
        }

        return $this->savePlanet($id, $PlanetData, $DataIDs);
    }

    /**
     * Planet editor: loads the planet (element columns from the resource table) and renders the form with owner name,
     * resources and the building/fleet/defense lists.
     */
    private function planetForm(int $id): Response
    {
        $e = $this->elements();
        $db = $this->db;

        [$PlanetData] = $this->loadPlanet($id);

        $sql = "SELECT `username` FROM %%USERS%%
        WHERE `id` = :userID AND universe = :universe;";
        $UserInfo = $db->selectSingle($sql, [
            ':userID'   => $PlanetData['id_owner'],
            ':universe' => $this->universe()->getEmulated(),
        ]);

        $build = $this->elementRows($e->allowedOn($PlanetData['planet_type']), $PlanetData);
        $fleet = $this->elementRows($e->ids('fleet'), $PlanetData);
        $defense = $this->elementRows($e->ids('defense'), $PlanetData);

        return $this->render('admin/QuickEditorPlanet.html.twig', [
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
    }

    /**
     * The player row with the research columns (from the game's resource table), the research element ids the form may edit and whether
     * the actor may change the password. The password belongs to the account, so every player of the account (all universes) must rank
     * below the actor.
     *
     * @return array{0: array<string, mixed>|false, 1: int[], 2: bool}
     */
    private function loadPlayer(int $id): array
    {
        $USER = $this->state->USER;
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
        $accountTop = (int) $db->selectSingle(
            'SELECT MAX(u.`authlevel`) AS top FROM %%ACCOUNTS_TO_USERS%% au INNER JOIN %%ACCOUNTS_TO_USERS%% other ON other.account_id = au.account_id INNER JOIN %%USERS%% u ON u.id = other.user_id WHERE au.user_id = :userID;',
            [':userID' => $id],
            'top',
        );
        $ChangePW = $USER['id'] == ROOT_USER || ($id != ROOT_USER && $USER['authlevel'] > max((int) $UserData['authlevel'], $accountTop));

        return [$UserData, $DataIDs, $ChangePW];
    }

    /** Player form POST: saves the player (the actor must be allowed to act on it) and answers with the success message. */
    private function saveFromPlayerForm(int $id): Response
    {
        [$UserData, $DataIDs, $ChangePW] = $this->loadPlayer($id);

        if (!$this->mayActOn($id, true)) {
            return $this->denyResponse();
        }

        return $this->savePlayer($id, $UserData, $DataIDs, $ChangePW);
    }

    /**
     * Player editor: loads the player (research columns from the resource table) and renders the form with the home planet
     * name and the research list.
     */
    private function playerForm(int $id): Response
    {
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $db = $this->db;

        [$UserData, , $ChangePW] = $this->loadPlayer($id);

        $sql = "SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetID AND `universe` = :universe;";
        $PlanetInfo = $db->selectSingle($sql, [
            ':planetID' => $UserData['id_planet'],
            ':universe' => $this->universe()->getEmulated(),
        ]);

        $tech = $this->elementRows($e->ids('tech'), $UserData);

        return $this->render('admin/QuickEditorUser.html.twig', [
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
     * Saves the planet form: only the fields present in the request are written, everything else keeps
     * its value. Building levels are clamped to 0..255, fleet/defense counts to 0..2^64-1, resources to >= 0.
     * Column names come from the $resource whitelist (build/fleet/defense ids), all values are bound parameters.
     * Recalculates `field_current` from the changed buildings that use fields on this planet type, clears `eco_hash`
     * and writes an admin log entry (type 2) with old and new values. Answers with the success message.
     *
     * @param array<string, mixed> $PlanetData the planet row as loaded before the edit
     * @param int[] $DataIDs element ids (fleet, buildings, defense) that the form may edit
     */
    private function savePlanet(int $id, array $PlanetData, array $DataIDs): Response
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

            $level = max(0, round($this->input()->float($column)));
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
                $values[$column] = $this->digits(max(0, round($this->input()->float($column))));
                $new[$ID] = $values[$column];
            }
        }

        $old['field_max'] = $new['field_max'] = $PlanetData['field_max'];
        if ($this->has('field_max')) {
            $values['field_max'] = $new['field_max'] = $this->input()->int('field_max');
        }
        if ($this->has('name')) {
            $values['name'] = $this->input()->string('name', '', UTF8_SUPPORT);
        }

        $values['field_current'] = (int) $fields;
        // eco_hash is cleared so the economy is recalculated
        $this->db->updateRows('PLANETS', $values, ['id' => $id, 'universe' => $this->universe()->getEmulated()], ['eco_hash' => "''"]);

        $LOG = new Log($this->db, $this->configs, $this->state, 2);
        $LOG->target = $id;
        $LOG->old = $old;
        $LOG->new = $new;
        $LOG->save();

        return new Response($LNG->format(
            'qe_edit_planet_sucess',
            $PlanetData['name'],
            $PlanetData['galaxy'],
            $PlanetData['system'],
            $PlanetData['planet'],
        ));
    }

    /**
     * Saves the player form: only fields present in the request are written. Tech levels are clamped
     * to 0..255 (column names from the $resource whitelist), the password is hashed, stored on the player's account and only changed when the
     * acting admin may (root, or an admin of higher authlevel than the target), the "attack allowed" checkbox
     * stores the player's own authlevel (0 for plain users or when off). The checkbox is absent from the POST when
     * unchecked, so it counts as submitted whenever the form's `name` field is present too. Writes an admin log
     * entry (type 1) with old and new values; skips update and log when the request carries no editable field.
     * Answers with the success message.
     *
     * @param array<string, mixed> $UserData the user row as loaded before the edit
     * @param int[] $DataIDs research element ids the form may edit
     */
    private function savePlayer(int $id, array $UserData, array $DataIDs, bool $ChangePW): Response
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
                $values[$column] = $new[$ID] = min(abs($this->input()->int($column)), 255);
            }
        }

        if (!empty($this->state->requestPost['password']) && $ChangePW) {
            $newPasswordHash = $this->accountUtil()->cryptPassword($this->input()->rawString('password'));
        }

        $old['username'] = $new['username'] = $UserData['username'];
        if ($this->has('name')) {
            $values['username'] = $new['username'] = $this->input()->string('name', '', UTF8_SUPPORT);
        }

        $old['authattack'] = $new['authattack'] = $UserData['authattack'];
        if ($this->has('authattack') || $this->has('name')) {
            $values['authattack'] = $new['authattack'] = ($UserData['authlevel'] != AuthLevel::User->value && $this->input()->string('authattack') == 'on' ? $UserData['authlevel'] : 0);
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

        return new Response($LNG->format('qe_edit_player_sucess', $UserData['username'], $id));
    }
}
