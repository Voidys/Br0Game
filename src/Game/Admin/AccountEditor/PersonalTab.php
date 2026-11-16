<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Core\Log;
use Symfony\Component\HttpFoundation\Response;

/** Account editor tab "personal": edits the personal data of a player (name, mail, password, vacation mode, ...). */
class PersonalTab extends AccountEditorTab
{
    /** Shows the form, or applies the posted changes (logged) and redirects with a message. */
    public function handle(): Response
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        if ($this->state->requestPost) {
            $id = $this->http()->_GP('id', 0);
            $username = $this->http()->_GP('username', '', UTF8_SUPPORT);
            $vacation = $this->http()->_GP('vacation', '');

            $sql = "SELECT `username`,`urlaubs_modus`,`urlaubs_until`,`universe`
            FROM %%USERS%% WHERE `id` = :userID;";
            $beforePersonal = $db->selectSingle($sql, [
                ':userID' => $id,
            ]);
            $afterPersonal = $beforePersonal;
            $QryUpdate = [];
            $params = [];

            if (!empty($username) && $id != ROOT_USER) {
                $QryUpdate[] = "`username` = :username";
                $params[':username'] = $username;
                $afterPersonal['username'] = $username;
            }

            if ($vacation == 'yes') {
                $QryUpdate[] = "`urlaubs_modus` = :urlaubs_modus";
                $params[':urlaubs_modus'] = 1;
                $afterPersonal['urlaubs_modus'] = 1;
                $d = $this->http()->_GP('d', 0);
                $h = $this->http()->_GP('h', 0);
                $m = $this->http()->_GP('m', 0);
                $s = $this->http()->_GP('s', 0);
                $TimeAns = $this->state->runtime->timestamp + $d * 86400 + $h * 3600 + $m * 60 + $s;
                $QryUpdate[] = "`urlaubs_until` = :urlaubs_until";
                $params[':urlaubs_until'] = $TimeAns;
                $afterPersonal['urlaubs_until'] = $TimeAns;
            }

            if (!empty($QryUpdate)) {
                $sql = "UPDATE %%USERS%% SET ";
                $sql .= implode(", ", $QryUpdate);
                $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                $db->update($sql, array_merge($params, [
                    ':id'       => $id,
                    ':universe' => $this->universe()->getEmulated(),
                ]));

                $LOG = new Log($this->db, $this->configs, $this->state, 1);
                $LOG->target = $id;
                $LOG->universe = $this->universe()->getEmulated();
                $LOG->old = $beforePersonal;
                $LOG->new = $afterPersonal;
                $LOG->save();
            }
            $this->responder->message($LNG['ad_personal_succes'], '?page=accountEditor&edit=personal');
        }

        $this->responder->assign([
            'Selector' => ['' => $LNG['select_option'], 'yes' => $LNG['one_is_yes_1'], 'no' => $LNG['one_is_yes_0']],
        ]);

        return $this->responder->render('admin/AccountEditorPagePersonal.html.twig');
    }
}
