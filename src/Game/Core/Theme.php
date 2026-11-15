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
        $this->template		= \App\Runtime\ProjectPaths::ROOT.'public/styles/home/';
        $this->customtpls	= [];
    }

    public function setUserTheme($Theme, $cssTheme = null)
    {
        if (!file_exists(\App\Runtime\ProjectPaths::ROOT.'public/styles/theme/'.$Theme.'/style.cfg')) {
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
        return './styles/theme/'.$this->imageSkin.'/';
    }

    public function getThemeName()
    {
        return $this->skin;
    }

    public function getTemplatePath()
    {
        return \App\Runtime\ProjectPaths::ROOT.'/styles/templates/'.$this->skin.'/';
    }

    public function isCustomTPL($tpl)
    {
        if (!isset($this->customtpls)) {
            return false;
        }

        return in_array($tpl, $this->customtpls);
    }

    public function setStyleSettings()
    {
        if (file_exists(\App\Runtime\ProjectPaths::ROOT.'public/styles/theme/'.$this->skin.'/settings.cfg')) {
            require(\App\Runtime\ProjectPaths::ROOT.'public/styles/theme/'.$this->skin.'/settings.cfg');
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

    public static function resolveDefaultTheme(?string $configured): string
    {
        if (!empty($configured) && array_key_exists($configured, self::getAvailableCssThemes())) {
            return $configured;
        }
        return DEFAULT_THEME;
    }

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
        return './styles/theme/'.$this->cssSkin.'/theme.css';
    }

    public function getCssThemeName()
    {
        return $this->cssSkin;
    }

    public function getImageThemeName()
    {
        return $this->imageSkin;
    }

    private static function loadThemeMeta(): array
    {
        if (self::$ThemeMeta !== null) {
            return self::$ThemeMeta;
        }

        $meta = [];

        $skins = glob(\App\Runtime\ProjectPaths::ROOT.'public/styles/theme/*/style.cfg');
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
