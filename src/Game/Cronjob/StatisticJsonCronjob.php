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

namespace App\Game\Cronjob;

use Exception;
use App\Game\Core\Discord;
use App\Game\Core\Statbuilder;

class StatisticJsonCronjob extends AbstractCronjobTask
{
    public function run(): void
    {
        try {
            $stat = new Statbuilder($this->db, $this->configs, $this->state);
            $stat->generateJson();
        } catch (Exception $exception) {
            $this->discord()->sendLog('StatisticJsonCronjob', null, $exception);
            echo $exception->getMessage();
        }
    }
}
