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

function ValidateAddress($address)
{
    return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
}

function pretty_fly_time($seconds)
{
    $hour = floor($seconds / 3600);
    $minute = floor($seconds / 60);
    $minute = $minute % 60;
    $second = floor($seconds);
    $second = $second % 60;

    return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
}

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

function GetStartAddressLink($FleetRow, $FleetType = '')
{
    return '<a href="game?page=galaxy&amp;galaxy=' . $FleetRow['start_galaxy'] . '&amp;system='
        . $FleetRow['start_system'] . '" class="' . $FleetType . '">[' . $FleetRow['start_galaxy'] . ':'
        . $FleetRow['start_system'] . ':' . $FleetRow['start_planet'] . ']</a>';
}

function GetTargetAddressLink($FleetRow, $FleetType = '')
{
    return '<a href="game?page=galaxy&amp;galaxy=' . $FleetRow['end_galaxy'] . '&amp;system='
        . $FleetRow['end_system'] . '" class="' . $FleetType . '">[' . $FleetRow['end_galaxy'] . ':'
        . $FleetRow['end_system'] . ':' . $FleetRow['end_planet'] . ']</a>';
}

function BuildPlanetAddressLink($CurrentPlanet)
{
    return '<a href="game?page=galaxy&amp;galaxy=' . $CurrentPlanet['galaxy'] . '&amp;system='
        . $CurrentPlanet['system'] . '">[' . $CurrentPlanet['galaxy'] . ':' . $CurrentPlanet['system'] . ':'
        . $CurrentPlanet['planet'] . ']</a>';
}

function makebr($text)
{
    return nl2br($text, false);
}

function floatToString($number, $Pro = 0, $output = false)
{
    if ($output) {
        return str_replace(",", ".", sprintf("%." . $Pro . "f", $number));
    }
    return sprintf("%." . $Pro . "f", $number);
}

function getNumber($from, $default = 0)
{
    return is_numeric($from) ? $from : $default;
}

function getRandomString()
{
    return md5(uniqid());
}

function isVacationMode($USER)
{
    return ($USER['urlaubs_modus'] == 1) ? true : false;
}


/*
 * Handler for exceptions
 *
 * @param object
 * @return Exception
 */

/*
 *
 * @throws ErrorException
 *
 * @return bool If its an hidden error.
 *
 */

// "workaround" for PHP version pre 5.3.0
if (!function_exists('array_replace_recursive')) {
    function array_replace_recursive()
    {
        if (!function_exists('recurse')) {
            function recurse($array, $array1)
            {
                foreach ($array1 as $key => $value) {
                    // create new key in $array, if it is empty or not an array
                    if (!isset($array[$key]) || (isset($array[$key]) && !is_array($array[$key]))) {
                        $array[$key] = [];
                    }

                    // overwrite the value in the base array
                    if (is_array($value)) {
                        $value = recurse($array[$key], $value);
                    }
                    $array[$key] = $value;
                }
                return $array;
            }
        }

        // handle the arguments, merge one by one
        $args = func_get_args();
        $array = $args[0];
        if (!is_array($array)) {
            return $array;
        }
        $count = count($args);
        for ($i = 1; $i < $count; ++$i) {
            if (is_array($args[$i])) {
                $array = recurse($array, $args[$i]);
            }
        }
        return $array;
    }
}

function compressToBase64($string)
{

    // gzip-Format (wie .gz). Alternative: gzdeflate() / gzcompress()
    $compressed = gzencode($string, 9); // Level 1-9
    if ($compressed === false) {
        throw new RuntimeException('gzencode failed');
    }

    return base64_encode($compressed);
}

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