<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\QuickEditorController;
use App\Game\Core\EndOfRequest;
use App\Tests\Support\GameTestCase;

/**
 * Admin quick editor saves: only fields present in the request change (a partial POST must not zero anything),
 * values are bound parameters, passwords are hashed, unknown field names are ignored.
 */
final class QuickEditorSaveTest extends GameTestCase
{
    /** @param array<string, mixed> $input */
    private function save(string $edit, int $id, array $input, array $admin): void
    {
        $this->state->USER = $admin;
        $this->state->universeEmulated = 1;
        $this->state->requestPost = $input;
        $this->state->requestInput = ['edit' => $edit, 'id' => $id, 'action' => 'send'] + $input;
        $controller = (new QuickEditorController())->withGameServices($this->db, $this->configs, $this->state);
        try {
            $controller->show();
            $this->fail('save should end the request');
        } catch (EndOfRequest) {
        }
    }

    private function loadPlanetRow(int $id): array
    {
        return $this->db->selectSingle('SELECT * FROM %%PLANETS%% WHERE id = :id;', [':id' => $id]);
    }

    private function passwordOf(int $userId): string
    {
        return (string) $this->db->selectSingle('SELECT a.password AS p FROM %%ACCOUNTS%% a INNER JOIN %%ACCOUNTS_TO_USERS%% au ON au.account_id = a.id WHERE au.user_id = :u;', [':u' => $userId], 'p');
    }

    public function testPartialPlanetPostLeavesOtherFieldsUnchanged(): void
    {
        [$admin] = $this->createPlayer(['authlevel' => AUTH_ADM]);
        [, $planet] = $this->createPlayer([], ['metal_mine' => 7, 'light_hunter' => 50, 'metal' => 1000, 'crystal' => 500, 'name' => 'Keep']);

        $this->save('planet', (int) $planet['id'], ['crystal' => '1234.4'], $admin);

        $after = $this->loadPlanetRow((int) $planet['id']);
        $this->assertSame(1234, (int) $after['crystal']);
        $this->assertSame(1000, (int) $after['metal']);
        $this->assertSame(7, (int) $after['metal_mine']);
        $this->assertSame(50, (int) $after['light_hunter']);
        $this->assertSame('Keep', $after['name']);
        $this->assertSame((int) $planet['field_current'], (int) $after['field_current']);
        $this->assertSame((int) $planet['field_max'], (int) $after['field_max']);
    }

    public function testFullPlanetPostUpdatesAndClamps(): void
    {
        [$admin] = $this->createPlayer(['authlevel' => AUTH_ADM]);
        [, $planet] = $this->createPlayer([], ['metal_mine' => 7]);

        $this->save('planet', (int) $planet['id'], ['metal_mine' => '9', 'solar_plant' => '999', 'light_hunter' => '-5', 'metal' => '-3', 'field_max' => '200', 'name' => 'New'], $admin);

        $after = $this->loadPlanetRow((int) $planet['id']);
        $this->assertSame(9, (int) $after['metal_mine']);
        $this->assertSame(255, (int) $after['solar_plant']);
        $this->assertSame(0, (int) $after['light_hunter']);
        $this->assertSame(0, (int) $after['metal']);
        $this->assertSame(200, (int) $after['field_max']);
        $this->assertSame('New', $after['name']);
        $this->assertSame((int) $planet['field_current'] + 2 + 255, (int) $after['field_current']);
    }

    public function testUnknownFieldNamesAreIgnored(): void
    {
        [$admin] = $this->createPlayer(['authlevel' => AUTH_ADM]);
        [, $planet] = $this->createPlayer([], ['metal' => 10]);

        $this->save('planet', (int) $planet['id'], ['metal` = 999999, `crystal' => '5', 'id_owner' => '1', 'metal' => '11'], $admin);

        $after = $this->loadPlanetRow((int) $planet['id']);
        $this->assertSame(11, (int) $after['metal']);
        $this->assertSame((int) $planet['id_owner'], (int) $after['id_owner']);
    }

    public function testPartialPlayerPostKeepsTechsAndPasswordChangeIsHashed(): void
    {
        [$admin] = $this->createPlayer(['authlevel' => AUTH_ADM]);
        [$user] = $this->createPlayer(['computer_tech' => 4, 'spy_tech' => 6]);

        $this->save('player', (int) $user['id'], ['spy_tech' => '300'], $admin);
        $after = $this->loadUser((int) $user['id']);
        $this->assertSame(255, (int) $after['spy_tech']);
        $this->assertSame(4, (int) $after['computer_tech']);
        $this->assertSame($user['username'], $after['username']);
        $before = $this->passwordOf((int) $user['id']);

        $this->save('player', (int) $user['id'], ['password' => 'n3w-secret'], $admin);
        $after = $this->loadUser((int) $user['id']);
        $hash = $this->passwordOf((int) $user['id']);
        $this->assertNotSame($before, $hash);
        $this->assertNotSame('n3w-secret', $hash);
        $this->assertTrue(password_verify('n3w-secret', $hash));
        $this->assertSame(255, (int) $after['spy_tech']);
    }

    public function testFullPlayerPostUpdatesNameAndTechs(): void
    {
        [$admin] = $this->createPlayer(['authlevel' => AUTH_ADM]);
        [$user] = $this->createPlayer(['computer_tech' => 4]);

        $this->save('player', (int) $user['id'], ['name' => 'Renamed', 'computer_tech' => '8', 'spy_tech' => '1'], $admin);

        $after = $this->loadUser((int) $user['id']);
        $this->assertSame('Renamed', $after['username']);
        $this->assertSame(8, (int) $after['computer_tech']);
        $this->assertSame(1, (int) $after['spy_tech']);
    }
}
