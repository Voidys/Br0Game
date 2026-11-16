<?php

namespace App\Game\Core;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class Discord
{
    private HttpClientInterface $httpClient;

    /**
     * No database on purpose: reports errors even when the database is down.
     *
     * @param HttpClientInterface|null $httpClient transport; null builds a default client (this class is created
     *                                             with `new` in many places, also from the error reporter)
     */
    public function __construct(
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    public function sendException(Throwable $exception): void
    {
        $config = $this->configs->get();

        if (!isset($config->discord_exceptions_hook) || empty($config->discord_exceptions_hook)) {
            return;
        }

        $USER = $this->state->USER;
        if (isset($USER) && is_array($USER) && !empty($USER['id']) && !empty($USER['username'])) {
            $ErrSource = $USER['id'];
            $ErrName = $USER['username'];
        } else {
            $ErrSource = 1;
            $ErrName = 'System';
        }

        $message = str_replace('<br>', PHP_EOL, $exception->getMessage());

        $this->send(
            $config->discord_exceptions_hook,
            '**' . $message . '**' . PHP_EOL .
            '```' .
            'User-Info: ' . $ErrSource . ' ' . $ErrName . PHP_EOL .
            'File: ' . $exception->getFile() . PHP_EOL .
            'Line: ' . $exception->getLine() . PHP_EOL .
            'URL: ' . $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->requestServer['REQUEST_URI'] . PHP_EOL .
            'Debug Backtrace: ' . PHP_EOL . htmlspecialchars($exception->getTraceAsString()) .
            '```'
        );
    }

    public function sendLog(string $title, ?array $data = null, ?Throwable $exception = null): void
    {
        $config = $this->configs->get();

        if (!isset($config->discord_logs_hook) || empty($config->discord_logs_hook)) {
            return;
        }

        $data = $data ?? (array)$exception;
        $message = '**' . $title . '**' . PHP_EOL . '```';
        foreach ($data ?? [] as $key => $row) {
            if (is_array($row)) {
                $message .= $key . ': ' . json_encode($row) . PHP_EOL;
            } else {
                $message .= $key . ': ' . $row . PHP_EOL;
            }
        }
        $message .= '```';

        $this->send($config->discord_logs_hook, $message);
    }

    /**
     * You can grab the discord IDs by turning on developer mode (settings -> (App-Settings) Advanced), and then right clicking on the relevant channel/user and using "copy ID"
     * <#channel_id>
     * <@user_id>
     * <@&role_id> \@role to get ID
     * <:emojiName:emojiId> \:customEmojiName: to get ID
     */
    public function sendMessage(string $webHookUrl, string $title, array $content): void
    {
        if (!isset($webHookUrl) || empty($webHookUrl) || empty($title) || empty($content)) {
            return;
        }

        $message = '**' . $title . '**' . PHP_EOL . '```';
        foreach ($content ?? [] as $key => $row) {
            if (is_array($row)) {
                $message .= $key . ': ' . json_encode($row) . PHP_EOL;
            } else {
                $message .= $key . ': ' . $row . PHP_EOL;
            }
        }
        $message .= '```';

        $this->send($webHookUrl, $message);
    }

    /**
     * Builds the webhook JSON payload (content, username = game name, tts, embeds).
     *
     * @return string JSON, slashes and unicode unescaped
     */
    public function buildPayload(string $message): string
    {
        return json_encode([
            'content' => $message,
            'username' => (string) $this->configs->get()->game_name,
            'tts' => false,
            'embeds' => [],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * POSTs the message to the webhook (redirects followed, response ignored). Failures are swallowed on purpose:
     * reporting an error must never raise another one.
     */
    private function send(string $webHookUrl, string $message): void
    {
        try {
            $this->httpClient->request('POST', $webHookUrl, [
                'headers' => ['Content-type' => 'application/json'],
                'body' => $this->buildPayload($message),
            ])->getStatusCode();
        } catch (Throwable) {
            // webhook unreachable or invalid: nothing sensible left to report to
        }
    }
}
