<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use App\Game\Alliance\AllianceException;
use App\Game\Alliance\AllianceRights;
use App\Game\Alliance\AllianceServices;
use App\Game\Alliance\AllianceText;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alliance pages: overview, application, creation, member list, circular messages and the admin area (texts, ranks, members, diplomacy).
 *
 * HTTP adapter only: reads the request, checks the rank rights of the page and renders; the rules and queries live
 * in the services of App\Game\Alliance (see AllianceServices). The services end an action early by throwing an
 * AllianceException, which exitWith() turns into the redirect / message page / JSON answer.
 */
#[\App\Routing\GamePage('game.php', 'alliance')]
#[\App\Routing\GamePage('game.php', 'alliance', uniPrefix: true)]
class AllianceController extends AbstractGameController
{
    public static $requireModule = 'MODULE_ALLIANCE';

    private $allianceData;
    private array $rights = [];
    private $hasAlliance = false;
    private $hasApply = false;
    private ?AllianceServices $allianceServices = null;
    public $availableRanks = AllianceRights::AVAILABLE;

    public function initialize(): void
    {
        $USER = & $this->state->USER;
        parent::initialize();
        $this->rights = AllianceRights::all(false);
        $this->hasAlliance = $USER['ally_id'] != 0;
        $this->hasApply = $this->alliance()->repository()->hasOpenApplication($USER['id']);
        if ($this->hasAlliance && !$this->hasApply) {
            $this->setAllianceData($USER['ally_id']);
        }
    }

    private function alliance(): AllianceServices
    {
        return $this->allianceServices ??= new AllianceServices($this->db, $this->configs, $this->state);
    }

    /** Ends the request the way the thrown alliance exception asks for. */
    private function exitWith(AllianceException $exit): never
    {
        match ($exit->kind) {
            AllianceException::HOME => $this->redirectToHome(),
            AllianceException::REDIRECT => $this->redirectTo($exit->payload),
            AllianceException::MESSAGE => $this->printMessage($exit->payload, $exit->buttons),
            AllianceException::JSON => $this->sendJSON($exit->payload),
        };
    }

    private function setAllianceData($allianceId)
    {
        $USER = $this->state->USER;

        $this->allianceData = $this->alliance()->repository()->find($allianceId);

        if (!$this->allianceData) {
            $this->printMessage($this->state->LNG['al_not_exists']);
        }

        if ($USER['ally_id'] == $allianceId) {
            $this->rights = $this->alliance()->repository()->rightsOf($USER, $this->allianceData);

            $this->assign([
                'rights' => $this->rights,
                'AllianceOwner' => $this->allianceData['ally_owner'] == $USER['id'],
            ]);
        }
    }

    public function info(): Response
    {
        $this->setAllianceData($this->http()->_GP('id', 0));

        $this->assign($this->alliance()->pages()->info($this->allianceData, $this->hasAlliance, $this->hasApply));

        return $this->render('game/page.alliance.info.html.twig');
    }

    public function show(): Response
    {
        if ($this->hasAlliance) {
            return $this->homeAlliance();
        } elseif ($this->hasApply) {
            return $this->applyWaitScreen();
        } else {
            return $this->render('game/page.alliance.createSelection.html.twig');
        }
    }

    private function redirectToHome()
    {
        $this->redirectTo('game?page=alliance');
    }

    private function getAction()
    {
        return $this->http()->_GP('action', '');
    }

    private function applyWaitScreen(): Response
    {
        $allianceResult = $this->alliance()->applications()->pendingTarget();

        if (empty($allianceResult['ally_tag'])) {
            $allianceResult['ally_tag'] = 0;
        }

        $link = new \Twig\Markup(
            '<a href="?page=alliance&amp;mode=info&amp;id=' . (int) $allianceResult['id'] . '">['
            . \App\Twig\GameEscaping::escape($allianceResult['ally_tag']) . '] ' . \App\Twig\GameEscaping::escape($allianceResult['ally_name']) . '</a>',
            'UTF-8',
        );
        $this->assign([
            'request_text' => AllianceText::html($this->state->LNG, 'al_request_wait_message', $link),
        ]);

        return $this->render('game/page.alliance.applyWait.html.twig');
    }

    public function search(): Response
    {
        if ($this->hasApply) {
            $this->redirectToHome();
        }

        $searchText = $this->http()->_GP('searchtext', '', UTF8_SUPPORT);

        $this->assign([
            'searchText' => $searchText,
            'searchList' => $this->alliance()->applications()->search($searchText),
        ]);

        return $this->render('game/page.alliance.search.html.twig');
    }

    public function apply(): Response
    {
        $LNG = $this->state->LNG;
        if ($this->hasApply || $this->state->USER['ally_id'] != 0) {
            $this->redirectToHome();
        }

        $text = $this->http()->_GP('text', '', true);
        $allianceId = $this->http()->_GP('id', 0);

        try {
            $applications = $this->alliance()->applications();
            $allianceResult = $applications->applicationTarget($allianceId);

            if (!empty($text)) {
                $applications->submit($allianceId, $text);

                $this->printMessage(AllianceText::html($LNG, 'al_request_confirmation_message'), [[
                    'label' => $LNG['al_ok'],
                    'url' => '?page=overview',
                ]]);
            }
        } catch (AllianceException $exit) {
            $this->exitWith($exit);
        }

        $this->assign([
            'allyid' => $allianceId,
            'applytext' => $allianceResult['ally_request'],
            'al_write_request' => AllianceText::html($LNG, 'al_write_request', $allianceResult['ally_tag']),
        ]);

        return $this->render('game/page.alliance.apply.html.twig');
    }

    public function cancelApply()
    {
        $LNG = $this->state->LNG;
        if (!$this->hasApply) {
            $this->redirectToHome();
        }

        $allyTag = $this->alliance()->applications()->cancel();

        $this->printMessage(AllianceText::html($LNG, 'al_request_deleted', $allyTag), [[
            'label' => $LNG['sys_forward'],
            'url' => '?page=alliance',
        ]]);
    }

    public function create(): Response
    {
        if ($this->hasApply) {
            $this->redirectToHome();
        }

        try {
            $this->alliance()->membership()->assertMayFound();
        } catch (AllianceException $exit) {
            $this->exitWith($exit);
        }

        if ($this->getAction() == 'send') {
            return $this->createAlliance();
        }

        return $this->render('game/page.alliance.create.html.twig');
    }

    /** processes the founding form: confirmation message on success */
    private function createAlliance(): never
    {
        $LNG = $this->state->LNG;
        $allianceTag = $this->http()->_GP('atag', '', UTF8_SUPPORT);
        $allianceName = $this->http()->_GP('aname', '', UTF8_SUPPORT);

        try {
            $label = $this->alliance()->membership()->found($allianceTag, $allianceName);
        } catch (AllianceException $exit) {
            $this->exitWith($exit);
        }

        $this->printMessage(AllianceText::html($LNG, 'al_created', $label), [[
            'label' => $LNG['sys_forward'],
            'url' => '?page=alliance',
        ]]);
    }

    private function homeAlliance(): Response
    {
        $this->assign($this->alliance()->pages()->home($this->allianceData));

        return $this->render('game/page.alliance.home.html.twig');
    }

    public function memberList(): Response
    {
        if (!$this->rights['MEMBERLIST']) {
            $this->redirectToHome();
        }

        $lists = $this->alliance()->memberLists();
        $memberList = $lists->members($this->allianceData);

        $this->assign([
            'memberList' => $memberList,
            'mainList' => $lists->mainAlliances($this->allianceData),
            'wingList' => $lists->wingAlliances($this->allianceData),
            'al_users_list' => $this->state->LNG->format('al_users_list', count($memberList)),
            'timezone' => $this->state->USER['timezone'],
            'ShortStatus' => $lists->shortStatus(),
        ]);

        return $this->render('game/page.alliance.memberList.html.twig');
    }

    /** leave the alliance (not for the leader) */
    public function close()
    {
        $LNG = $this->state->LNG;

        if (!$this->hasAlliance || !$this->allianceData) {
            $this->redirectToHome();
        }

        try {
            $this->alliance()->membership()->leave($this->allianceData);
        } catch (AllianceException $exit) {
            $this->exitWith($exit);
        }

        $this->printMessage(AllianceText::html($LNG, 'al_leave_sucess', $this->allianceData['ally_name']), [[
            'label' => $LNG['sys_back'],
            'url' => '?page=alliance',
        ]]);
    }

    public function circular(): Response
    {
        $LNG = $this->state->LNG;
        if (!$this->rights['ROUNDMAIL']) {
            $this->redirectToHome();
        }

        if ($this->http()->_GP('action', '') == "send") {
            $rankId = $this->http()->_GP('rankID', 0);
            $subject = $this->http()->_GP('subject', '', true);
            $text = $this->http()->_GP('text', $LNG['mg_no_subject'], true);

            try {
                $sendList = $this->alliance()->messenger()->sendCircular($this->allianceData, $rankId, $subject, $text);
            } catch (AllianceException $exit) {
                $this->exitWith($exit);
            }

            $this->sendJSON(['message' => $sendList, 'error' => false]);
        }

        $this->setWindow('popup');

        $this->assign([
            'RangeList' => [$LNG['al_all_players']] + $this->alliance()->repository()->rankNames($this->allianceData['id']),
        ]);

        return $this->render('game/page.alliance.circular.html.twig');
    }

    /** dispatches ?action= to the admin* handlers below */
    public function admin(): ?Response
    {
        $LNG = & $this->state->LNG;

        $action = $this->http()->_GP('action', 'overview');
        $methodName = 'admin' . ucwords($action);

        if (!is_callable([$this, $methodName])) {
            (new ErrorController())->withGameServicesOf($this)->showError($LNG['page_doesnt_exist']);
        }

        return $this->{$methodName}();
    }

    protected function adminOverview(): Response
    {
        if (!$this->hasAlliance || !$this->rights['ADMIN']) {
            $this->redirectToHome();
        }

        $LNG = $this->state->LNG;
        $settings = $this->alliance()->settings();
        $textMode = $this->http()->_GP('textMode', 'external');

        if ($this->http()->_GP('send', 0)) {
            $input = [
                'owner_range' => $this->http()->_GP('owner_range', '', true),
                'web' => $this->http()->_GP('web', ''),
                'image' => $this->http()->_GP('image', ''),
                'request_notallow' => $this->http()->_GP('request_notallow', 0),
                'ally_max_members' => $this->http()->_GP('ally_max_members', ''),
                'request_min_points' => $this->http()->_GP('request_min_points', 0),
                'stats' => $this->http()->_GP('stats', 0),
                'diplo' => $this->http()->_GP('diplo', 0),
                'ally_tag' => $this->http()->_GP('ally_tag', $this->allianceData['ally_tag'], UTF8_SUPPORT),
                'ally_name' => $this->http()->_GP('ally_name', $this->allianceData['ally_name'], UTF8_SUPPORT),
                'text' => $this->http()->_GP('text', '', true),
                'textMode' => $textMode,
            ];
            try {
                $this->allianceData = $settings->save($this->allianceData, $input);
            } catch (AllianceException $exit) {
                $this->exitWith($exit);
            }
            $text = $input['text'];
        } else {
            $text = $settings->textFor($this->allianceData, $textMode);
        }

        $this->assign([
            'RequestSelector' => [0 => $LNG['al_requests_allowed'], 1 => $LNG['al_requests_not_allowed']],
            'YesNoSelector' => [1 => $LNG['al_go_out_yes'], 0 => $LNG['al_go_out_no']],
            'textMode' => $textMode,
            'text' => $text,
            'ally_tag' => $this->allianceData['ally_tag'],
            'ally_name' => $this->allianceData['ally_name'],
            'ally_web' => AllianceText::httpUrlOrEmpty((string) $this->allianceData['ally_web']),
            'ally_image' => AllianceText::httpUrlOrEmpty((string) $this->allianceData['ally_image']),
            'ally_request_notallow' => $this->allianceData['ally_request_notallow'],
            'ally_members' => $this->allianceData['ally_members'],
            'ally_max_members' => $this->allianceData['ally_max_members'],
            'ally_request_min_points' => $this->allianceData['ally_request_min_points'],
            'ally_owner_range' => $this->allianceData['ally_owner_range'],
            'ally_stats_data' => $this->allianceData['ally_stats'],
            'ally_diplo_data' => $this->allianceData['ally_diplo'],
        ]);

        return $this->render('game/page.alliance.admin.overview.html.twig');
    }

    /** disband the alliance (leader only, silently ignored otherwise) */
    protected function adminClose()
    {
        if ($this->allianceData) {
            $this->alliance()->membership()->disband($this->allianceData);
        }

        $this->redirectToHome();
    }

    protected function adminTransfer(): Response
    {
        if (!$this->allianceData || $this->allianceData['ally_owner'] != $this->state->USER['id']) {
            $this->redirectToHome();
        }

        $membership = $this->alliance()->membership();
        $newLeader = $this->http()->_GP('newleader', 0);
        if (!empty($newLeader)) {
            try {
                $membership->transfer($this->allianceData, $newLeader);
            } catch (AllianceException $exit) {
                $this->exitWith($exit);
            }
            $this->redirectToHome();
        }

        $this->assign([
            'transferUserList' => $membership->transferCandidates($this->allianceData),
        ]);

        return $this->render('game/page.alliance.admin.transfer.html.twig');
    }

    /** the application pages need both rights */
    private function requireApplicationRights(): void
    {
        if (!$this->rights['SEEAPPLY'] || !$this->rights['MANAGEAPPLY']) {
            $this->redirectToHome();
        }
    }

    protected function adminManageApply(): Response
    {
        $this->requireApplicationRights();

        $this->assign([
            'applyList' => $this->alliance()->applications()->listFor($this->allianceData),
        ]);

        return $this->render('game/page.alliance.admin.manageApply.html.twig');
    }

    protected function adminDetailApply(): Response
    {
        $LNG = $this->state->LNG;
        $this->requireApplicationRights();

        $applications = $this->alliance()->applications();
        $applyDetail = $applications->find($this->allianceData, $this->http()->_GP('id', 0));

        if (empty($applyDetail)) {
            $this->printMessage($LNG['al_apply_not_exists'], [[
                'label' => $LNG['sys_back'],
                'url' => 'game?page=alliance&mode=admin&action=manageApply',
            ]]);
        }

        $playerCard = (new PlayerCardController())->withGameServicesOf($this);
        $playerCard->initialize();

        $this->assign($applications->detailVariables($applyDetail, $playerCard));

        return $this->render('game/page.alliance.admin.detailApply.html.twig');
    }

    protected function adminSendAnswerToApply()
    {
        $this->requireApplicationRights();

        $text = makebr($this->http()->_GP('text', '', true));
        $answer = $this->http()->_GP('answer', '');
        $applyID = $this->http()->_GP('id', 0);

        try {
            $result = $this->alliance()->applications()->answer($this->allianceData, $applyID, $answer, $text);
        } catch (AllianceException $exit) {
            $this->exitWith($exit);
        }

        if ($result !== null) {
            // confirmation for the manager, in his own language
            $LNG = $this->state->LNG;
            $this->printMessage($result == 'yes' ? $LNG['al_apply_accepted'] : $LNG['al_apply_declined'], [[
                'label' => $LNG['sys_back'],
                'url' => 'game?page=alliance&mode=admin&action=manageApply',
            ]]);
        }
        $this->redirectTo('game?page=alliance&mode=admin&action=manageApply');
    }

    protected function adminPermissions(): Response
    {
        if (!$this->rights['RANKS']) {
            $this->redirectToHome();
        }

        $this->assign($this->alliance()->ranks()->permissionPage($this->allianceData, $this->rights) + [
            'ownRights' => $this->rights,
            'ownRankId' => $this->state->USER['ally_rank_id'],
        ]);

        return $this->render('game/page.alliance.admin.permissions.html.twig');
    }

    protected function adminPermissionsSend()
    {
        if (!$this->rights['RANKS']) {
            $this->redirectToHome();
        }

        $newRank = $this->http()->_GP('newrank', [], true);
        $delete = $this->http()->_GP('deleteRank', 0);
        $rankData = $this->http()->_GP('rank', []);
        $ranks = $this->alliance()->ranks();

        try {
            if (!empty($newRank['rankName'])) {
                $ranks->create($this->allianceData, $this->rights, $newRank);
            } elseif (!empty($delete)) {
                $ranks->delete($this->allianceData, $delete);
            } else {
                $ranks->update($this->allianceData, $this->rights, $rankData);
            }
        } catch (AllianceException $exit) {
            $this->exitWith($exit);
        }

        $this->redirectTo('game?page=alliance&mode=admin&action=permissions');
    }

    protected function adminMembers(): Response
    {
        $LNG = $this->state->LNG;
        if (!$this->rights['MANAGEUSERS']) {
            $this->redirectToHome();
        }

        $lists = $this->alliance()->memberLists();
        $choices = $this->alliance()->ranks()->memberChoices($this->allianceData, $this->rights);
        $memberList = $lists->adminMembers($this->allianceData);

        $this->assign([
            'memberList' => $memberList,
            'rankList' => $choices['rankList'],
            'rankSelectList' => $choices['rankSelectList'],
            'founder' => empty($this->allianceData['ally_owner_range'])
                ? $LNG['al_founder_rank_text'] : $this->allianceData['ally_owner_range'],
            'al_users_list' => $LNG->format('al_users_list', count($memberList)),
            'canKick' => $this->rights['KICK'],
            'ShortStatus' => $lists->shortStatus(),
        ]);

        return $this->render('game/page.alliance.admin.members.html.twig');
    }

    protected function adminRank()
    {
        if (!$this->rights['MANAGEUSERS']) {
            $this->redirectToHome();
        }

        $this->alliance()->ranks()->assign($this->allianceData, $this->rights, $this->http()->_GP('rank', []));

        $this->sendJSON($this->state->LNG['al_saved']);
    }

    protected function adminMembersKick()
    {
        if (!$this->rights['KICK']) {
            $this->redirectToHome();
        }

        try {
            $this->alliance()->membership()->kick($this->allianceData, $this->rights, $this->http()->_GP('id', 0));
        } catch (AllianceException $exit) {
            $this->exitWith($exit);
        }

        $this->redirectTo('game?page=alliance&mode=admin&action=members');
    }

    private function requireDiplomaticRight(): void
    {
        if (!$this->rights['DIPLOMATIC']) {
            $this->redirectToHome();
        }
    }

    protected function adminDiplomacy(): Response
    {
        $this->requireDiplomaticRight();

        $this->assign([
            'diploList' => $this->alliance()->diplomacy()->overview($this->allianceData),
        ]);

        return $this->render('game/page.alliance.admin.diplomacy.default.html.twig');
    }

    /** accept / reject / delete a treaty by ?id= (method: accept|reject|delete of AllianceDiplomacyService) */
    private function diplomacyAction(string $method): never
    {
        $this->requireDiplomaticRight();

        try {
            $this->alliance()->diplomacy()->$method($this->allianceData, $this->http()->_GP('id', 0));
        } catch (AllianceException $exit) {
            $this->exitWith($exit);
        }

        $this->redirectTo('game?page=alliance&mode=admin&action=diplomacy');
    }

    protected function adminDiplomacyAccept()
    {
        $this->diplomacyAction('accept');
    }

    protected function adminDiplomacyReject()
    {
        $this->diplomacyAction('reject');
    }

    protected function adminDiplomacyDelete()
    {
        $this->diplomacyAction('delete');
    }

    protected function adminDiplomacyCreate(): Response
    {
        $this->requireDiplomaticRight();
        $this->setWindow('popup');

        $diplomaticMode = $this->http()->_GP('diploMode', 0);
        $form = $this->alliance()->diplomacy()->createFormData($diplomaticMode, $this->http()->_GP('id', 0));

        $this->assign([
            'diploLevel' => $form['diploLevel'],
            'diploMode' => $diplomaticMode,
            'AllyList' => $form['AllyList'],
            'IdList' => $form['IdList'],
        ]);

        return $this->render('game/page.alliance.admin.diplomacy.create.html.twig');
    }

    protected function adminDiplomacyCreateProcessor()
    {
        $this->requireDiplomaticRight();

        $id = $this->http()->_GP('ally_id', '', UTF8_SUPPORT);
        $level = $this->http()->_GP('level', 0);
        $text = $this->http()->_GP('text', '', true);

        try {
            $this->alliance()->diplomacy()->create($this->allianceData, $id, $level, $text);
        } catch (AllianceException $exit) {
            $this->exitWith($exit);
        }

        $this->sendJSON([
            'error' => false,
            'message' => $this->state->LNG['al_diplo_create_done'],
        ]);
    }
}
