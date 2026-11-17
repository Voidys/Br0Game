<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\AccountLinks;
use App\Game\Core\DeviceLinks;
use App\Tests\Support\GameTestCase;

/**
 * Device-ID account link (F-15): the hashed device id is recorded per player, accounts sharing a device count as
 * linked and old rows are purged.
 */
final class DeviceLinkTest extends GameTestCase
{
    private function devices(): DeviceLinks
    {
        return new DeviceLinks($this->db, 'test-secret');
    }

    public function testRecordStoresHashNotRawIdAndUpdatesLastSeen(): void
    {
        [$a] = $this->createPlayer();
        $id = str_repeat('ab', 32);
        $this->devices()->record($a['id'], $id, 1000);
        $this->devices()->record($a['id'], $id, 2000);

        $rows = $this->db->select('SELECT * FROM %%USER_DEVICES%% WHERE `user_id` = :u;', [':u' => $a['id']]);
        $this->assertCount(1, $rows);
        $this->assertSame(hash('sha256', $id . 'test-secret'), $rows[0]['device_hash']);
        $this->assertNotSame($id, $rows[0]['device_hash']);
        $this->assertSame(1000, (int) $rows[0]['first_seen']);
        $this->assertSame(2000, (int) $rows[0]['last_seen']);
    }

    public function testSharedDeviceLinksAccounts(): void
    {
        [$a] = $this->createPlayer(['user_lastip' => '10.1.1.1']);
        [$b] = $this->createPlayer(['user_lastip' => '10.2.2.2']);
        [$c] = $this->createPlayer(['user_lastip' => '10.3.3.3']);
        $links = new AccountLinks($this->db);
        $this->devices()->record($a['id'], str_repeat('01', 32), 1000);
        $this->devices()->record($b['id'], str_repeat('02', 32), 1000);
        $this->assertFalse($links->linked($a['id'], $b['id'], true));
        $this->assertFalse($links->sharedDevice($a['id'], $b['id']));

        $this->devices()->record($c['id'], str_repeat('01', 32), 1000);
        $this->assertTrue($links->sharedDevice($a['id'], $c['id']));
        $this->assertTrue($links->linked($a['id'], $c['id'], false));
        $this->assertTrue($links->linked($c['id'], $a['id'], true));
        $this->assertFalse($links->sharedDevice($a['id'], $a['id']));
    }

    public function testPurgeDeletesOldRows(): void
    {
        [$a] = $this->createPlayer();
        $now = 100000000;
        $this->devices()->record($a['id'], str_repeat('03', 32), $now - DeviceLinks::RETENTION - 10);
        $this->devices()->record($a['id'], str_repeat('04', 32), $now - 10);
        $this->devices()->purge($now);

        $this->assertSame(1, (int) $this->db->selectSingle('SELECT count(*) as anz FROM %%USER_DEVICES%% WHERE `user_id` = :u;', [':u' => $a['id']], 'anz'));
    }
}
