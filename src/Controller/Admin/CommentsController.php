<?php

namespace App\Controller\Admin;

use App\Game\Core\Universe;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Lists the admin comments stored on players of the universe. */
class CommentsController extends AbstractAdminController
{
    /**
     * Skips the per-page permission check: every admin may read the comments.
     */
    protected function construct(): void
    {
        if (!$this->viewHelpers()->allowedTo('ShowMultiIPPage')) {
            $this->deny();
        }

        parent::construct();
    }

    /**
     * Lists the admin comments (USERS_COMMENTS) stored on players of the universe with player and alliance names.
     */
    #[GameRoute('admin', 'admin/comments', 'comments')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $db = $this->db;

        $sql = 'SELECT uc.user_id, u.username, a.ally_name, uc.comment, uc.info, uc.created_at 
                FROM %%USERS_COMMENTS%% AS uc 
                LEFT JOIN %%USERS%% AS u ON u.id = uc.user_id 
                LEFT JOIN %%ALLIANCE%% AS a ON a.id = u.ally_id 
                WHERE u.universe = :uni ORDER BY created_at DESC;';

        $comments = $db->select($sql, [
            ':uni' => $this->universe()->getEmulated(),
        ]);

        foreach ($comments as &$Data) {
            $Data['created_at'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $Data['created_at']);
        }

        return $this->render('admin/Comments.html.twig', [
            'comments'     => $comments,
        ]);
    }
}
