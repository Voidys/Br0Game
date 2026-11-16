<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * Data of the admin layout (formerly three frames: menu, top navigation, content): the compact grouped
 * navigation filtered by the admin's rights (related pages share one entry and switch via tabs in the
 * page head) with the current entry and tab marked, the universe switcher,
 * the open ticket count and the account actions. Rendered by admin/overall_header.html.twig.
 *
 * pageData() is everything an admin page template needs besides its own variables (title, clock,
 * theme, the shell itself); data() is the shell part alone.
 */
final class AdminShell
{
    use \App\Game\Core\GameUtilities;

    /**
     * group => menu entries; an entry is [label key, icon, tabs]. Tabs are the pages shown under the
     * entry (switchable inside the page): [page, extra query, tab label key, right (former class name)],
     * a null right means every admin may open it. Entries with one tab are plain links; an entry is
     * hidden when the admin may open none of its tabs.
     */
    private const MENU = [
        'mu_general' => [
            ['adm_cp_index', 'layout-dashboard', [['overview', '', 'adm_cp_index', null]]],
            ['mu_game_info', 'info', [['information', '', 'mu_game_info', 'ShowInformationPage']]],
            ['adm_nav_settings', 'sliders-horizontal', [
                ['configBasic', '', 'adm_tab_server', 'ShowConfigBasicPage'],
                ['configUni', '', 'adm_tab_universe', 'ShowConfigUniPage'],
                ['configPlanets', '', 'adm_tab_planets', 'ShowMultiIPPage'],
                ['module', '', 'mu_module', 'ShowModulePage'],
            ]],
            ['adm_nav_expeditions', 'compass', [
                ['expeditionDeck', '', 'adm_tab_card_deck', 'ShowExpeditionDeckPage'],
                ['expeditionShips', '', 'adm_tab_findable_ships', 'ShowExpeditionShipsPage'],
            ]],
            ['adm_nav_bots', 'user-cog', [
                ['bots', 'mode=providers', 'adm_tab_bot_providers', 'ShowBotsPage'],
                ['bots', 'mode=profiles', 'adm_tab_bot_profiles', 'ShowBotsPage'],
                ['bots', 'mode=bots', 'adm_tab_bot_list', 'ShowBotsPage'],
            ]],
            ['mu_cronjob', 'calendar-clock', [['cronjob', '', 'mu_cronjob', 'ShowCronjobPage']]],
        ],
        'adm_group_community' => [
            ['adm_nav_players', 'users', [
                ['search', 'search=users', 'adm_tab_players', 'ShowSearchPage'],
                ['search', 'search=online', 'mu_connected', 'ShowSearchPage'],
                ['search', 'search=planet', 'adm_tab_planets', 'ShowSearchPage'],
                ['search', 'search=moon', 'adm_tab_moons', 'ShowSearchPage'],
                ['search', 'search=p_connect', 'mu_active_planets', 'ShowSearchPage'],
                ['search', '', 'mu_search_page', 'ShowSearchPage'],
            ]],
            ['adm_nav_accounts', 'id-card', [
                ['accountData', '', 'mu_info_account_page', 'ShowAccountDataPage'],
                ['accountEditor', '', 'adm_tab_editor', 'ShowAccountEditorPage'],
                ['creator', '', 'adm_tab_create', 'ShowCreatorPage'],
                ['accountswap', '', 'adm_tab_swap', 'ShowAccountswapPage'],
            ]],
            ['adm_nav_moderation', 'shield-check', [
                ['ban', '', 'adm_tab_bans', 'ShowBanPage'],
                ['active', '', 'mu_vaild_users', 'ShowActivePage'],
                ['multiIP', '', 'mu_multiip_page', 'ShowMultiIPPage'],
                ['comments', '', 'adm_comments', 'ShowMultiIPPage'],
                ['analytics', '', 'adm_analytics', 'ShowMultiIPPage'],
            ]],
            ['mu_support', 'ticket', [['support', '', 'mu_support', 'ShowSupportPage']]],
            ['adm_nav_messages', 'messages-square', [
                ['messageList', '', 'mu_mess_list', 'ShowMessageListPage'],
                ['sendMessages', '', 'mu_global_message', 'ShowSendMessagesPage'],
            ]],
            ['adm_nav_content', 'file-text', [
                ['pages', '', 'adm_tab_pages', 'ShowPagesPage'],
                ['news', '', 'mu_news', 'ShowNewsPage'],
                ['team', '', 'adm_tab_team', 'ShowTeamPage'],
            ]],
            ['mu_flying_fleets', 'send', [['flyingFleet', '', 'mu_flying_fleets', 'ShowFlyingFleetPage']]],
        ],
        'mu_tools' => [
            ['mu_logs', 'scroll-text', [['log', '', 'mu_logs', 'ShowLogPage']]],
            ['mu_giveaway', 'gift', [['giveaway', '', 'mu_giveaway', 'ShowGiveawayPage']]],
            ['mu_dump', 'hard-drive-download', [['dump', '', 'mu_dump', 'ShowDumpPage']]],
            ['mu_md5_encripter', 'key-round', [['passEncripter', '', 'mu_md5_encripter', 'ShowPassEncripterPage']]],
            ['adm_nav_maintenance', 'wrench', [['maintenance', '', 'adm_nav_maintenance', 'ShowClearCachePage|ShowStatUpdatePage']]],
            ['rp_header', 'shuffle', [['reshuffle', '', 'rp_header', 'ShowReshufflePage']]],
        ],
    ];

    /** pages that ask for confirmation before they run (label key of the question) */
    private const CONFIRM = [];

    /** action pages without a menu entry of their own, shown under the entry of the given page */
    private const PAGE_ALIASES = ['clearcache' => 'maintenance', 'statupdate' => 'maintenance'];

    public function __construct(
        private Database $db,
        private ConfigRegistry $configs,
        private Singleton $state,
    ) {}

    /** templates rendered without the shell (password prompt, popup editors) */
    private const BARE_TEMPLATES = ['LoginPage.html.twig', 'QuickEditorUser.html.twig', 'QuickEditorPlanet.html.twig'];

    /**
     * Layout variables of an admin page (formerly Template::adm_main()). The shell is left out for
     * the bare templates and as long as the session has no admin access (password prompt).
     *
     * @return array<string, mixed>
     */
    public function pageData(string $template = ''): array
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $config = $this->configs->get();

        $serverTime = new \DateTime('now');
        $userTime = $serverTime;
        if (isset($USER['timezone'])) {
            try {
                $userTime = new \DateTime('now', new \DateTimeZone($USER['timezone']));
            } catch (\Exception) {
                $userTime = $serverTime;
            }
        }

        $bare = in_array(basename($template), self::BARE_TEMPLATES, true) || (int) ($this->state->session->adminAccess ?? 0) !== 1;

        return [
            'title' => $config->game_name . ' - ' . $LNG['adm_cp_title'],
            'fcm_info' => $LNG['fcm_info'],
            'lang' => $LNG->getLanguage(),
            'REV' => substr($config->version, -4),
            'date' => explode('|', date('Y\\|n\\|j\\|G\\|i\\|s\\|Z', $this->state->runtime->timestamp)),
            'Offset' => $userTime->getOffset() - $serverTime->getOffset(),
            'version' => $config->version,
            'dpath' => 'styles/theme/nova/',
            'themeCssPath' => 'styles/theme/nova/theme.css',
            'bodyclass' => 'full',
            'adminShell' => !$bare,
            // also on the notice pages, which carry no page variables
            'signalColors' => $USER['signalColors'] ?? null,
            // body id: the requested page (styling hook)
            'bodyId' => preg_replace('/\W/', '', (string) ($this->state->requestQuery['page'] ?? '')) ?: 'overview',
        ] + ($bare ? [] : $this->data());
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $isAdmin = (int) $USER['authlevel'] === AUTH_ADM;
        $currentPage = strtolower((string) ($this->state->requestQuery['page'] ?? 'overview'));
        $currentPage = self::PAGE_ALIASES[$currentPage] ?? $currentPage;
        $currentSearch = (string) ($this->state->requestQuery['search'] ?? '');
        $currentMode = (string) ($this->state->requestQuery['mode'] ?? '');

        $openTickets = (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS anz FROM %%TICKETS%% WHERE `universe` = :universe AND `status` = 0;',
            [':universe' => $this->universe()->getEmulated()],
            'anz',
        );

        $menu = [];
        $groups = $this->menuGroups($isAdmin);
        $currentTitle = $LNG['adm_cp_index'];
        $currentTab = null;
        $tabs = [];
        foreach ($groups as $groupKey => $entries) {
            $items = [];
            foreach ($entries as [$labelKey, $icon, $entryTabs]) {
                [$allowed, $activeTab] = $this->allowedTabs($entryTabs, $currentPage, $currentSearch, $currentMode);

                if ($allowed === []) {
                    continue;
                }

                $label = $LNG[$labelKey];
                if ($activeTab !== null) {
                    $currentTitle = $label;
                    $currentTab = count($allowed) > 1 ? $activeTab['label'] : null;
                    $tabs = count($allowed) > 1 ? $allowed : [];
                }
                $firstPage = $allowed[0]['page'];
                $items[] = [
                    'href' => $allowed[0]['href'],
                    'label' => $label,
                    'icon' => $icon,
                    'active' => $activeTab !== null,
                    // the filter also finds an entry by the names of its tabs
                    'keywords' => implode(' ', array_column($allowed, 'label')),
                    'badge' => $firstPage === 'support' && $openTickets > 0 ? $openTickets : null,
                    'confirm' => isset(self::CONFIRM[$firstPage]) ? $LNG[self::CONFIRM[$firstPage]] : null,
                ];
            }
            if ($items !== []) {
                $menu[] = ['label' => $LNG[$groupKey], 'items' => $items];
            }
        }

        $universes = $this->universeOptions($isAdmin);

        return [
            'adminMenu' => $menu,
            'adminTitle' => $currentTitle,
            'adminTab' => $currentTab,
            'adminTabs' => $tabs,
            'adminUniverses' => $universes,
            'adminUniverse' => $this->universe()->getEmulated(),
            'adminUser' => $USER['username'] ?? '',
            'adminAuthLevel' => (int) $USER['authlevel'],
            'adminOpenTickets' => $openTickets,
        ];
    }

    /**
     * The menu groups: the static MENU, plus the system group (universes, rights, reset for the root user) for admins.
     *
     * @return array<string, list<array>> group label key => entries (label key, icon, tabs)
     */
    private function menuGroups(bool $isAdmin): array
    {
        $USER = $this->state->USER;

        $groups = self::MENU;
        if ($isAdmin) {
            $groups['ad_system'] = [
                ['mu_universe', 'server', [['universe', '', 'mu_universe', null]]],
                ['mu_moderation_page', 'lock', [
                    ['rights', 'mode=rights', 'mu_moderation_page', null],
                    ['rights', 'mode=users', 'ad_authlevel_title', null],
                ]],
            ];
            if ((int) $USER['id'] === 1) {
                $groups['ad_system'][] = ['re_reset_universe', 'triangle-alert', [['reset', '', 're_reset_universe', null]]];
            }
        }

        return $groups;
    }

    /**
     * Filters the tabs of one menu entry by the admin's rights (a right like "A|B" is met by either) and marks the tab
     * of the current page (search lists also compare the `search`, tabs with a `mode` compare the mode).
     *
     * @param list<array{0: string, 1: string, 2: string, 3: ?string}> $entryTabs page, query, label key, right
     * @return array{0: list<array<string, mixed>>, 1: ?array<string, mixed>} the allowed tabs and the active one
     */
    private function allowedTabs(array $entryTabs, string $currentPage, string $currentSearch, string $currentMode): array
    {
        $LNG = $this->state->LNG;

        $allowed = [];
        $activeTab = null;
        foreach ($entryTabs as [$page, $query, $tabLabelKey, $right]) {
            // a right like "A|B" is met by either of them
            if ($right !== null && !array_filter(explode('|', $right), fn(string $one): bool => (bool) $this->viewHelpers()->allowedTo($one))) {
                continue;
            }
            parse_str($query, $params);
            $active = strtolower($page) === $currentPage
                && ($page !== 'search' || ($params['search'] ?? '') === $currentSearch)
                && (!isset($params['mode']) || $params['mode'] === $currentMode);
            $tab = [
                'href' => 'admin?page=' . $page . ($query !== '' ? '&' . $query : ''),
                'label' => $LNG[$tabLabelKey],
                'active' => $active,
            ];
            if ($active) {
                $activeTab = $tab;
            }
            $allowed[] = $tab + ['page' => $page];
        }

        // a page called without its mode (e.g. admin?page=bots) shows its first tab, so that tab is the active one
        if ($activeTab === null && $currentMode === '') {
            foreach ($allowed as $i => $tab) {
                if (strtolower($tab['page']) === $currentPage && str_contains($tab['href'], '&mode=')) {
                    $allowed[$i]['active'] = true;
                    $activeTab = array_diff_key($allowed[$i], ['page' => true]);
                    break;
                }
            }
        }

        return [$allowed, $activeTab];
    }

    /**
     * The universe switcher options (admins only): "name (ID n)" by universe id.
     *
     * @return array<int, string>
     */
    private function universeOptions(bool $isAdmin): array
    {
        $universes = [];
        if ($isAdmin) {
            foreach ($this->universe()->availableUniverses() as $uniId) {
                $universes[$uniId] = sprintf('%s (ID %d)', $this->configs->get($uniId)->uni_name, $uniId);
            }
            ksort($universes);
        }

        return $universes;
    }
}
