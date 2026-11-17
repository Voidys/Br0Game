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

namespace App\Controller\Admin;

use App\Game\Core\Config;
use App\Game\Core\Config\ConfigFieldProcessor;
use App\Game\Core\Log;
use App\Game\Core\Mail;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use App\Game\Core\Config\ConfigFieldSchema;
use Symfony\Component\HttpFoundation\Response;

/** Basic game settings of the emulated universe (schema driven form). */
#[\App\Routing\GamePage('admin', 'configBasic')]
class ConfigBasicController extends AbstractAdminController
{
    /**
     * Basic (global) game settings as a schema driven form (ConfigFieldSchema). Any POST saves the processed fields through Config::saveGlobalKeys
     * and writes an admin log entry (type 3). POST field `test` additionally tests the SMTP connection and changes the label of the test button.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $config = $this->configs->get($this->universe()->getEmulated());

        // Schema laden
        $schema = new ConfigFieldSchema(ConfigFieldSchema::CONFIG_TYPE_BASIC, $this->state, $this->configs);

        if (!empty($this->state->requestPost)) {
            $config_before = ConfigFieldProcessor::createSnapshot($config, $schema);

            $config_after = ConfigFieldProcessor::processPostData($schema->getAllFields(), $this->http(), $config);

            // Apply all config changes at once
            foreach ($config_after as $key => $value) {
                $config->$key = $value;
            }
            $config->saveGlobalKeys();

            // Logging
            $LOG = new Log($this->db, $this->configs, $this->state, 3);
            $LOG->target = 0;
            $LOG->old = ConfigFieldProcessor::maskSecrets($config_before, $schema);
            $LOG->new = ConfigFieldProcessor::maskSecrets($config_after, $schema);
            $LOG->save();
        }

        $configValues = ConfigFieldProcessor::prepareTemplateData($config, $schema);

        $smtpTest = $LNG['button_test'];
        if (isset($this->state->requestPost['test'])) {
            if ($this->mailer()->testConnection()) {
                $smtpTest = $LNG['button_test_positive'];
            } else {
                $smtpTest = $LNG['button_test_negative'];
            }
        }

        // Selector Options + Config Values
        $this->assign([
            'configValues' => $configValues,
            'configSchema' => $schema->getSchema(),
            'smtpTest' => $smtpTest,
        ]);

        return $this->render('admin/ConfigBasic.html.twig');
    }
}
