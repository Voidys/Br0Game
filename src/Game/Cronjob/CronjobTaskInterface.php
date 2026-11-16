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

namespace App\Game\Cronjob;

/**
 * One scheduled task. The class name (without namespace) is stored in `cronjobs.class`; run() does the whole job
 * and may throw (Cronjob::execute() catches, unlocks and reports to Discord).
 */
interface CronjobTaskInterface
{
    /**
     * Executes the task once.
     */
    public function run(): void;
}
