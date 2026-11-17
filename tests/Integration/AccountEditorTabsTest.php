<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Admin\AccountEditor\AlliancesTab;
use App\Game\Admin\AccountEditor\PersonalTab;
use App\Game\Admin\ClosureAdminResponder;
use App\Tests\Support\GameTestCase;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Enum\AuthLevel;

/**
 * Saves of the admin account editor tabs that were broken (alliance deletion, removing a member, personal data without
 * changes). The tabs end with a message page, which the test responder turns into an exception carrying the message.
 */
final class AccountEditorTabsTest extends GameTestCase
{
    /** @param array<string, mixed> $input */
    private function save(string $tab, array $input): string
    {
        $this->state->universeEmulated = 1;
        $this->state->requestPost = ['save' => 1];
        $this->state->requestInput = $input;
        $responder = new ClosureAdminResponder(
            static function ($message): never {
                throw new \RuntimeException((string) $message);
            },
            static function (array $variables): void {},
            static fn(string $template): Response => new Response($template),
        );
        try {
            (new $tab($this->db, $this->configs, $this->state, $responder))->handle();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return '';
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: array<string, mixed>} alliance id, admin, member */
    private function allianceWithMember(): array
    {
        [$admin] = $this->createPlayer(['authlevel' => AuthLevel::Admin->value]);
        [$member] = $this->createPlayer();
        $this->state->USER = $admin;
        $this->db->insert("INSERT INTO %%ALLIANCE%% (ally_name, ally_tag, ally_owner, ally_register_time, ally_universe, ally_events, ally_members) VALUES ('EdFix', 'EDF', :o, :t, 1, '', 1);", [':o' => $member['id'], ':t' => time()]);
        $allyId = $this->db->lastInsertId();
        $this->db->update('UPDATE %%USERS%% SET ally_id = :a, ally_rank_id = 1, ally_register_time = 5 WHERE id = :u;', [':a' => $allyId, ':u' => $member['id']]);

        return [$allyId, $admin, $member];
    }

    public function testDeletingAnAllianceReleasesMembersAndRemovesRows(): void
    {
        [$allyId, , $member] = $this->allianceWithMember();
        $this->db->insert("INSERT INTO %%STATPOINTS%% (id_owner, id_ally, stat_type, universe) VALUES (:a, 0, 2, 1) ON DUPLICATE KEY UPDATE stat_type = 2;", [':a' => $allyId]);

        $message = $this->save(AlliancesTab::class, ['id' => $allyId, 'delete' => 'on']);

        $this->assertNotSame('', $message);
        $this->assertSame(0, (int) $this->loadUser((int) $member['id'])['ally_id']);
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ALLIANCE%% WHERE id = :a;', [':a' => $allyId], 'c'));
    }

    public function testRemovingAMemberUsesTheUserId(): void
    {
        [$allyId, , $member] = $this->allianceWithMember();

        $this->save(AlliancesTab::class, ['id' => $allyId, 'delete_u' => $member['id']]);

        $this->assertSame(0, (int) $this->loadUser((int) $member['id'])['ally_id']);
        $this->assertSame(0, (int) $this->db->selectSingle('SELECT ally_members AS c FROM %%ALLIANCE%% WHERE id = :a;', [':a' => $allyId], 'c'));
    }

    public function testPersonalSaveWithoutChangesIsANoOp(): void
    {
        [$user] = $this->createPlayer();
        $this->state->USER = $user;

        $message = $this->save(PersonalTab::class, ['id' => $user['id']]);

        $this->assertNotSame('', $message, 'the success message page is shown instead of an SQL error');
        $this->assertSame($user['username'], $this->loadUser((int) $user['id'])['username']);
    }
}
