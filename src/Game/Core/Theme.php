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

/**
 * Visual theme of the player: an image theme (picture sets) and a CSS theme, both from public/styles/theme/<name>/
 * (style.cfg metadata, settings.cfg layout values such as overview planet rows). Provides URLs, asset lookup via
 * ThemeAssets and the lists of selectable themes.
 */
class Theme
{
    public static $Themes;
    private static $ThemeMeta;
    private $THEMESETTINGS;
    private $skininfo;
    private $skin;
    private $cssSkin;
    private $imageSkin;
    private $template;
    private $customtpls;

    public function __construct()
    {
        $this->skininfo = [];
        $this->cssSkin = DEFAULT_THEME;
        $this->imageSkin = DEFAULT_THEME;
        $this->skin		= DEFAULT_THEME; // the session holds no theme (the former $_SESSION['dpath'] was never set)
        $this->setUserTheme($this->skin);
    }

    public function isHome()
    {
        $this->template		= \App\Runtime\ProjectPaths::ROOT . 'public/styles/home/';
        $this->customtpls	= [];
    }

    /**
     * Selects the image theme (and optionally another CSS theme) if public/styles/theme/<name>/style.cfg exists;
     * returns false for an unknown theme (the current one stays) and loads the theme's layout settings.
     */
    public function setUserTheme($Theme, $cssTheme = null)
    {
        if (!file_exists(\App\Runtime\ProjectPaths::ROOT . 'public/styles/theme/' . $Theme . '/style.cfg')) {
            return false;
        }

        $this->skin		= $Theme;
        $this->cssSkin = $Theme;
        $this->imageSkin = $Theme;

        if ($cssTheme !== null) {
            $this->cssSkin = $cssTheme;
        }
        $this->setStyleSettings();
    }

    public function getTheme()
    {
        return './styles/theme/' . $this->imageSkin . '/';
    }

    /** URL of an image of the image theme (`gebaeude/229.gif`): own folder, artwork sets, common (see ThemeAssets) */
    public function asset(string $path): string
    {
        return ThemeAssets::resolve($this->imageSkin, $path);
    }

    public function getThemeName()
    {
        return $this->skin;
    }

    /**
     * Reads the theme's settings.cfg and merges it over the defaults (overview/fleet rows, short numbers in the top nav).
     */
    public function setStyleSettings()
    {
        $THEMESETTINGS = [];
        if (file_exists(\App\Runtime\ProjectPaths::ROOT . 'public/styles/theme/' . $this->skin . '/settings.cfg')) {
            require(\App\Runtime\ProjectPaths::ROOT . 'public/styles/theme/' . $this->skin . '/settings.cfg');
        }

        $this->THEMESETTINGS	= array_merge([
            'PLANET_ROWS_ON_OVERVIEW' => 2,
            'SHORTCUT_ROWS_ON_FLEET1' => 2,
            'COLONY_ROWS_ON_FLEET1' => 2,
            'ACS_ROWS_ON_FLEET1' => 1,
            'TOPNAV_SHORTLY_NUMBER' => 0,
        ], $THEMESETTINGS);
    }

    public function getStyleSettings()
    {
        return $this->THEMESETTINGS;
    }

    /**
     * The configured default theme if it is an available CSS theme, else DEFAULT_THEME.
     */
    public static function resolveDefaultTheme(?string $configured): string
    {
        if (!empty($configured) && array_key_exists($configured, self::getAvailableCssThemes())) {
            return $configured;
        }
        return DEFAULT_THEME;
    }

    /**
     * CSS themes the player can choose (themes that are not disabled and offer CSS).
     * @return array<string,string> theme folder => display name.
     */
    public static function getAvailableCssThemes()
    {
        $themes = [];
        foreach (self::loadThemeMeta() as $theme => $info) {
            if ($info['cssTheme']) {
                $themes[$theme] = $info['name'];
            }
        }
        return $themes;
    }

    /**
     * Image themes the player can choose.
     * @return array<string,string> theme folder => display name.
     */
    public static function getAvailableImageThemes()
    {
        $themes = [];
        foreach (self::loadThemeMeta() as $theme => $info) {
            if ($info['imageTheme']) {
                $themes[$theme] = $info['name'];
            }
        }
        return $themes;
    }

    public function getThemeCssPath()
    {
        return './styles/theme/' . $this->cssSkin . '/theme.css';
    }

    public function getCssThemeName()
    {
        return $this->cssSkin;
    }

    public function getImageThemeName()
    {
        return $this->imageSkin;
    }

    /**
     * Scans public/styles/theme/<name>/style.cfg once per request; disabled themes are skipped.
     * @return array<string, array{name:string, cssTheme:bool, imageTheme:bool}> by folder.
     */
    private static function loadThemeMeta(): array
    {
        if (self::$ThemeMeta !== null) {
            return self::$ThemeMeta;
        }

        $meta = [];

        $skins = glob(\App\Runtime\ProjectPaths::ROOT . 'public/styles/theme/*/style.cfg');
        foreach ($skins as $cfgPath) {

            $theme = basename(dirname($cfgPath));

            $Skin = [];
            require($cfgPath);
            if (isset($Skin['disabled'])) {
                continue;
            }

            $info = array_merge([
                'name' => $theme,
                'cssTheme' => true,
                'imageTheme' => true,
            ], $Skin);

            $meta[$theme] = [
                'name' => $info['name'],
                'cssTheme' => $info['cssTheme'],
                'imageTheme' => $info['imageTheme'],
            ];
        }

        self::$ThemeMeta = $meta;
        if (!isset(self::$Themes)) {
            $names = [];
            foreach ($meta as $theme => $info) {
                $names[$theme] = $info['name'];
            }
            self::$Themes = $names;
        }

        return self::$ThemeMeta;
    }
}
