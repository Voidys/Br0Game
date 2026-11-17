<?php

namespace App\Controller\Shared;

use Symfony\Component\HttpFoundation\Response;

/** Team page: the members of the admin team section, rendered in the layout of the area. */
trait TeamPage
{
    /** Lists the members from TEAM_MEMBERS (edited in the admin team page). */
    public function show(): Response
    {
        return $this->render(static::templateArea() . '/page.team.default.html.twig', [
            'members' => $this->db->select('SELECT name, position, description, contact FROM %%TEAM_MEMBERS%% ORDER BY sort ASC, id ASC;'),
        ]);
    }
}
