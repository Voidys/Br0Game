<?php

namespace App\Game\Admin\Creator;

use App\Game\Enum\AuthLevel;
use Symfony\Component\HttpFoundation\Response;

/** Creator tab "account": creates a game account (login, mail, password, language). */
class AccountCreator extends CreatorTab
{
    /** Shows the creation form, or creates the object from the posted form and shows the result. */
    public function handle(): Response
    {
        $LNG = $this->state->LNG;
        $LNG->includeData(['PUBLIC']);
        if ($this->state->requestPost) {
            $this->createFromPost();
        }

        return $this->showForm();
    }

    /**
     * Validates the posted form and creates the account; always ends the request with a message (errors, or the new
     * account id).
     */
    private function createFromPost(): void
    {
        $LNG = $this->state->LNG;
        $accountName = $this->input()->string('name', '', UTF8_SUPPORT);
        $accountPass = $this->input()->rawString('password');
        $accountPass2 = $this->input()->rawString('password2');
        $accountMail = $this->input()->string('email');
        $accountMail2 = $this->input()->string('email2');
        $authlevel = $this->input()->int('authlevel');
        // nobody can hand out a level above their own (the form only offers the allowed ones)
        $authlevel = max(AuthLevel::User->value, min((int) $authlevel, (int) $this->state->USER['authlevel']));
        $Language = $this->input()->string('lang');

        $config = $this->configs->get($this->universe()->getEmulated());
        $errors = $this->validationErrors($accountName, $accountPass, $accountPass2, $accountMail, $accountMail2);

        if (!empty($errors)) {
            $this->responder->message(new \Twig\Markup($errors, 'UTF-8'), 'admin/creator/account', 10, true);
        }

        $Language = array_key_exists($Language, $LNG->getAllowedLangs(false)) ? $Language : $config->lang;
        $id = $this->accountUtil()->createAccount(
            $accountName,
            $this->accountUtil()->cryptPassword($accountPass),
            $accountMail,
            $Language,
            $authlevel,
        );

        $this->responder->message($LNG->format('new_account_success', $id), 'admin/creator/account', 5, true);
    }

    /**
     * Checks the form values: mail unused, name not empty/valid/taken, password at least 6 characters and repeated
     * equally, mail repeated equally.
     *
     * @return string the translated messages (empty when everything is valid)
     */
    private function validationErrors(string $accountName, string $accountPass, string $accountPass2, string $accountMail, string $accountMail2): string
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $sql = "SELECT COUNT(*) as count FROM %%ACCOUNTS%% WHERE accountname = :accountname;";
        $ExistsUser = $db->selectSingle($sql, [':accountname' => $accountName], 'count');

        $sql = "SELECT COUNT(*) as count FROM %%ACCOUNTS%% WHERE email = :email OR email_2 = :email;";
        $ExistsMails = $db->selectSingle($sql, [':email' => $accountMail], 'count');

        $errors = "";

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

        return $errors;
    }

    /** Renders the creation form with the auth level choices the acting admin may hand out. */
    private function showForm(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

        $AUTH = [];
        $AUTH[AuthLevel::User->value] = $LNG['user_level_' . AuthLevel::User->value];

        if ($USER['authlevel'] >= AuthLevel::Operator->value) {
            $AUTH[AuthLevel::Operator->value] = $LNG['user_level_' . AuthLevel::Operator->value];
        }

        if ($USER['authlevel'] >= AuthLevel::Moderator->value) {
            $AUTH[AuthLevel::Moderator->value] = $LNG['user_level_' . AuthLevel::Moderator->value];
        }

        if ($USER['authlevel'] >= AuthLevel::Admin->value) {
            $AUTH[AuthLevel::Admin->value] = $LNG['user_level_' . AuthLevel::Admin->value];
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
