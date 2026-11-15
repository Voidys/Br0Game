<?php

namespace App\Controller\Admin;

use Exception;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;

/** Lists the admin comments stored on players of the universe. */
#[\App\Routing\GamePage('admin.php', 'comments')]
#[\App\Routing\GamePage('admin.php', 'comments', uniPrefix: true)]
class CommentsController extends AbstractAdminController
{
    protected function construct(): void
    {
        if (!$this->viewHelpers()->allowedTo('ShowMultiIPPage')) {
            throw new Exception($this->state->LNG['adm_permission_error']);
        }

        parent::construct();
    }

    public function show(): Response
    {
        $LNG = & $this->state->LNG;
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

        $this->assign([
            'comments'     => $comments,
        ]);
        return $this->render('admin/Comments.html.twig');
    }
}
