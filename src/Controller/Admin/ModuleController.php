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

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Config;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Switches the game modules (config `moduls`) of the emulated universe on or off.
 */
#[\App\Routing\GamePage('admin.php', 'module')]
#[\App\Routing\GamePage('admin.php', 'module', uniPrefix: true)]
class ModuleController extends AbstractAdminController
{
    /**
     * Module switches of the emulated universe. With mode aktiv/inaktiv and id (module id) the module is turned on or off in the config
     * (`moduls`), saved and the caches cleared; always lists all modules with their state.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $config = $this->configs->get($this->universe()->getEmulated());
        $module = $config->moduls;

        if (isset($this->state->requestQuery['mode'])) {
            $module[$this->http()->_GP('id', 0)] = ($this->state->requestQuery['mode'] == 'aktiv') ? 1 : 0;
            $config->moduls = $module;
            $config->saveModules();
            $this->maintenance()->clearCache();
        }

        foreach ($module as $ID => $state) {
            $Modules[$ID] = [
                'name'  => $LNG['modul_' . $ID],
                'state' => $state,
            ];
        }

        asort($Modules);
        $this->assign([
            'Modules'             => $Modules,
            'mod_module'          => $LNG['mod_module'],
            'mod_info'            => $LNG['mod_info'],
            'mod_active'          => $LNG['mod_active'],
            'mod_deactive'        => $LNG['mod_deactive'],
            'mod_change_active'   => $LNG['mod_change_active'],
            'mod_change_deactive' => $LNG['mod_change_deactive'],
        ]);

        return $this->render('admin/ModulePage.html.twig');
    }
}
