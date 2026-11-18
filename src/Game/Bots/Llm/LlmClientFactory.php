<?php

namespace App\Game\Bots\Llm;

use App\Game\Core\Database;
use App\Game\Core\Singleton;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds LlmClient objects from the bot_llm_providers table and manages the provider rows.
 */
class LlmClientFactory
{
    /** Example model name per provider (placeholder in the admin form). */
    public const MODEL_HINTS = [
        'openai' => 'gpt-4.1-mini',
        'claude' => 'claude-sonnet-4-5',
        'opencode_go' => 'deepseek-v4.1-flash',
        'deepseek' => 'deepseek-chat',
        'openrouter' => 'openai/gpt-4.1-mini',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Singleton $state,
        private readonly HttpClientInterface $httpClient,
        private readonly LlmUrlGuard $guard = new LlmUrlGuard(),
    ) {}

    /** The active provider with a stored key and model, else null. */
    public function active(): ?LlmClient
    {
        $row = $this->db->selectSingle('SELECT * FROM %%BOT_LLM_PROVIDERS%% WHERE is_active = 1 LIMIT 1;');
        if (empty($row) || trim((string) $row['api_key']) === '' || trim((string) $row['model']) === '') {
            return null;
        }

        return $this->build((string) $row['provider'], (string) $row['api_key'], (string) $row['model'], (string) $row['base_url']);
    }

    /**
     * Client for a provider with optional overrides (e.g. to test unsaved form values): null = use the stored value;
     * an empty key means "stored key". Refuses to use the stored key together with a different base URL than the
     * stored one, so a key can never be sent to a URL the admin typed in without re-entering it.
     *
     * @throws LlmException
     */
    public function forProvider(string $provider, ?string $apiKey = null, ?string $model = null, ?string $baseUrl = null): LlmClient
    {
        if (!in_array($provider, LlmClient::PROVIDERS, true)) {
            throw new LlmException('Unknown provider.');
        }
        $row = $this->db->selectSingle('SELECT * FROM %%BOT_LLM_PROVIDERS%% WHERE provider = :p;', [':p' => $provider]) ?: [];

        $storedKey = $apiKey === null || trim($apiKey) === '';
        $key = $storedKey ? (string) ($row['api_key'] ?? '') : trim($apiKey);
        $mod = $model === null ? (string) ($row['model'] ?? '') : trim($model);
        $url = ($baseUrl === null || trim($baseUrl) === '') ? (string) ($row['base_url'] ?? '') : trim($baseUrl);

        // Never send the stored key to a base URL other than the stored one.
        $storedUrl = trim((string) ($row['base_url'] ?? '')) ?: LlmClient::defaultBaseUrl($provider);
        if ($storedKey && $key !== '' && rtrim($url ?: LlmClient::defaultBaseUrl($provider), '/') !== rtrim($storedUrl, '/')) {
            throw new LlmException('Changed base URL: enter the API key again (or save first).');
        }

        return $this->build($provider, $key, $mod, $url);
    }

    /**
     * All provider rows in the fixed provider order, without the key itself.
     *
     * @return list<array{provider: string, has_key: bool, key_hint: string, model: string, base_url: string,
     *     default_url: string, model_hint: string, is_active: int, last_test_at: int, last_test_at_text: string,
     *     last_test_ok: int, last_test_message: string}>
     */
    public function providers(): array
    {
        $rows = [];
        foreach ($this->db->select('SELECT * FROM %%BOT_LLM_PROVIDERS%%;') as $row) {
            $key = (string) $row['api_key'];
            $rows[$row['provider']] = [
                'provider' => (string) $row['provider'],
                'has_key' => $key !== '',
                'key_hint' => $key !== '' ? '••••' . mb_substr($key, -4) : '',
                'model' => (string) $row['model'],
                'base_url' => (string) $row['base_url'],
                'default_url' => LlmClient::defaultBaseUrl((string) $row['provider']),
                'model_hint' => self::MODEL_HINTS[$row['provider']] ?? '',
                'is_active' => (int) $row['is_active'],
                'last_test_at' => (int) $row['last_test_at'],
                'last_test_at_text' => $row['last_test_at'] ? date('d.m.Y H:i', (int) $row['last_test_at']) : '',
                'last_test_ok' => (int) $row['last_test_ok'],
                'last_test_message' => (string) $row['last_test_message'],
            ];
        }

        $ordered = [];
        foreach (LlmClient::PROVIDERS as $name) {
            if (isset($rows[$name])) {
                $ordered[] = $rows[$name];
            }
        }

        return $ordered;
    }

    /**
     * Saves one provider row. $apiKey null or '' keeps the stored key. Activating a provider
     * deactivates all others (the caller decides which one is active; false here only clears this row).
     *
     * @throws LlmException on unknown provider or invalid base URL
     */
    public function saveProvider(string $provider, ?string $apiKey, string $model, string $baseUrl, bool $active): void
    {
        if (!in_array($provider, LlmClient::PROVIDERS, true)) {
            throw new LlmException('Unknown provider.');
        }
        $model = mb_substr(trim($model), 0, 128);
        $baseUrl = mb_substr(trim($baseUrl), 0, 255);
        if ($baseUrl !== '' && !preg_match('~^https?://[^/@\s]+~i', $baseUrl)) {
            throw new LlmException('Invalid base URL.');
        }

        if ($active) {
            $this->db->update('UPDATE %%BOT_LLM_PROVIDERS%% SET is_active = 0 WHERE provider <> :p;', [':p' => $provider]);
        }

        $params = [':m' => $model, ':u' => $baseUrl, ':a' => $active ? 1 : 0, ':p' => $provider];
        $key = $apiKey === null ? '' : trim($apiKey);
        if ($key === '' && $this->baseUrlChanged($provider, $baseUrl)) {
            // the stored key must never travel to a different URL: it has to be entered again
            $this->db->update('UPDATE %%BOT_LLM_PROVIDERS%% SET api_key = \'\', is_active = 0 WHERE provider = :p;', [':p' => $provider]);
            $params[':a'] = 0;
        }
        if ($key !== '') {
            $this->db->update(
                'UPDATE %%BOT_LLM_PROVIDERS%% SET api_key = :k, model = :m, base_url = :u, is_active = :a WHERE provider = :p;',
                $params + [':k' => $key],
            );
        } else {
            $this->db->update(
                'UPDATE %%BOT_LLM_PROVIDERS%% SET model = :m, base_url = :u, is_active = :a WHERE provider = :p;',
                $params,
            );
        }
    }

    /** Does `$baseUrl` differ from the stored one (an empty URL means the provider default)? */
    private function baseUrlChanged(string $provider, string $baseUrl): bool
    {
        $stored = (string) $this->db->selectSingle('SELECT `base_url` FROM %%BOT_LLM_PROVIDERS%% WHERE provider = :p;', [':p' => $provider], 'base_url');
        $normalize = static fn(string $url): string => rtrim(trim($url) !== '' ? trim($url) : LlmClient::defaultBaseUrl($provider), '/');

        return $normalize($stored) !== $normalize($baseUrl);
    }

    /** Creates the client; the OpenCode session id is stable per installation (hash of the project path), the referer is this game's URL. */
    private function build(string $provider, string $key, string $model, string $url): LlmClient
    {
        $runtime = $this->state->runtime;
        $referer = $runtime->httpHost !== '' ? $runtime->protocol . $runtime->httpHost . $runtime->httpBase : 'https://v0game.local/';

        return new LlmClient(
            $this->db,
            $this->httpClient,
            $provider,
            $key,
            $model,
            $url,
            'v0game-' . substr(hash('sha256', $runtime->rootPath), 0, 24),
            $referer,
            $this->guard,
        );
    }
}
