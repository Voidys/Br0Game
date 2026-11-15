<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\StoredData;
use App\Game\Core\Universe;

/**
 * Edit moderator rights per admin page and the authlevel of users.
 */
#[\App\Routing\GamePage('admin.php', 'rights')]
#[\App\Routing\GamePage('admin.php', 'rights', uniPrefix: true)]
class RightsController extends AbstractAdminController
{
    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $db = $this->db;
        $mode = $this->http()->_GP('mode', '');
        switch ($mode) {
            case 'rights':
                $this->addScript('filterlist.js');

                if ($this->state->requestPost) {
                    $id = $this->http()->_GP('id_1', 0);

                    if ($USER['id'] != ROOT_USER && $id == ROOT_USER) {
                        $this->message($LNG['ad_authlevel_error_3'], '?page=rights&mode=rights');
                    }

                    if (!isset($this->state->requestPost['rights'])) {
                        $this->state->requestPost['rights'] = [];
                    }
                    if (!isset($this->state->requestPost['action'])) {
                        $this->state->requestPost['action'] = '';
                    }

                    if ($this->state->requestPost['action'] == 'send') {
                        $db->update("UPDATE %%USERS%% SET `rights` = :rights WHERE `id` = :userID;", [
                            ':rights'   => StoredData::encode(array_map('intval', $this->state->requestPost['rights'])),
                            ':userID'   => $id,
                        ]);
                    }

                    $Rights = $db->selectSingle("SELECT rights FROM %%USERS%% WHERE id = :userID;", [
                        ':userID'   => $id,
                    ]);

                    $Rights['rights'] = StoredData::tryDecode($Rights['rights']);

                    $Files = array_map(
                        [$this, 'prepare'],
                        array_diff(
                            // rights are stored under the former page class names (see AbstractAdminController)
                            array_map(
                                static fn(string $file): string => 'Show' . basename($file, 'Controller.php') . 'Page.php',
                                array_filter(glob(__DIR__ . '/*Controller.php'), static fn(string $file): bool => !str_starts_with(basename($file), 'Abstract')),
                            ),
                            [
                                '.',
                                '..',
                                '.svn',
                                'index.html',
                                '.htaccess',
                                'ShowIndexPage.php',
                                'ShowErrorPage.php',
                                'ShowOverviewPage.php',
                                'ShowMenuPage.php',
                                'ShowTopnavPage.php',
                            ],
                        ),
                    );
                    $this->assign([
                        'Files'              => $Files,
                        'Rights'             => $Rights['rights'],
                        'id'                 => $id,
                        'yesorno'            => [1 => $LNG['one_is_yes_1'], 0 => $LNG['one_is_yes_0']],
                        'ad_authlevel_title' => $LNG['ad_authlevel_title'],
                        'button_submit'      => $LNG['button_submit'],
                        'User'               => '',
                    ]);

                    return $this->render('admin/ModerrationRightsPostPage.html.twig');
                }
                if (!isset($this->state->requestQuery['get'])) {
                    $this->state->requestQuery['get'] = '';
                }
                $authLevel = '';
                if ($this->state->requestQuery['get'] == 'adm') {
                    $authLevel = AUTH_ADM;
                } elseif ($this->state->requestQuery['get'] == 'ope') {
                    $authLevel = AUTH_OPS;
                } elseif ($this->state->requestQuery['get'] == 'mod') {
                    $authLevel = AUTH_MOD;
                } elseif ($this->state->requestQuery['get'] == 'pla') {
                    $authLevel = AUTH_USR;
                }

                if ($authLevel != '') {
                    $QueryUsers = $db->select("SELECT `id`, `username`, `authlevel` FROM %%USERS%% WHERE `universe` = :universe AND `authlevel` = :authlevel", [
                        ':universe' => $this->universe()->getEmulated(),
                        ':authlevel' => $authLevel,
                    ]);
                } else {
                    $QueryUsers = $db->select("SELECT `id`, `username`, `authlevel` FROM %%USERS%% WHERE `universe` = :universe", [
                        ':universe' => $this->universe()->getEmulated(),
                    ]);
                }


                $UserList = "";
                foreach ($QueryUsers as $List) {
                    $UserList .= '<option value="' . (int) $List['id'] . '">' . htmlspecialchars($List['username'], ENT_QUOTES, 'UTF-8', false) . '&nbsp;&nbsp;(' . $LNG['se_rank'][$List['authlevel']] . ')</option>';
                }

                $this->assign([
                    'Selector'               => [
                        0 => $LNG['se_rank'][0],
                        1 => $LNG['se_rank'][1],
                        2 => $LNG['se_rank'][2],
                        3 => $LNG['se_rank'][3],
                    ],
                    'UserList'               => new \Twig\Markup($UserList, 'UTF-8'),
                    'ad_authlevel_title'     => $LNG['ad_authlevel_title'],
                    'bo_select_title'        => $LNG['bo_select_title'],
                    'button_submit'          => $LNG['button_submit'],
                    'button_deselect'        => $LNG['button_deselect'],
                    'button_filter'          => $LNG['button_filter'],
                    'ad_authlevel_insert_id' => $LNG['ad_authlevel_insert_id'],
                    'ad_authlevel_auth'      => $LNG['ad_authlevel_auth'],
                    'ad_authlevel_aa'        => $LNG['ad_authlevel_aa'],
                    'ad_authlevel_oo'        => $LNG['ad_authlevel_oo'],
                    'ad_authlevel_mm'        => $LNG['ad_authlevel_mm'],
                    'ad_authlevel_jj'        => $LNG['ad_authlevel_jj'],
                    'ad_authlevel_tt'        => $LNG['ad_authlevel_tt'],
                ]);

                return $this->render('admin/ModerrationRightsPage.html.twig');
            case 'users':
                $this->addScript('filterlist.js');

                if ($this->state->requestPost) {
                    $id = $this->http()->_GP('id_1', 0);
                    $authlevel = $this->http()->_GP('authlevel', 0);

                    if ($id == 0) {
                        $id = $this->http()->_GP('id_2', 0);
                    }

                    if ($USER['id'] != ROOT_USER && $id == ROOT_USER) {
                        $this->message($LNG['ad_authlevel_error_3'], '?page=rights&mode=users');
                    }

                    $db->update("UPDATE %%USERS%% SET `authlevel` = :authlevel WHERE `id` = :userID;", [
                        ':userID'   => $id,
                        ':authlevel' => $authlevel,
                    ]);
                    $this->message($LNG['ad_authlevel_succes'], '?page=rights&mode=users');
                }
                if (!isset($this->state->requestQuery['get'])) {
                    $this->state->requestQuery['get'] = '';
                }
                $authLevel = '';
                if ($this->state->requestQuery['get'] == 'adm') {
                    $authLevel = AUTH_ADM;
                } elseif ($this->state->requestQuery['get'] == 'ope') {
                    $authLevel = AUTH_OPS;
                } elseif ($this->state->requestQuery['get'] == 'mod') {
                    $authLevel = AUTH_MOD;
                } elseif ($this->state->requestQuery['get'] == 'pla') {
                    $authLevel = AUTH_USR;
                }

                if ($authLevel != '') {
                    $QueryUsers = $db->select("SELECT `id`, `username`, `authlevel` FROM %%USERS%% WHERE `universe` = :universe AND `authlevel` = :authlevel", [
                        ':universe' => $this->universe()->getEmulated(),
                        ':authlevel' => $authLevel,
                    ]);
                } else {
                    $QueryUsers = $db->select("SELECT `id`, `username`, `authlevel` FROM %%USERS%% WHERE `universe` = :universe", [
                        ':universe' => $this->universe()->getEmulated(),
                    ]);
                }

                $UserList = "";
                foreach ($QueryUsers as $List) {
                    $UserList .= '<option value="' . (int) $List['id'] . '">' . htmlspecialchars($List['username'], ENT_QUOTES, 'UTF-8', false) . '&nbsp;&nbsp;('
                        . $LNG['se_rank'][$List['authlevel']] . ')</option>';
                }

                $this->assign([
                    'Selector'               => [
                        0 => $LNG['se_rank'][0],
                        1 => $LNG['se_rank'][1],
                        2 => $LNG['se_rank'][2],
                        3 => $LNG['se_rank'][3],
                    ],
                    'UserList'               => new \Twig\Markup($UserList, 'UTF-8'),
                    'ad_authlevel_title'     => $LNG['ad_authlevel_title'],
                    'bo_select_title'        => $LNG['bo_select_title'],
                    'button_submit'          => $LNG['button_submit'],
                    'button_deselect'        => $LNG['button_deselect'],
                    'button_filter'          => $LNG['button_filter'],
                    'ad_authlevel_insert_id' => $LNG['ad_authlevel_insert_id'],
                    'ad_authlevel_auth'      => $LNG['ad_authlevel_auth'],
                    'ad_authlevel_aa'        => $LNG['ad_authlevel_aa'],
                    'ad_authlevel_oo'        => $LNG['ad_authlevel_oo'],
                    'ad_authlevel_mm'        => $LNG['ad_authlevel_mm'],
                    'ad_authlevel_jj'        => $LNG['ad_authlevel_jj'],
                    'ad_authlevel_tt'        => $LNG['ad_authlevel_tt'],
                ]);

                return $this->render('admin/ModerrationUsersPage.html.twig');
        }

        // unknown mode: empty page, as before
        return new Response();
    }

    public function prepare($val): array|string
    {
        return str_replace('.php', '', $val);
    }
}
