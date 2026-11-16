<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

/**
 * Time zones for the settings dropdown, grouped by continent (only region identifiers).
 * @return array<string, array<string, string>> continent => [identifier => "City" or "Region - City"].
 */
function get_timezone_selector()
{
    // New Timezone Selector, better support for changes in tzdata (new russian timezones, e.g.)
    // http://www.php.net/manual/en/datetimezone.listidentifiers.php

    $timezones = [];
    $timezone_identifiers = DateTimeZone::listIdentifiers();

    foreach ($timezone_identifiers as $value) {
        if (preg_match('/^(America|Antartica|Arctic|Asia|Atlantic|Europe|Indian|Pacific|Australia)\//', $value)) {
            $ex = explode('/', $value); //obtain continent,city
            $city = isset($ex[2]) ? $ex[1] . ' - ' . $ex[2] : $ex[1]; //in case a timezone has more than one
            $timezones[$ex[0]][$value] = str_replace('_', ' ', $city);
        }
    }
    return $timezones;
}

/**
 * True if the string is a valid e-mail address.
 */
function ValidateAddress($address)
{
    return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Duration as HH:MM:SS (hours not wrapped at 24).
 * @param int|float $seconds duration in seconds.
 */
function pretty_fly_time($seconds)
{
    $hour = floor($seconds / 3600);
    $minute = floor($seconds / 60);
    $minute = $minute % 60;
    $second = floor($seconds);
    $second = $second % 60;

    return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
}

/**
 * Remaining time for countdowns as e.g. "1d 02h 03m" (units Y M W d h m s; below days the numbers are zero-padded). Rounds up so the shown second only changes when it has really elapsed (like the JS countdown). With $shorten at most the three largest non-zero units are shown.
 * @param int|float $seconds
 */
function getRestTimeFormat($seconds, $shorten = false)
{
    // ceil keeps the displayed second until it actually elapses, matching the JS countdown
    $seconds = max(0, (int) ceil($seconds - 1e-9));
    $timethresh = [31536000, 2592000, 604800, 86400, 3600, 60, 1]; // y,m,w,d,h,m,s
    $ax = [0, 0, 0, 0, 0, 0, 0];
    $fmt = ["Y", "M", "W", "d", "h", "m", "s"];
    $longest = -1;
    foreach ($timethresh as $t => $v) {
        $inside = floor($seconds / $v);
        if ($inside == 0 && $longest == $t - 1) {
            $longest = $t;
        }
        $seconds -= $inside * $v;
        $ax[$t] = $inside;
    }
    $rstr = "";
    $longest = $longest + 1;
    for ($i = $longest; $i < 7 && ($i - $longest < 3 || $shorten != true); $i++) {
        if ($i > 3) {
            $rstr = $rstr . " " . sprintf('%02d', $ax[$i]) . $fmt[$i] . " ";
        } else {
            $rstr = $rstr . " " . $ax[$i] . $fmt[$i] . " ";
        }
    }
    return trim($rstr);
}

/**
 * HTML link "[g:s:p]" to the galaxy view of the fleet's start coordinates.
 * @param array $FleetRow fleet row (start_galaxy, start_system, start_planet).
 * @param string $FleetType CSS class of the link (mission style).
 */
function GetStartAddressLink($FleetRow, $FleetType = '')
{
    return '<a href="game?page=galaxy&amp;galaxy=' . $FleetRow['start_galaxy'] . '&amp;system='
        . $FleetRow['start_system'] . '" class="' . $FleetType . '">[' . $FleetRow['start_galaxy'] . ':'
        . $FleetRow['start_system'] . ':' . $FleetRow['start_planet'] . ']</a>';
}

/**
 * HTML link "[g:s:p]" to the galaxy view of the fleet's target coordinates (end_* columns).
 */
function GetTargetAddressLink($FleetRow, $FleetType = '')
{
    return '<a href="game?page=galaxy&amp;galaxy=' . $FleetRow['end_galaxy'] . '&amp;system='
        . $FleetRow['end_system'] . '" class="' . $FleetType . '">[' . $FleetRow['end_galaxy'] . ':'
        . $FleetRow['end_system'] . ':' . $FleetRow['end_planet'] . ']</a>';
}

/**
 * HTML link "[g:s:p]" to the galaxy view of a planet row.
 */
function BuildPlanetAddressLink($CurrentPlanet)
{
    return '<a href="game?page=galaxy&amp;galaxy=' . $CurrentPlanet['galaxy'] . '&amp;system='
        . $CurrentPlanet['system'] . '">[' . $CurrentPlanet['galaxy'] . ':' . $CurrentPlanet['system'] . ':'
        . $CurrentPlanet['planet'] . ']</a>';
}

/**
 * Converts line breaks to <br> (text is not escaped here).
 */
function makebr($text)
{
    return nl2br($text, false);
}

/**
 * Number as plain decimal string without exponent (sprintf), for storing big amounts.
 * @param int $Pro decimals.
 * @param bool $output true: force a '.' as decimal separator (sprintf with a comma locale).
 */
function floatToString($number, $Pro = 0, $output = false)
{
    if ($output) {
        return str_replace(",", ".", sprintf("%." . $Pro . "f", $number));
    }
    return sprintf("%." . $Pro . "f", $number);
}

/**
 * The value if numeric, else $default.
 */
function getNumber($from, $default = 0)
{
    return is_numeric($from) ? $from : $default;
}

/** 128 bit random hex token (reset/verification keys, fleet tokens); random_bytes(), not predictable */
function getRandomString()
{
    return bin2hex(random_bytes(16));
}

/**
 * True if the player row has vacation mode on.
 */
function isVacationMode($USER)
{
    return ($USER['urlaubs_modus'] == 1) ? true : false;
}


/**
 * gzip (level 9) and base64 encode, used to store large combat reports. @throws RuntimeException if compression fails.
 */
function compressToBase64($string)
{

    // gzip-Format (wie .gz). Alternative: gzdeflate() / gzcompress()
    $compressed = gzencode($string, 9); // Level 1-9
    if ($compressed === false) {
        throw new RuntimeException('gzencode failed');
    }

    return base64_encode($compressed);
}

/**
 * Reverse of compressToBase64(). @throws RuntimeException if the text is not valid base64 or gzip.
 */
function decompressFromBase64($string)
{
    $compressed = base64_decode($string);
    if ($compressed === false) {
        throw new RuntimeException('base64_decode failed');
    }

    $decompressed = gzdecode($compressed);
    if ($decompressed === false) {
        throw new RuntimeException('gzdecode failed');
    }

    return $decompressed;
}