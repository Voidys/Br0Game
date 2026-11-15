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

namespace App\Game\Core;

use ArrayAccess;
use Exception;

class Language implements ArrayAccess
{
    private array $container = [];
    private string $language = '';
    /** texts were added while the config was still loading: "{game_name}" is filled in on first read */
    private bool $gameNamePending = false;

    public function __construct(
        private ConfigRegistry $configs,
        ?string $language = null,
    ) {
        $this->setLanguage($language);
        if ($language === null) {
            $this->getUserAgentLanguage();
        }
    }

    private function getUserAgentLanguage(): void
    {
        if (isset($this->configs->gameState()->requestInput['lang']) && in_array($this->configs->gameState()->requestInput['lang'], $this->catalog()->getAllowedLangs())) {
            (new HTTP($this->configs->gameState()))->sendCookie('lang', $this->configs->gameState()->requestInput['lang'], 2147483647);
            $this->setLanguage($this->configs->gameState()->requestInput['lang']);
            return;
        }

        if (($this->configs->gameState()->runtime->mode === 'LOGIN' || $this->configs->gameState()->runtime->mode === 'INSTALL' || $this->configs->gameState()->runtime->mode === 'UPGRADE') && isset($this->configs->gameState()->requestCookies['lang']) && in_array($this->configs->gameState()->requestCookies['lang'], $this->catalog()->getAllowedLangs())) {
            $this->setLanguage($this->configs->gameState()->requestCookies['lang']);
            return;
        }

        if (empty($this->configs->gameState()->requestServer['HTTP_ACCEPT_LANGUAGE'])) {
            $this->setLanguage(DEFAULT_LANG);
            return;
        }

        $accepted_languages = preg_split('/,\s*/', $this->configs->gameState()->requestServer['HTTP_ACCEPT_LANGUAGE']);

        $language = $this->getLanguage();

        foreach ($accepted_languages as $accepted_language) {
            $isValid = preg_match('!^([a-z]{1,8}(?:-[a-z]{1,8})*)(?:;\s*q=(0(?:\.[0-9]{1,3})?|1(?:\.0{1,3})?))?$!i', $accepted_language, $matches);

            if ($isValid !== 1) {
                continue;
            }

            [$code]	= explode('-', strtolower($matches[1]));

            if (in_array($code, $this->catalog()->getAllowedLangs())) {
                $language	= $code;
                break;
            }
        }

        (new HTTP($this->configs->gameState()))->sendCookie('lang', $language, 2147483647);
        $this->setLanguage($language);
    }

    public function setLanguage(?string $language): void
    {
        if (!is_null($language) && in_array($language, $this->catalog()->getAllowedLangs())) {
            $this->language = $language;
        } elseif ($this->configs->gameState()->runtime->mode !== 'INSTALL') {
            $this->language	= $this->configs->get()->lang;
        } else {
            $this->language	= DEFAULT_LANG;
        }
    }

    public function addData(array $data): void
    {
        $this->container = array_replace_recursive($this->container, $data);
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getTemplate(string $templateName): string
    {
        if (file_exists('templates/texts/'.$this->getLanguage().'/'.$templateName.'.txt')) {
            return file_get_contents('templates/texts/'.$this->getLanguage().'/'.$templateName.'.txt');
        } else {
            return '### Template "'.$templateName.'" on language "'.$this->getLanguage().'" not found! ###';
        }
    }


    public function includeData(array $files): void
    {
        // Fixed BOM problems.
        ob_start();

        //FALLBACK
        $path = $this->configs->gameState()->runtime->cachePath.'languages/';

        // A pack file that is missing although its translations exist (cache cleared or older than a new
        // domain) would leave raw keys in messages written by missions: rebuild the packs once.
        foreach ($files as $file) {
            if (
                \App\Game\Cache\Resource\PhpArrayFile::read($path.'en-'.$file) === null
                && file_exists(\App\Runtime\ProjectPaths::ROOT.'translations/'.strtolower($file).'.en.yaml')
            ) {
                (new \App\Game\Cache\Builder\LanguageBuildCache())->buildCache();
                break;
            }
        }

        foreach ($files as $file) {
            $data = \App\Game\Cache\Resource\PhpArrayFile::read($path.'en-'.$file);
            if ($data !== null) {
                try {
                    $this->addData($this->withGameName($data));
                } catch (Exception $e) {
                    error_log("YAML-Fehler: " . $e->getMessage());
                }
            }
        }

        // Get current client language
        foreach ($files as $file) {
            $data = \App\Game\Cache\Resource\PhpArrayFile::read($path.$this->getLanguage().'-'.$file);
            if ($data !== null) {
                try {
                    $this->addData($this->withGameName($data));
                } catch (Exception $e) {
                    error_log("YAML-Fehler: " . $e->getMessage());
                }
            }
        }

        ob_end_clean();
    }

    /** texts name the game by "{game_name}", filled in from the configured game name */
    private function withGameName(array $data): array
    {
        // the config schema loads texts while the config is built: asking for the config here would recurse
        if (!$this->configs->isLoaded()) {
            $this->gameNamePending = true;

            return $data;
        }

        try {
            $gameName = (string) $this->configs->get()->game_name;
        } catch (\Throwable) {
            return $data;
        }

        $replace = static function (array $items) use (&$replace, $gameName): array {
            foreach ($items as $key => $value) {
                if (is_array($value)) {
                    $items[$key] = $replace($value);
                } elseif (is_string($value) && str_contains($value, '{game_name}')) {
                    $items[$key] = str_replace('{game_name}', $gameName, $value);
                }
            }

            return $items;
        };

        return $replace($data);
    }

    /** ArrayAccess Functions **/
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->container[] = $value;
        } else {
            $this->container[$offset] = $value;
        }
    }
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return isset($this->container[$offset]);
    }
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        unset($this->container[$offset]);
    }
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        if ($this->gameNamePending && $this->configs->isLoaded()) {
            $this->gameNamePending = false;
            $this->container = $this->withGameName($this->container);
        }

        return isset($this->container[$offset]) ? $this->container[$offset] : $offset;
    }

    /** selectable languages (code list, or code => name); see LanguageCatalog */
    public function getAllowedLangs(bool $OnlyKey = true): array
    {
        return $this->catalog()->getAllowedLangs($OnlyKey);
    }

    private function catalog(): LanguageCatalog
    {
        return new LanguageCatalog();
    }
}
