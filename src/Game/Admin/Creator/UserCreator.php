<?php

namespace App\Game\Admin\Creator;

use Symfony\Component\HttpFoundation\Response;

/** Creator tab "user": creates a player (with home planet) for an existing account. */
class UserCreator extends CreatorTab
{
    /** Shows the creation form, or creates the object from the posted form and shows the result. */
    public function handle(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $LNG->includeData(['PUBLIC']);
        if ($this->state->requestPost) {
            $UserName = $this->http()->_GP('name', '', UTF8_SUPPORT);
            $UserAuth = $this->http()->_GP('authlevel', 0);
            $Galaxy = $this->http()->_GP('galaxy', 0);
            $System = $this->http()->_GP('system', 0);
            $Planet = $this->http()->_GP('planet', 0);
            $Language = $this->http()->_GP('lang', '');
            $accountID = $this->http()->_GP('accountID', 0);

            $db = $this->db;
            $sql = "SELECT COUNT(*) as count FROM %%USERS%% WHERE universe = :universe AND username = :username;";
            $ExistsUser = $db->selectSingle($sql, [':universe' => $this->universe()->getEmulated(), ':username' => $UserName], 'count');

            $sql = "SELECT COUNT(*) as count FROM %%USERS%% u INNER JOIN %%ACCOUNTS_TO_USERS%% atu on atu.user_id = u.id WHERE u.universe = :universe AND atu.account_id = :accountId;";
            $AccountHasUser = $db->selectSingle($sql, [':universe' => $this->universe()->getEmulated(), ':accountId' => $accountID], 'count');

            $errors = "";

            $config = $this->configs->get($this->universe()->getEmulated());

            if (empty($accountID)) {
                $errors .= $LNG['empty_account_field'];
                $errors .= "<br>";
            }

            if (empty($UserName)) {
                $errors .= $LNG['registerErrorUsernameEmpty'];
                $errors .= "<br>";
            }

            if (!$this->playerUtil()->isNameValid($UserName)) {
                $errors .= $LNG['registerErrorUsernameChar'];
                $errors .= "<br>";
            }

            if ($ExistsUser != 0) {
                $errors .= $LNG['registerErrorUsernameExist'];
                $errors .= "<br>";
            }

            if ($AccountHasUser != 0) {
                $errors .= $LNG['account_already_has_user'];
                $errors .= "<br>";
            }

            if (!$this->playerUtil()->isPositionFree($this->universe()->getEmulated(), $Galaxy, $System, $Planet)) {
                $errors .= $LNG['po_complete_all'];
                $errors .= "<br>";
            }

            if ($Galaxy > $config->max_galaxy || $System > $config->max_system || $Planet > $config->max_planets) {
                $errors .= $LNG['po_complete_all2'];
                $errors .= "<br>";
            }

            if (!empty($errors)) {
                $this->responder->message(new \Twig\Markup($errors, 'UTF-8'), '?page=creator&mode=user', 10, true);
            }

            $Language = array_key_exists($Language, $LNG->getAllowedLangs(false)) ? $Language : $config->lang;

            $player = $this->playerUtil()->createPlayer(
                $accountID,
                $this->universe()->getEmulated(),
                $UserName,
                $Language,
                $Galaxy,
                $System,
                $Planet,
                $LNG['fcm_planet'],
                $UserAuth,
            );

            $this->responder->message(sprintf($LNG['new_user_success'], $player[0], $player[1]), '?page=creator&mode=user', 5, true);
        }

        $AUTH = [];
        $AUTH[AUTH_USR] = $LNG['user_level_' . AUTH_USR];

        if ($USER['authlevel'] >= AUTH_OPS) {
            $AUTH[AUTH_OPS] = $LNG['user_level_' . AUTH_OPS];
        }

        if ($USER['authlevel'] >= AUTH_MOD) {
            $AUTH[AUTH_MOD] = $LNG['user_level_' . AUTH_MOD];
        }

        if ($USER['authlevel'] >= AUTH_ADM) {
            $AUTH[AUTH_ADM] = $LNG['user_level_' . AUTH_ADM];
        }

        $this->responder->assign([
            'admin_auth'          => $USER['authlevel'],
            'accountID'           => $LNG['accountID'],
            'new_add_user'        => $LNG['new_add_user'],
            'new_creator_refresh' => $LNG['new_creator_refresh'],
            'new_creator_go_back' => $LNG['new_creator_go_back'],
            'universe'            => $LNG['mu_universe'],
            'user_reg'            => $LNG['user_reg'],
            'new_coord'           => $LNG['new_coord'],
            'new_range'           => $LNG['new_range'],
            'lang_reg'            => $LNG['lang_reg'],
            'new_title'           => $LNG['new_title'],
            'Selector'            => ['auth' => $AUTH, 'lang' => $LNG->getAllowedLangs(false)],
        ]);
        return $this->responder->render('admin/CreatePageUser.html.twig');
    }
}
