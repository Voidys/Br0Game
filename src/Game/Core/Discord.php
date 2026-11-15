<?php

namespace App\Game\Core;

use Throwable;

class Discord
{
    /** no database on purpose: reports errors even when the database is down */
    public function __construct(
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    public function sendException(Throwable $exception): void
    {
        $config = $this->configs->get();

        if (!isset($config->discord_exceptions_hook) || empty($config->discord_exceptions_hook)) {
            return;
        }

        $USER = & $this->state->USER;
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

    private function send(string $webHookUrl, string $message): void
    {
        $json_data = json_encode([
            'content' => $message,
            'username' => (string) $this->configs->get()->game_name,
            'tts' => false,
            'embeds' => []

        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ch = curl_init($webHookUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-type: application/json']);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_exec($ch);
        curl_close($ch);
    }
}
