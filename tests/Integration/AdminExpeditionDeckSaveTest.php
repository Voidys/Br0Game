<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\Admin\ExpeditionDeckController;
use App\Game\Core\ExpeditionDeckHandler;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\AuthLevel;

/** Saving the expedition deck in the admin stores it for the universe. */
final class AdminExpeditionDeckSaveTest extends GameTestCase
{
    public function testSavingTheDeckStoresItForTheUniverse(): void
    {
        [$admin] = $this->createPlayer(['authlevel' => AuthLevel::Admin->value]);
        $deck = ExpeditionDeckHandler::$defaultDeckConfig;
        $this->state->USER = $admin;
        $this->state->universeEmulated = 1;
        $post = ['deck_config_json' => json_encode($deck), 'uniID' => 1];
        $this->state->requestPost = $post;
        $this->state->requestInput = $post;
        $this->state->requestQuery = [];

        $controller = (new ExpeditionDeckController())->withGameServices($this->db, $this->configs, $this->state);
        $controller->setRouter(self::container()->get('router'));
        try {
            $controller->update();
        } catch (\Throwable) {
            // rendering the page needs a browser session, which a test does not have: the outcome is checked in the database
        }

        $stored = $this->db->selectSingle('SELECT deck_data FROM %%EXPEDITION_DECK_CONFIG%% WHERE universe = 1;');
        $this->assertSame($deck, json_decode($stored['deck_data'], true));
    }
}
