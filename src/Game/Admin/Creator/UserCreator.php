<?php

namespace App\Game\Admin\Creator;

use App\Game\Enum\AuthLevel;
use Symfony\Component\HttpFoundation\Response;

/** Creator tab "user": creates a player (with home planet) for an existing account. */
class UserCreator extends CreatorTab
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
     * Validates the posted form (name, account, position) and creates the player with its home planet; always ends
     * the request with a message (errors, or the new user and planet id).
     */
    private function createFromPost(): void
    {
        $LNG = $this->state->LNG;
        $UserName = $this->input()->string('name', '', UTF8_SUPPORT);
        $UserAuth = $this->input()->int('authlevel');
        // nobody can hand out a level above their own (the form only offers the allowed ones)
        $UserAuth = max(AuthLevel::User->value, min((int) $UserAuth, (int) $this->state->USER['authlevel']));
        $Galaxy = $this->input()->int('galaxy');
        $System = $this->input()->int('system');
        $Planet = $this->input()->int('planet');
        $Language = $this->input()->string('lang');
        $accountID = $this->input()->int('accountID');

        $config = $this->configs->get($this->universe()->getEmulated());
        $errors = $this->validationErrors($UserName, $accountID, $Galaxy, $System, $Planet, $config);

        if (!empty($errors)) {
            $this->responder->message(new \Twig\Markup($errors, 'UTF-8'), 'admin/creator/user', 10, true);
        }

        $Language = array_key_exists($Language, $LNG->getAllowedLangs(false)) ? $Language : $config->lang;

        $player = $this->playerRegistration()->createPlayer(
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

        $this->responder->message($LNG->format('new_user_success', $player[0], $player[1]), 'admin/creator/user', 5, true);
    }

    /**
     * Checks the form values: account given, name not empty/valid/taken in the universe, account without a player in
     * the universe, position free and inside the universe size.
     *
     * @param \App\Game\Core\Config $config config of the emulated universe
     * @return string the translated messages, each followed by "<br>" (empty when everything is valid)
     */
    private function validationErrors(string $UserName, $accountID, $Galaxy, $System, $Planet, $config): string
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $sql = "SELECT COUNT(*) as count FROM %%USERS%% WHERE universe = :universe AND username = :username;";
        $ExistsUser = $db->selectSingle($sql, [':universe' => $this->universe()->getEmulated(), ':username' => $UserName], 'count');

        $sql = "SELECT COUNT(*) as count FROM %%USERS%% u INNER JOIN %%ACCOUNTS_TO_USERS%% atu on atu.user_id = u.id WHERE u.universe = :universe AND atu.account_id = :accountId;";
        $AccountHasUser = $db->selectSingle($sql, [':universe' => $this->universe()->getEmulated(), ':accountId' => $accountID], 'count');

        $errors = "";

        if (empty($accountID)) {
            $errors .= $LNG['empty_account_field'];
            $errors .= "<br>";
        }

        if (empty($UserName)) {
            $errors .= $LNG['registerErrorUsernameEmpty'];
            $errors .= "<br>";
        }

        if (!$this->playerRegistration()->isNameValid($UserName)) {
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

        if (!$this->planetPositions()->isPositionFree($this->universe()->getEmulated(), $Galaxy, $System, $Planet)) {
            $errors .= $LNG['po_complete_all'];
            $errors .= "<br>";
        }

        if ($Galaxy > $config->max_galaxy || $System > $config->max_system || $Planet > $config->max_planets) {
            $errors .= $LNG['po_complete_all2'];
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
