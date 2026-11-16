<?php

namespace App\Game\Core;

/**
 * The game's error handling (formerly the exceptionHandler()/errorHandler() globals): renders the
 * game error page, logs to var/log/game.log, reports to Discord and, in debug mode, opens a support
 * ticket. Used by GameResponseFactory for exceptions thrown in game controllers and registered by
 * GameBootstrap (register()) as PHP's error/exception handler.
 */
class ErrorReporter
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        // exception details (message, file, trace) are only written into the error page in debug mode
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.debug%')]
        protected bool $debug = false,
    ) {}

    /**
     * Error settings of the game requests: only fatal errors are reported (handleError() turns them
     * into exceptions), logged to var/log/game.log.
     */
    public function register(): void
    {
        error_reporting(E_ERROR);
        // texts with the wrong number of arguments fail loudly in debug, are logged in production
        Language::$strictFormat = $this->debug;
        ini_set('log_errors', '1');
        ini_set('error_log', $this->state->runtime->rootPath . 'var/log/game.log');
        set_exception_handler($this->handleException(...));
        set_error_handler($this->handleError(...));
    }

    public function handleException(\ErrorException|\Throwable $exception): void
    {
        $this->http()->sendHeader('HTTP/1.1 503 Service Unavailable');

        if (method_exists($exception, 'getSeverity')) {
            $errno = $exception->getSeverity();
        } else {
            $errno = E_USER_ERROR;
        }

        $errorType = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_PARSE => 'PARSING ERROR',
            E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'CORE ERROR',
            E_CORE_WARNING => 'CORE WARNING',
            E_COMPILE_ERROR => 'COMPILE ERROR',
            E_COMPILE_WARNING => 'COMPILE WARNING',
            E_USER_ERROR => 'USER ERROR',
            E_USER_WARNING => 'USER WARNING',
            E_USER_NOTICE => 'USER NOTICE',
            E_RECOVERABLE_ERROR => 'RECOVERABLE ERROR',
            E_DEPRECATED => 'DEPRECATED',
            E_USER_DEPRECATED => 'USER DEPRECATED',
            E_ALL => 'ALL',
        ];

        if (file_exists($this->state->runtime->rootPath . 'install/VERSION')) {
            $version = file_get_contents($this->state->runtime->rootPath . 'install/VERSION') . ' (FILE)';
        } else {
            $version = 'UNKNOWN';
        }
        $gameName = '-';

        if ($this->state->runtime->mode !== 'INSTALL') {
            try {
                $config = $this->configs->get();
                $gameName = $config->game_name;
                $version = $config->version;
            } catch (\ErrorException $e) {
                error_log('Error while fetching config: ' . $e->getMessage());
            }
        }


        $DIR = ($this->state->runtime->mode === 'INSTALL' || $this->state->runtime->mode === 'UPGRADE') ? '..' : '.';
        ob_start();
        echo '<!DOCTYPE html>
    <html lang="de">
    <head>
    	<meta charset="utf-8">
    	<title>' . $gameName . ' - Error</title>
    	<link rel="stylesheet" type="text/css" href="' . $DIR . '/styles/resource/css/app/base.css">
    	<link rel="stylesheet" type="text/css" href="' . $DIR . '/styles/theme/nova/theme.css">
    	<link rel="stylesheet" type="text/css" href="' . $DIR . '/styles/resource/css/app/content.css">
    	<link rel="shortcut icon" href="./favicon.ico" type="image/x-icon">
    </head>
    <body id="overview" class="full">

    <div class="error-report">
        <h2>Ein Fehler ist aufgetreten</h2>
        <p>
            Unser Entwicklungs-Team wurde bereits von dem Fehler in Kenntnis gesetzt und wird ihn zeitnah beheben.<br>
            Sofern zusätzliche Informationen zu Verfügung stellen, um den Fehler zu reproduzieren, melde diese bitte im
            Discord-Channel #bugs.
        </p>
        <p>
            This error has been reported to our dev-team and it will be fixed soon.<br>
            In case you are able to provide additional information in order to reproduce this issue, please send us a
            message within our Discord channel #bugs.
        </p>

        <a class="button" href="/game">Zurück zur Startseite</a>
    </div>

    ' . ($this->debug ? '<!--
    Message: ' . str_replace('--', '- -', htmlspecialchars($exception->getMessage())) . '
    File: ' . htmlspecialchars($exception->getFile()) . '
    Line: ' . $exception->getLine() . '
    URL: ' . htmlspecialchars($this->state->runtime->protocol . $this->state->runtime->httpHost . ($this->state->requestServer['REQUEST_URI'] ?? '')) . '
    PHP-Version: ' . PHP_VERSION . '
    PHP-API: ' . php_sapi_name() . '
    Br0Game Version: ' . htmlspecialchars((string) $version) . '

    Debug Backtrace:
    ' . str_replace('--', '- -', makebr(htmlspecialchars($exception->getTraceAsString()))) . '
    -->' : '') . '
    </body>
    </html>';

        if ($this->state->runtime->mode !== 'INSTALL') {
            (new Discord($this->configs, $this->state))->sendException($exception);
        }

        echo str_replace(['\\', $this->state->runtime->rootPath, substr($this->state->runtime->rootPath, 0, 15)], ['/', '/', 'FILEPATH '], ob_get_clean());

        $errorText = date("[d-M-Y H:i:s]", $this->state->runtime->timestamp) . ' ' . $errorType[$errno] . ': "'
            . strip_tags($exception->getMessage()) . "\"\r\n";
        $errorText .= 'File: ' . $exception->getFile() . ' | Line: ' . $exception->getLine() . "\r\n";
        $errorText .= 'URL: ' . $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->requestServer['REQUEST_URI'] . ' | Version: ' . $version . "\r\n";
        $errorText .= "Stack trace:\r\n";
        $errorText .= str_replace(
            $this->state->runtime->rootPath,
            '/',
            htmlspecialchars(str_replace('\\', '/', $exception->getTraceAsString()))
        ) . "\r\n";

        if (is_writable('var/log/game.log')) {
            file_put_contents('var/log/game.log', $errorText, FILE_APPEND);
        }

        /* Debug via Support Ticket */
        $config = $this->configs->get();
        if ($config->debug) {
            $USER = $this->state->USER;
            if (isset($USER) && is_array($USER) && !empty($USER['id']) && !empty($USER['username'])) {
                $ErrSource = $USER['id'];
                $ErrName = $USER['username'];
            } else {
                $ErrSource = 0;
                $ErrName = 'System';
            }

            $ticketObj = new SupportTickets($this->db, $this->configs, $this->state);
            $ticketID = $ticketObj->createTicket($ErrSource, 9, $errorType[$errno]);
            $ticketObj->createAnswer($ticketID, $ErrSource, $ErrName, $errorType[$errno], $errorText, 0);
        }
    }

    /** PHP errors become ErrorExceptions (only those covered by error_reporting()). */
    public function handleError($errno, $errstr, $errfile, $errline): bool
    {
        if (!($errno & error_reporting())) {
            return false;
        }

        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
}
