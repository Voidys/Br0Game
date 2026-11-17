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
            $id = $this->input()->int('id');
            $username = $this->input()->string('username', '', UTF8_SUPPORT);
            $vacation = $this->input()->string('vacation');

            $sql = "SELECT `username`,`urlaubs_modus`,`urlaubs_until`,`universe`
            FROM %%USERS%% WHERE `id` = :userID;";
            $beforePersonal = $db->selectSingle($sql, [
                ':userID' => $id,
            ]);
            $afterPersonal = $beforePersonal;
            $QryUpdate = [];

            if (!empty($username) && $id != ROOT_USER) {
                $QryUpdate['username'] = $username;
                $afterPersonal['username'] = $username;
            }

            if ($vacation == 'yes') {
                $QryUpdate['urlaubs_modus'] = 1;
                $afterPersonal['urlaubs_modus'] = 1;
                $d = $this->input()->int('d');
                $h = $this->input()->int('h');
                $m = $this->input()->int('m');
                $s = $this->input()->int('s');
                $TimeAns = $this->state->runtime->timestamp + $d * 86400 + $h * 3600 + $m * 60 + $s;
                $QryUpdate['urlaubs_until'] = $TimeAns;
                $afterPersonal['urlaubs_until'] = $TimeAns;
            }

            if (!empty($QryUpdate)) {
                $db->updateRows('USERS', $QryUpdate, ['id' => $id, 'universe' => $this->universe()->getEmulated()]);

                $LOG = new Log($this->db, $this->configs, $this->state, 1);
                $LOG->target = $id;
                $LOG->universe = $this->universe()->getEmulated();
                $LOG->old = $beforePersonal;
                $LOG->new = $afterPersonal;
                $LOG->save();
            }
            $this->responder->message($LNG['ad_personal_succes'], 'admin/accounts/edit/personal');
        }

        $this->responder->assign([
            'Selector' => ['' => $LNG['select_option'], 'yes' => $LNG['one_is_yes_1'], 'no' => $LNG['one_is_yes_0']],
        ]);

        return $this->responder->render('admin/AccountEditorPagePersonal.html.twig');
    }
}
