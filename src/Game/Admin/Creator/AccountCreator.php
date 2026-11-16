<?php

namespace App\Game\Admin\Creator;

use Symfony\Component\HttpFoundation\Response;

/** Creator tab "account": creates a game account (login, mail, password, language). */
class AccountCreator extends CreatorTab
{
    /** Shows the creation form, or creates the object from the posted form and shows the result. */
    public function handle(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $LNG->includeData(['PUBLIC']);
        if ($this->state->requestPost) {
            $accountName = $this->http()->_GP('name', '', UTF8_SUPPORT);
            $accountPass = $this->http()->_GP('password', '');
            $accountPass2 = $this->http()->_GP('password2', '');
            $accountMail = $this->http()->_GP('email', '');
            $accountMail2 = $this->http()->_GP('email2', '');
            $authlevel = $this->http()->_GP('authlevel', 0);
            $Language = $this->http()->_GP('lang', '');
            $accountID = $this->http()->_GP('accountID', 0);

            $db = $this->db;
            $sql = "SELECT COUNT(*) as count FROM %%ACCOUNTS%% WHERE accountname = :accountname;";
            $ExistsUser = $db->selectSingle($sql, [':accountname' => $accountName], 'count');

            $sql = "SELECT COUNT(*) as count FROM %%ACCOUNTS%% WHERE email = :email OR email_2 = :email;";
            $ExistsMails = $db->selectSingle($sql, [':email' => $accountMail], 'count');

            $errors = "";

            $config = $this->configs->get($this->universe()->getEmulated());

            if ($ExistsMails != 0) {
                $errors .= $LNG['mail_already_exists'];
            }
            if (empty($accountName)) {
                $errors .= $LNG['registerErrorUsernameEmpty'];
                $errors .= "<br>";
            }

            if (strlen($accountPass) < 6) {
                $errors .= $LNG->format('registerErrorPasswordLength', 6);
                $errors .= "<br>";
            }

            if ($accountPass != $accountPass2) {
                $errors .= $LNG['registerErrorPasswordSame'];
                $errors .= "<br>";
            }

            if ($accountMail != $accountMail2) {
                $errors .= $LNG['registerErrorMailSame'];
                $errors .= "<br>";
            }

            if (!$this->accountUtil()->isNameValid($accountName)) {
                $errors .= $LNG['registerErrorUsernameChar'];
                $errors .= "<br>";
            }

            if ($ExistsUser != 0) {
                $errors .= $LNG['registerErrorUsernameExist'];
                $errors .= "<br>";
            }

            if (!empty($errors)) {
                $this->responder->message(new \Twig\Markup($errors, 'UTF-8'), '?page=creator&mode=account', 10, true);
            }

            $Language = array_key_exists($Language, $LNG->getAllowedLangs(false)) ? $Language : $config->lang;
            $id = $this->accountUtil()->createAccount(
                $accountName,
                $this->accountUtil()->cryptPassword($accountPass),
                $accountMail,
                $Language,
                $authlevel,
            );

            $this->responder->message($LNG->format('new_account_success', $id), '?page=creator&mode=account', 5, true);
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
            'new_add_account'     => $LNG['new_add_account'],
            'new_creator_refresh' => $LNG['new_creator_refresh'],
            'new_creator_go_back' => $LNG['new_creator_go_back'],
            'universe'            => $LNG['mu_universe'],
            'user_reg'            => $LNG['user_reg'],
            'pass_reg'            => $LNG['pass_reg'],
            'pass2_reg'           => $LNG['pass2_reg'],
            'email_reg'           => $LNG['email_reg'],
            'email2_reg'          => $LNG['email2_reg'],
            'new_coord'           => $LNG['new_coord'],
            'new_range'           => $LNG['new_range'],
            'lang_reg'            => $LNG['lang_reg'],
            'new_title_acc'       => $LNG['new_title_acc'],
            'Selector'            => ['auth' => $AUTH, 'lang' => $LNG->getAllowedLangs(false)],
        ]);
        return $this->responder->render('admin/CreatePageAccount.html.twig');
    }
}
