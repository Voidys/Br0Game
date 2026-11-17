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

namespace App\Game\Cache\Builder;

use Symfony\Component\Yaml\Yaml;
use App\Game\Core\Language;

/**
 * Builds the "language" cache: converts the Symfony translation files translations/<domain>.<lang>.yaml of every
 * language pack into var/game/languages/<lang>-<DOMAIN> array files (read by Language::includeData()) and returns
 * the selectable languages with their metadata (config/languages.yaml).
 */
class LanguageBuildCache implements BuildCacheInterface
{
    /**
     * Writes the language array files for all packs and returns the language list.
     * @return array<string, array<string, mixed>> language code => metadata (name, ...) for packs that declare a name.
     */
    public function buildCache(): array
    {
        if (!is_dir(\App\Runtime\ProjectPaths::CACHE . 'languages/')) {
            mkdir(\App\Runtime\ProjectPaths::CACHE . 'languages/');
        }

        // Selectable languages and their metadata: config/languages.yaml (formerly LANG.cfg per pack)
        $languages	= [];
        foreach ((new \App\Game\Core\LanguageCatalog())->getPacks() as $lang => $metadata) {
            $this->cacheLanguage(\App\Runtime\ProjectPaths::ROOT . 'translations/', $lang);
            if (is_array($metadata) && isset($metadata['name'])) {
                $languages[$lang]	= $metadata;
            }
        }

        return $languages;
    }

    /**
     * Converts all domain files of one language into cache files.
     * @param string $fileDir unused (kept for compatibility); the translations directory of the project is used.
     * @param string $lang language code, e.g. 'de'.
     */
    public function cacheLanguage(string $fileDir, string $lang): void
    {

        // Messages live in Symfony's translations/ dir: <domain>.<lang>.yaml, cached under the
        // former pack file name (e.g. l18n.en.yaml -> cache/languages/en-L18N) for Language::includeData().
        $files = glob(\App\Runtime\ProjectPaths::ROOT . 'translations/*.' . $lang . '.yaml');

        foreach ($files as $file) {
            $name = strtoupper(basename($file, '.' . $lang . '.yaml'));
            $data = Yaml::parseFile($file);
            \App\Game\Cache\Resource\PhpArrayFile::write(\App\Runtime\ProjectPaths::CACHE . 'languages/' . $lang . '-' . $name, $data);
        }
    }
}
