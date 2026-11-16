<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Game\Bots\BotProfiles;
use App\Game\Bots\BotRegistry;
use App\Game\Bots\BotSpawner;
use App\Game\Bots\Llm\LlmClient;
use App\Game\Bots\Llm\LlmClientFactory;
use App\Game\Bots\Llm\LlmException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Bots: LLM providers, behaviour profiles and the bots of the emulated universe. Tabs (providers, profiles,
 * bots) are the "mode" of the page. Every POST carries the session CSRF token; changes redirect back (PRG).
 */
#[\App\Routing\GamePage('admin.php', 'bots')]
#[\App\Routing\GamePage('admin.php', 'bots', uniPrefix: true)]
class BotsController extends AbstractAdminController
{
    private const TABS = ['providers', 'profiles', 'bots'];

    private const MODEL_HINTS = [
        'openai' => 'gpt-4.1-mini',
        'claude' => 'claude-sonnet-4-5',
        'opencode_go' => 'deepseek-v4.1-flash',
        'deepseek' => 'deepseek-chat',
        'openrouter' => 'openai/gpt-4.1-mini',
    ];

    private const WEIGHT_KEYS = ['mines', 'energy', 'storage', 'facilities', 'research', 'ships', 'defense'];

    private LlmClientFactory $llm;
    private BotProfiles $profiles;
    private BotSpawner $spawner;
    private BotRegistry $registry;

    /**
     * Setter injection of the bot services (LLM clients, profiles, bot service) for this controller.
     */
    #[Required]
    public function setBotServices(LlmClientFactory $llm, BotProfiles $profiles, BotSpawner $spawner, BotRegistry $registry): void
    {
        $this->llm = $llm;
        $this->profiles = $profiles;
        $this->spawner = $spawner;
        $this->registry = $registry;
    }

    /**
     * Bots admin page. Request: mode (tab: providers, profiles or bots; default providers), action. action=test is answered as JSON by actionTest().
     * Other POST actions need the session CSRF token (`csrf`, else a flash error and redirect), run in handlePost() and redirect to the URL it returns
     * (post/redirect/get, result message via session flash); everything else renders the tab with renderTab().
     */
    public function show(): Response
    {
        $tab = (string) $this->http()->_GP('mode', 'providers');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'providers';
        }
        $action = (string) $this->http()->_GP('action', '');
        $isPost = ($this->state->requestServer['REQUEST_METHOD'] ?? '') === 'POST';

        if ($action === 'test') {
            return $this->actionTest($isPost);
        }

        if ($isPost && $action !== '') {
            if (!$this->csrfOk()) {
                $this->flash($this->state->LNG['bot_csrf_error'], false);
                $this->http()->redirectTo($this->tabUrl($tab));
            }
            $this->http()->redirectTo($this->handlePost($action, $tab));
        }

        return $this->renderTab($tab);
    }

    // ---------------------------------------------------------------------------- CSRF / flash

    private function session(): \Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        return $this->sessions()->symfonySession();
    }

    private function csrfToken(): string
    {
        $session = $this->session();
        $token = (string) $session->get('bots_csrf', '');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            $session->set('bots_csrf', $token);
        }

        return $token;
    }

    private function csrfOk(): bool
    {
        $given = (string) ($this->state->requestPost['csrf'] ?? '');

        return $given !== '' && hash_equals($this->csrfToken(), $given);
    }

    private function flash(string $message, bool $ok = true): void
    {
        $this->session()->set('bots_flash', ['message' => $message, 'ok' => $ok]);
    }

    private function tabUrl(string $tab, string $extra = ''): string
    {
        return 'admin?page=bots&mode=' . $tab . $extra;
    }

    // ---------------------------------------------------------------------------- actions

    /**
     * Tests an LLM provider connection (button on the providers tab), answered as JSON. Needs a POST with the CSRF token (otherwise 403 {ok: false}).
     * POST provider (must be one of LlmClient::PROVIDERS), api_key, model, base_url build a client for those values and run its test call; the result
     * array is returned, an LlmException becomes {ok: false, message}.
     */
    private function actionTest(bool $isPost): JsonResponse
    {
        $LNG = $this->state->LNG;
        $fail = static fn(string $message, int $status = 200): JsonResponse => new JsonResponse(
            ['ok' => false, 'message' => $message, 'response' => '', 'latency_ms' => 0],
            $status,
        );

        if (!$isPost || !$this->csrfOk()) {
            return $fail($LNG['bot_csrf_error'], 403);
        }

        $post = $this->state->requestPost;
        $provider = (string) ($post['provider'] ?? '');
        if (!in_array($provider, LlmClient::PROVIDERS, true)) {
            return $fail('Unknown provider.', 400);
        }

        try {
            $client = $this->llm->forProvider(
                $provider,
                (string) ($post['api_key'] ?? ''),
                (string) ($post['model'] ?? ''),
                (string) ($post['base_url'] ?? ''),
            );

            return new JsonResponse($client->test());
        } catch (LlmException $e) {
            return $fail($e->getMessage());
        }
    }

    /** runs a POST action and returns the URL to continue at */
    private function handlePost(string $action, string $tab): string
    {
        switch ($action) {
            case 'save_providers':
                return $this->saveProviders();
            case 'save_profile':
                return $this->saveProfile();
            case 'delete_profile':
                return $this->deleteProfile();
            case 'duplicate_profile':
                return $this->duplicateProfile();
            case 'restore_profiles':
                return $this->restoreProfiles();
            case 'spawn':
                return $this->spawnBots();
            case 'remove_bot':
                return $this->removeBot();
        }

        return $this->tabUrl($tab);
    }

    /**
     * Action save_providers: validates the base URLs (http/https only), stores key, model and URL of every provider and marks the active one; returns the URL of the providers tab.
     */
    private function saveProviders(): string
    {
        $LNG = $this->state->LNG;
        $post = $this->state->requestPost;

        $active = (string) ($post['active'] ?? 'none');
        $rows = [];
        foreach (LlmClient::PROVIDERS as $provider) {
            $row = (array) ($post['providers'][$provider] ?? []);
            $url = mb_substr(trim((string) ($row['base_url'] ?? '')), 0, 255);
            if ($url !== '' && !preg_match('~^https?://~i', $url)) {
                $this->flash($LNG['bot_invalid_url'], false);

                return $this->tabUrl('providers');
            }
            $rows[$provider] = [
                trim((string) ($row['api_key'] ?? '')),
                mb_substr(trim((string) ($row['model'] ?? '')), 0, 128),
                $url,
            ];
        }
        foreach ($rows as $provider => [$key, $model, $url]) {
            $this->llm->saveProvider($provider, $key === '' ? null : $key, $model, $url, $active === $provider);
        }
        $this->flash($LNG['bot_saved']);

        return $this->tabUrl('providers');
    }

    /**
     * Action save_profile: creates or updates a behaviour profile (a name is required); returns the URL of the profiles tab (back to the form on error).
     */
    private function saveProfile(): string
    {
        $LNG = $this->state->LNG;
        $post = $this->state->requestPost;

        $id = (int) ($post['id'] ?? 0);
        $name = trim((string) ($post['name'] ?? ''));
        if ($name === '') {
            $this->flash($LNG['bot_name_required'], false);

            return $this->tabUrl('profiles', $id > 0 ? '&edit=' . $id : '&new=1');
        }
        $this->profiles->save([
            'id' => $id,
            'name' => $name,
            'description' => (string) ($post['description'] ?? ''),
            'settings' => $this->settingsFromPost($post),
        ]);
        $this->flash($LNG['bot_saved']);

        return $this->tabUrl('profiles');
    }

    /**
     * Action delete_profile: deletes a profile unless it is a default profile or still used by bots; returns the URL of the profiles tab.
     */
    private function deleteProfile(): string
    {
        $LNG = $this->state->LNG;
        $post = $this->state->requestPost;

        $id = (int) ($post['id'] ?? 0);
        $profile = $this->profiles->get($id);
        if (!$profile || $profile['is_default']) {
            $this->flash($LNG['bot_profile_default_locked'], false);
        } elseif ((int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%BOT_PLAYERS%% WHERE profile_id = :id;', [':id' => $id], 'c') > 0) {
            $this->flash($LNG['bot_profile_in_use'], false);
        } else {
            $this->profiles->delete($id);
            $this->flash($LNG['bot_deleted']);
        }

        return $this->tabUrl('profiles');
    }

    /**
     * Action duplicate_profile: copies an existing profile; returns the URL of the profiles tab.
     */
    private function duplicateProfile(): string
    {
        $LNG = $this->state->LNG;
        $post = $this->state->requestPost;

        if ($this->profiles->get((int) ($post['id'] ?? 0))) {
            $this->profiles->duplicate((int) $post['id']);
            $this->flash($LNG['bot_saved']);
        }

        return $this->tabUrl('profiles');
    }

    /**
     * Action restore_profiles: restores the default profiles; returns the URL of the profiles tab.
     */
    private function restoreProfiles(): string
    {
        $LNG = $this->state->LNG;

        $this->profiles->restoreDefaults();
        $this->flash($LNG['bot_defaults_restored']);

        return $this->tabUrl('profiles');
    }

    /**
     * Action spawn: creates 1-50 bots with the given profile in the emulated universe; returns the URL of the bots tab.
     */
    private function spawnBots(): string
    {
        $LNG = $this->state->LNG;
        $post = $this->state->requestPost;

        $profileId = (int) ($post['profile_id'] ?? 0);
        $count = max(1, min(50, (int) ($post['count'] ?? 1)));
        if (!$this->profiles->get($profileId)) {
            $this->flash($LNG['bot_profile_missing'], false);
        } else {
            try {
                $ids = $this->spawner->spawn($this->universe()->getEmulated(), $profileId, $count);
                $this->flash($LNG->format('bot_spawned', count($ids)));
            } catch (\Throwable $e) {
                $this->flash($e->getMessage(), false);
            }
        }

        return $this->tabUrl('bots');
    }

    /**
     * Action remove_bot: removes a bot player (only real bots); returns the URL of the bots tab.
     */
    private function removeBot(): string
    {
        $LNG = $this->state->LNG;
        $post = $this->state->requestPost;

        $userId = (int) ($post['user_id'] ?? 0);
        $isBot = $this->db->selectSingle('SELECT user_id FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $userId], 'user_id');
        if ($isBot) {
            $this->spawner->remove($userId);
            $this->flash($LNG['bot_deleted']);
        }

        return $this->tabUrl('bots');
    }


    /** raw profile settings from the form, normalized and clamped by BotProfiles::normalizeSettings() on save */
    private function settingsFromPost(array $p): array
    {
        $weights = [];
        foreach (self::WEIGHT_KEYS as $k) {
            $weights[$k] = $p['weights'][$k] ?? 0;
        }

        return [
            'activity' => array_map('trim', explode(',', (string) ($p['activity'] ?? ''))),
            'pattern_shift_hours' => $p['pattern_shift_hours'] ?? 0,
            'pattern_shift_days' => $p['pattern_shift_days'] ?? 0,
            'check_interval' => [$p['check_interval_min'] ?? 1, $p['check_interval_max'] ?? 1],
            'session_minutes' => [$p['session_minutes_min'] ?? 1, $p['session_minutes_max'] ?? 1],
            'actions_per_session' => [$p['actions_per_session_min'] ?? 1, $p['actions_per_session_max'] ?? 1],
            'weights' => $weights,
            'expedition_chance' => $p['expedition_chance'] ?? 0,
            'colonize_chance' => $p['colonize_chance'] ?? 0,
            'skill_start' => $p['skill_start'] ?? 0,
            'skill_max' => $p['skill_max'] ?? 0,
            'skill_gain_per_day' => $p['skill_gain_per_day'] ?? 0,
            'mistake_chance' => $p['mistake_chance'] ?? 0,
            'randomness' => $p['randomness'] ?? 0,
            'llm_advice' => !empty($p['llm_advice']),
            'reply_chance' => $p['reply_chance'] ?? 0,
            'alliance_chance' => $p['alliance_chance'] ?? 0,
            'alliance_join_chance' => $p['alliance_join_chance'] ?? 0,
            'fleetsave_chance' => $p['fleetsave_chance'] ?? 0,
            'saving_discipline' => $p['saving_discipline'] ?? 0,
            'expedition_slots' => $p['expedition_slots'] ?? 0,
            'buddy_accept_chance' => $p['buddy_accept_chance'] ?? 0,
            'chat_style' => (string) ($p['chat_style'] ?? ''),
            'raid_enabled' => !empty($p['raid_enabled']),
            'raid_chance' => $p['raid_chance'] ?? 0,
            'scout_range' => $p['scout_range'] ?? 1,
            'min_profit_ratio' => $p['min_profit_ratio'] ?? 1,
            'relentless_minutes' => [$p['relentless_min'] ?? 0, $p['relentless_max'] ?? 0],
            'recall_check_chance' => $p['recall_check_chance'] ?? 0,
            'max_attacks_per_target_per_day' => $p['max_attacks_per_target_per_day'] ?? 1,
            'attack_bots' => !empty($p['attack_bots']),
            'attack_humans' => !empty($p['attack_humans']),
            'prefer_inactive' => $p['prefer_inactive'] ?? 0,
        ];
    }

    // ---------------------------------------------------------------------------- views

    /**
     * Renders one tab and consumes the session flash message. providers: the providers with key hint (never the key), model, URL, active flag and last
     * test result. profiles: the behaviour profiles with a settings summary, plus the edit form when `edit` (profile id) or `new` is requested. bots: the
     * bots of the emulated universe, the profile choices, the `bots_enabled` switch and the 50 most recent log entries.
     */
    private function renderTab(string $tab): Response
    {
        $LNG = $this->state->LNG;
        $universe = $this->universe()->getEmulated();
        $session = $this->session();
        $flash = $session->get('bots_flash');
        $session->remove('bots_flash');

        $vars = [
            'tab' => $tab,
            'csrf' => $this->csrfToken(),
            'message' => is_array($flash) ? (string) $flash['message'] : '',
            'messageOk' => !is_array($flash) || !empty($flash['ok']),
            'universe' => $universe,
        ];

        if ($tab === 'providers') {
            $vars += $this->providersTabVars();
        } elseif ($tab === 'profiles') {
            $vars += $this->profilesTabVars();
        } else {
            $vars += $this->botsTabVars($universe);
        }

        $this->assign($vars);

        return $this->render('admin/BotsPage.html.twig');
    }

    /**
     * Variables of the providers tab: the providers with key hint (never the key), model, URL, active flag and last test result, plus the JS strings.
     *
     * @return array<string, mixed> template variables of the tab
     */
    private function providersTabVars(): array
    {
        $vars = [];
        $LNG = $this->state->LNG;
        $providers = [];
        foreach ($this->llm->providers() as $row) {
            $name = (string) $row['provider'];
            $hint = (string) ($row['key_hint'] ?? '');
            $providers[$name] = [
                'provider' => $name,
                'has_key' => $hint !== '',
                'key_hint' => $hint,
                'model' => (string) ($row['model'] ?? ''),
                'base_url' => (string) ($row['base_url'] ?? ''),
                'default_url' => (string) ($row['default_url'] ?? ''),
                'is_active' => !empty($row['is_active']),
                'model_hint' => (string) ($row['model_hint'] ?? (self::MODEL_HINTS[$name] ?? '')),
                'last_test_at' => (string) ($row['last_test_at_text'] ?? ''),
                'last_test_ok' => !empty($row['last_test_ok']),
                'last_test_message' => (string) ($row['last_test_message'] ?? ''),
            ];
        }
        $ordered = [];
        foreach (LlmClient::PROVIDERS as $name) {
            if (isset($providers[$name])) {
                $ordered[] = $providers[$name];
            }
        }
        $vars['providers'] = $ordered;
        $vars['anyActive'] = (bool) array_filter($ordered, static fn(array $p): bool => $p['is_active']);
        $vars['jsStrings'] = [
            'testing' => $LNG['bot_testing'],
            'failed' => $LNG['bot_failed'],
            'ok' => 'OK',
        ];

        return $vars;
    }

    /**
     * Variables of the profiles tab: the behaviour profiles with a settings summary, plus the edit form when `edit` (profile id) or `new` is requested.
     *
     * @return array<string, mixed> template variables of the tab
     */
    private function profilesTabVars(): array
    {
        $vars = [];
        $LNG = $this->state->LNG;
        $profiles = [];
        foreach ($this->profiles->all() as $p) {
            $s = $p['settings'];
            $p['summary'] = sprintf(
                '%s %d-%d min, %s %d-%d, %s %d%%, %s %d%%',
                $LNG['bot_summary_session'],
                $s['session_minutes'][0],
                $s['session_minutes'][1],
                $LNG['bot_summary_actions'],
                $s['actions_per_session'][0],
                $s['actions_per_session'][1],
                $LNG['bot_skill'],
                round($s['skill_start'] * 100),
                $LNG['bot_summary_mistakes'],
                $s['mistake_chance'],
            );
            $profiles[] = $p;
        }
        $vars['profiles'] = $profiles;

        $editForm = null;
        $editId = (int) $this->http()->_GP('edit', 0);
        if ($editId > 0 || $this->http()->_GP('new', 0)) {
            $base = $editId > 0 ? $this->profiles->get($editId) : null;
            $settings = $base['settings'] ?? $this->profiles->defaults()['office_worker']['settings'];
            $settings['activity_csv'] = implode(', ', $settings['activity']);
            $editForm = [
                'id' => (int) ($base['id'] ?? 0),
                'name' => (string) ($base['name'] ?? ''),
                'description' => (string) ($base['description'] ?? ''),
                'is_default' => !empty($base['is_default']),
                's' => $settings,
            ];
        }
        $vars['editForm'] = $editForm;
        $vars['weightKeys'] = self::WEIGHT_KEYS;

        return $vars;
    }

    /**
     * Variables of the bots tab: the bots of the universe, the profile choices, the `bots_enabled` switch and the 50
     * most recent log entries.
     *
     * @return array<string, mixed> template variables of the tab
     */
    private function botsTabVars(int|string $universe): array
    {
        $vars = [];
        $profiles = [];
        foreach ($this->profiles->all() as $p) {
            $profiles[] = ['id' => (int) $p['id'], 'name' => (string) $p['name']];
        }
        $bots = $this->registry->listBots($universe);
        $log = $this->registry->recentLog($universe, 50);
        $vars += [
            'botsEnabled' => (bool) $this->configs->get($universe)->bots_enabled,
            'profiles' => $profiles,
            'bots' => $bots,
            'botLog' => $log,
        ];

        return $vars;
    }
}
