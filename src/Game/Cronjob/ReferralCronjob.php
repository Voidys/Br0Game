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

use App\Game\Enum\MessageType;
use App\Game\Core\Language;

/**
 * Referral notice (cronjob "referral", off by default; every 30 minutes): tells recruiters when an invited player reached the points threshold.
 */
class ReferralCronjob extends AbstractCronjobTask
{
    /**
     * With ref_active: for every player with ref_bonus = 1 whose total points reached ref_minpoints, clears the
     * flag and sends the recruiter (ref_id) a system message (no resources are granted here).
     */
    public function run(): void
    {
        if ($this->configs->get(ROOT_UNI)->ref_active != 1) {
            return;
        }
        /** @var Language[] $langObjects */
        $langObjects = [];

        $db = $this->db;

        $sql = 'SELECT user.`username`, user.`ref_id`, user.`id`, ref_users.`lang`, user.`universe`
		FROM %%USERS%% user
		INNER JOIN %%USERS_SETTINGS%% as ref_users
		ON ref_users.`user_id` = user.`ref_id`
		INNER JOIN %%STATPOINTS%% as stats
		ON stats.`id_owner` = user.`id` AND stats.`stat_type` = :type AND stats.`total_points` >= :points
		WHERE user.`ref_bonus` = 1;';

        $userArray = $db->select($sql, [
            ':type'     => 1,
            ':points'   => $this->configs->get(ROOT_UNI)->ref_minpoints,
        ]);

        foreach ($userArray as $user) {
            if (!isset($langObjects[$user['lang']])) {
                $langObjects[$user['lang']] = new Language($this->configs, $user['lang']);
                $langObjects[$user['lang']]->includeData(['L18N', 'INGAME', 'TECH', 'CUSTOM']);
            }

            $userConfig = $this->configs->get($user['universe']);

            $LNG = $langObjects[$user['lang']];

            $sql = 'UPDATE %%USERS%% SET `ref_bonus` = 0 WHERE `id` = :userId;';

            $db->update($sql, [':userId' => $user['id']]);

            $Message = $LNG->format(
                'sys_refferal_text',
                $user['username'],
                $this->viewHelpers()->prettyNumber($userConfig->ref_minpoints),
            );
            $this->playerMessenger()->sendMessage(
                $user['ref_id'],
                0,
                $LNG['sys_refferal_from'],
                MessageType::System->value,
                $LNG->format('sys_refferal_title', $user['username']),
                $Message,
                $this->state->runtime->timestamp,
            );
        }

        return;
    }
}
