<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Team members shown on the public team page (/team), edited in one form.
 */
class TeamController extends AbstractAdminController
{
    /** Lists the team members of the public team page in one editable form. */
    #[GameRoute('admin', 'admin/team', 'team')]
    public function show(): Response
    {
        return $this->renderMembers('');
    }

    /** Stores the submitted rows (saveMembers) and lists the members again. */
    #[GameRoute('admin', 'admin/team', 'team', 'save', ['POST'])]
    public function save(): Response
    {
        $this->saveMembers();

        return $this->renderMembers($this->state->LNG['team_saved']);
    }

    private function renderMembers(string $message): Response
    {
        return $this->render('admin/TeamPage.html.twig', [
            'members' => $this->db->select('SELECT id, name, position, description, contact FROM %%TEAM_MEMBERS%% ORDER BY sort ASC, id ASC;'),
            'message' => $message,
        ]);
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
