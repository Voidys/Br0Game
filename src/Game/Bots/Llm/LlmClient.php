<?php

namespace App\Game\Bots\Llm;

use App\Game\Core\Database;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One configured LLM provider. Built by LlmClientFactory (not a container service).
 */
class LlmClient
{
    public const PROVIDERS = ['openai', 'claude', 'opencode_go', 'deepseek', 'openrouter'];

    /** Used when no base URL is stored or entered. */
    public const DEFAULT_BASE_URLS = [
        'openai'      => 'https://api.openai.com/v1',
        'claude'      => 'https://api.anthropic.com/v1',
        'opencode_go' => 'https://opencode.ai/zen/go/v1',
        'deepseek'    => 'https://api.deepseek.com',
        'openrouter'  => 'https://openrouter.ai/api/v1',
    ];

    /**
     * @param string $installId stable id of this installation (OpenCode Go session header)
     * @param string $referer   game URL sent to OpenRouter
     */
    public function __construct(
        private readonly Database $db,
        private readonly HttpClientInterface $httpClient,
        private readonly string $provider,
        private readonly string $apiKey,
        private readonly string $model,
        private string $baseUrl,
        private readonly string $installId = 'br0game',
        private readonly string $referer = 'https://br0game.local/',
        private readonly ?LlmUrlGuard $guard = null,
    ) {
        if (trim($this->baseUrl) === '') {
            $this->baseUrl = self::DEFAULT_BASE_URLS[$provider] ?? '';
        }
    }

    /** API base URL used for a provider when none is configured; '' for unknown providers. */
    public static function defaultBaseUrl(string $provider): string
    {
        return self::DEFAULT_BASE_URLS[$provider] ?? '';
    }

    /**
     * Sends one prompt and returns the model's text. POST (JSON) to /messages (claude) or /chat/completions
     * (others); 10 s total, 8 s connect/idle, no redirects. openai gets max_completion_tokens, opencode_go the
     * x-opencode-session header, openrouter referer/title headers.
     *
     * @throws LlmException on missing config, transport error, non-2xx status (HTTP code + provider message,
     *                      API key masked) or an empty/invalid response
     */
    public function complete(string $system, string $prompt, int $maxTokens = 400): string
    {
        if ($this->apiKey === '') {
            throw new LlmException('No API key set.');
        }
        if ($this->model === '') {
            throw new LlmException('No model set.');
        }
        if (!preg_match('~^https?://~i', $this->baseUrl)) {
            throw new LlmException('Invalid base URL.');
        }

        // public hosts only; the client is pinned to the checked addresses (no second lookup)
        $target = ($this->guard ?? new LlmUrlGuard())->check($this->baseUrl);

        [$url, $headers, $body] = $this->buildRequest($system, $prompt, $maxTokens);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'body' => json_encode($body),
                'timeout' => 8, // connect / idle
                'max_duration' => 10,
                'max_redirects' => 0,
                'resolve' => [$target['host'] => $target['ips'][0]],
            ]);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new LlmException('Request failed: ' . $this->clean($e->getMessage() !== '' ? $e->getMessage() : 'transport error'));
        }

        $json = json_decode((string) $raw, true);

        if ($status < 200 || $status >= 300) {
            throw new LlmException($this->errorMessage($status, $json, (string) $raw));
        }

        if (!is_array($json)) {
            throw new LlmException('Invalid JSON response.');
        }

        $text = '';
        if ($this->provider === 'claude') {
            foreach ((array) ($json['content'] ?? []) as $block) {
                if (is_array($block) && isset($block['text'])) {
                    $text .= $block['text'];
                }
            }
        } else {
            $text = (string) ($json['choices'][0]['message']['content'] ?? '');
        }

        $text = trim($text);
        if ($text === '') {
            throw new LlmException('Empty response.');
        }

        return $text;
    }

    /**
     * The provider specific request: URL (/messages for claude, /chat/completions otherwise), headers (API key, version,
     * session or referer headers) and body (claude: system field and max_tokens; others: system message; openai gets
     * max_completion_tokens as its newer models only accept that).
     *
     * @return array{0: string, 1: array<string, string>, 2: array<string, mixed>} url, headers, body
     */
    private function buildRequest(string $system, string $prompt, int $maxTokens): array
    {
        $base = rtrim($this->baseUrl, '/');
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        if ($this->provider === 'claude') {
            $url = $base . '/messages';
            $headers['x-api-key'] = $this->apiKey;
            $headers['anthropic-version'] = '2023-06-01';
            $body = [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ];
        } else {
            $url = $base . '/chat/completions';
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
            if ($this->provider === 'opencode_go') {
                // OpenCode Go routes requests by session; one stable id per installation.
                $headers['x-opencode-session'] = $this->installId;
            }
            if ($this->provider === 'openrouter') {
                $headers['HTTP-Referer'] = $this->referer;
                $headers['X-Title'] = 'pr0game';
            }
            $body = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ];
            // OpenAI's newer models only accept max_completion_tokens.
            $body[$this->provider === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $maxTokens;
        }

        return [$url, $headers, $body];
    }

    /**
     * Error text of a non-2xx answer: the provider's message (error.message, error, message) or the first 150
     * characters of the plain body, with the HTTP status; the API key is masked.
     *
     * @param mixed $json decoded body (null when not JSON)
     */
    private function errorMessage(int $status, mixed $json, string $raw): string
    {
        $msg = '';
        if (is_array($json)) {
            $err = $json['error'] ?? $json['message'] ?? null;
            $msg = is_array($err) ? (string) ($err['message'] ?? json_encode($err)) : (string) $err;
        }
        if ($msg === '') {
            $msg = mb_substr(trim(strip_tags((string) $raw)), 0, 150);
        }

        return 'HTTP ' . $status . ($msg !== '' ? ': ' . $this->clean($msg) : '');
    }

    /**
     * Connectivity check: asks the model for "pong" and stores the outcome (time, ok flag, message) in the provider's
     * bot_llm_providers row. Never throws; failures are reported in the result (`latency_ms` is the round trip).
     *
     * @return array{ok: bool, message: string, response: string, latency_ms: int}
     */
    public function test(): array
    {
        $start = microtime(true);
        $result = ['ok' => false, 'message' => '', 'response' => '', 'latency_ms' => 0];
        try {
            $result['response'] = $this->complete('You are a connectivity test.', 'Reply with exactly the word: pong', 512);
            $result['ok'] = $result['response'] !== '';
            $result['message'] = 'OK';
        } catch (LlmException $e) {
            $result['message'] = $e->getMessage();
        }
        $result['latency_ms'] = (int) round((microtime(true) - $start) * 1000);

        $this->db->update(
            'UPDATE %%BOT_LLM_PROVIDERS%% SET last_test_at = :t, last_test_ok = :ok, last_test_message = :m WHERE provider = :p;',
            [
                ':t' => time(),
                ':ok' => $result['ok'] ? 1 : 0,
                ':m' => mb_substr($result['message'], 0, 255),
                ':p' => $this->provider,
            ],
        );

        return $result;
    }

    /** Strips the API key and control characters from a message. */
    private function clean(string $msg): string
    {
        if ($this->apiKey !== '') {
            $msg = str_replace($this->apiKey, '***', $msg);
        }
        $msg = preg_replace('/\s+/', ' ', $msg);

        return mb_substr(trim($msg), 0, 240);
    }
}
