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

    #[Required]
    public function setBotServices(LlmClientFactory $llm, BotProfiles $profiles, BotSpawner $spawner, BotRegistry $registry): void
    {
        $this->llm = $llm;
        $this->profiles = $profiles;
        $this->spawner = $spawner;
        $this->registry = $registry;
    }

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
        $LNG = $this->state->LNG;
        $post = $this->state->requestPost;

        switch ($action) {
            case 'save_providers':
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

            case 'save_profile':
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

            case 'delete_profile':
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

            case 'duplicate_profile':
                if ($this->profiles->get((int) ($post['id'] ?? 0))) {
                    $this->profiles->duplicate((int) $post['id']);
                    $this->flash($LNG['bot_saved']);
                }

                return $this->tabUrl('profiles');

            case 'restore_profiles':
                $this->profiles->restoreDefaults();
                $this->flash($LNG['bot_defaults_restored']);

                return $this->tabUrl('profiles');

            case 'spawn':
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

            case 'remove_bot':
                $userId = (int) ($post['user_id'] ?? 0);
                $isBot = $this->db->selectSingle('SELECT user_id FROM %%BOT_PLAYERS%% WHERE user_id = :u;', [':u' => $userId], 'user_id');
                if ($isBot) {
                    $this->spawner->remove($userId);
                    $this->flash($LNG['bot_deleted']);
                }

                return $this->tabUrl('bots');
        }

        return $this->tabUrl($tab);
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
            'message_chance' => $p['message_chance'] ?? 0,
            'alliance_chance' => $p['alliance_chance'] ?? 0,
            'buddy_accept_chance' => $p['buddy_accept_chance'] ?? 0,
            'chat_style' => (string) ($p['chat_style'] ?? ''),
        ];
    }

    // ---------------------------------------------------------------------------- views

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
        } elseif ($tab === 'profiles') {
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
        } else {
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
        }

        $this->assign($vars);

        return $this->render('admin/BotsPage.html.twig');
    }
}
