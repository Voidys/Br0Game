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

$planetData	= [
    1	=> ['temp' => random_int(220, 260), 'avgTemp' => 240,	'fields' => random_int(96, 172), 'avgFields' => 134,	'image' => ['trocken' => random_int(1, 10), 'wuesten' => random_int(1, 4)]],
    2	=> ['temp' => random_int(170, 210), 'avgTemp' => 190,	'fields' => random_int(104, 176), 'avgFields' => 140,	'image' => ['trocken' => random_int(1, 10), 'wuesten' => random_int(1, 4)]],
    3	=> ['temp' => random_int(120, 160), 'avgTemp' => 140,	'fields' => random_int(112, 182), 'avgFields' => 140,	'image' => ['trocken' => random_int(1, 10), 'wuesten' => random_int(1, 4)]],
    4	=> ['temp' => random_int(70, 110), 'avgTemp' => 90,	'fields' => random_int(118, 208), 'avgFields' => 163,	'image' => ['dschjungel' => random_int(1, 10)]],
    5	=> ['temp' => random_int(60, 100), 'avgTemp' => 80,	'fields' => random_int(133, 232), 'avgFields' => 182,	'image' => ['dschjungel' => random_int(1, 10)]],
    6	=> ['temp' => random_int(50, 90), 'avgTemp' => 70,	'fields' => random_int(146, 242), 'avgFields' => 194,	'image' => ['dschjungel' => random_int(1, 10)]],
    7	=> ['temp' => random_int(40, 80), 'avgTemp' => 60,	'fields' => random_int(152, 248), 'avgFields' => 200,	'image' => ['normaltemp' => random_int(1, 7)]],
    8	=> ['temp' => random_int(30, 70), 'avgTemp' => 50,	'fields' => random_int(156, 252), 'avgFields' => 204,	'image' => ['normaltemp' => random_int(1, 7)]],
    9	=> ['temp' => random_int(20, 60), 'avgTemp' => 40,	'fields' => random_int(150, 246), 'avgFields' => 198,	'image' => ['normaltemp' => random_int(1, 7), 'wasser' => random_int(1, 9)]],
    10	=> ['temp' => random_int(10, 50), 'avgTemp' => 30,	'fields' => random_int(142, 232), 'avgFields' => 187,	'image' => ['normaltemp' => random_int(1, 7), 'wasser' => random_int(1, 9)]],
    11	=> ['temp' => random_int(0, 40), 'avgTemp' => 20,		'fields' => random_int(136, 210), 'avgFields' => 173,	'image' => ['normaltemp' => random_int(1, 7), 'wasser' => random_int(1, 9)]],
    12	=> ['temp' => random_int(-10, 30), 'avgTemp' => 10,	'fields' => random_int(125, 186), 'avgFields' => 156,	'image' => ['normaltemp' => random_int(1, 7), 'wasser' => random_int(1, 9)]],
    13	=> ['temp' => random_int(-50, -10), 'avgTemp' => -30,	'fields' => random_int(114, 172), 'avgFields' => 143,	'image' => ['eis' => random_int(1, 10)]],
    14	=> ['temp' => random_int(-90, -50), 'avgTemp' => -70,	'fields' => random_int(100, 168), 'avgFields' => 134,	'image' => ['eis' => random_int(1, 10), 'gas' => random_int(1, 8)]],
    15	=> ['temp' => random_int(-130, -90), 'avgTemp' => -110,	'fields' => random_int(90, 164), 'avgFields' => 127,	'image' => ['eis' => random_int(1, 10), 'gas' => random_int(1, 8)]]
];

if (!function_exists('getAllPlanetPictures')) {
    function getAllPlanetPictures(): array
    {
        return ['trocken' => random_int(1, 10), 'wuesten' => random_int(1, 4), 'dschjungel' => random_int(1, 10), 'normaltemp' => random_int(1, 7), 'wasser' => random_int(1, 9), 'eis' => random_int(1, 10), 'gas' => random_int(1, 8)];
    }
}
