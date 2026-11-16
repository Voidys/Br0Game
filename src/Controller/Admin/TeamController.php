<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;

/**
 * Team members shown on the public team page (./?page=team), edited in one form.
 */
#[\App\Routing\GamePage('admin.php', 'team')]
#[\App\Routing\GamePage('admin.php', 'team', uniPrefix: true)]
class TeamController extends AbstractAdminController
{
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $message = '';

        if ($this->http()->_GP('action', '', true) === 'save') {
            $this->saveMembers();
            $message = $LNG['team_saved'];
        }

        $this->assign([
            'members' => $this->db->select('SELECT id, name, position, description, contact FROM %%TEAM_MEMBERS%% ORDER BY sort ASC, id ASC;'),
            'message' => $message,
        ]);
        return $this->render('admin/TeamPage.html.twig');
    }

    /** The submitted rows in submitted order replace the stored ones (rows without a name are dropped). */
    private function saveMembers(): void
    {
        $post = $this->state->requestPost;
        $names = (array) ($post['name'] ?? []);
        $existing = array_map('intval', array_column($this->db->select('SELECT id FROM %%TEAM_MEMBERS%%;'), 'id'));
        $kept = [];
        $sort = 0;

        $this->db->startTransaction();
        foreach ($names as $i => $name) {
            $name = mb_substr(trim((string) $name), 0, 64);
            if ($name === '') {
                continue;
            }
            $values = [
                ':name' => $name,
                ':position' => mb_substr(trim((string) ($post['position'][$i] ?? '')), 0, 128),
                ':description' => trim((string) ($post['description'][$i] ?? '')),
                ':contact' => mb_substr(trim((string) ($post['contact'][$i] ?? '')), 0, 128),
                ':sort' => ++$sort,
            ];
            $id = (int) ($post['id'][$i] ?? 0);
            if ($id > 0 && in_array($id, $existing, true)) {
                $this->db->update('UPDATE %%TEAM_MEMBERS%% SET name = :name, position = :position, description = :description, contact = :contact, sort = :sort WHERE id = :id;', $values + [':id' => $id]);
                $kept[] = $id;
            } else {
                $this->db->insert('INSERT INTO %%TEAM_MEMBERS%% (name, position, description, contact, sort, created_at) VALUES (:name, :position, :description, :contact, :sort, :created);', $values + [':created' => $this->state->runtime->timestamp]);
            }
        }
        foreach (array_diff($existing, $kept) as $id) {
            $this->db->delete('DELETE FROM %%TEAM_MEMBERS%% WHERE id = :id;', [':id' => $id]);
        }
        $this->db->commit();
    }
}
