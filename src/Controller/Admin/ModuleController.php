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

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Switches the game modules (config `moduls`) of the emulated universe on or off.
 */
class ModuleController extends AbstractAdminController
{
    /** Lists all modules of the emulated universe with their state. */
    #[GameRoute('admin', 'admin/modules', 'module')]
    public function show(): Response
    {
        return $this->renderModules();
    }

    /** Turns the module `id` on in the config (`moduls`), saves it and clears the caches. */
    #[GameRoute('admin', 'admin/modules/{id}/activate', 'module', 'activate', ['POST'], ['id' => '\d+'])]
    public function activate(): Response
    {
        return $this->switchModule(1);
    }

    /** Turns the module `id` off in the config (`moduls`), saves it and clears the caches. */
    #[GameRoute('admin', 'admin/modules/{id}/deactivate', 'module', 'deactivate', ['POST'], ['id' => '\d+'])]
    public function deactivate(): Response
    {
        return $this->switchModule(0);
    }

    /** Sets the state (1 on, 0 off) of the module `id` in the config, saves it, clears the caches and renders the list. */
    private function switchModule(int $state): Response
    {
        $config = $this->configs->get($this->universe()->getEmulated());
        $module = $config->moduls;
        $module[$this->input()->int('id')] = $state;
        $config->moduls = $module;
        $config->saveModules();
        $this->maintenance()->clearCache();

        return $this->renderModules();
    }

    /** Renders the module list (name and state of every module of the emulated universe, sorted by name). */
    private function renderModules(): Response
    {
        $LNG = $this->state->LNG;
        $module = $this->configs->get($this->universe()->getEmulated())->moduls;

        $Modules = [];
        foreach ($module as $ID => $state) {
            $Modules[$ID] = [
                'name'  => $LNG['modul_' . $ID],
                'state' => $state,
            ];
        }

        asort($Modules);
        return $this->render('admin/ModulePage.html.twig', [
            'Modules'             => $Modules,
            'mod_module'          => $LNG['mod_module'],
            'mod_info'            => $LNG['mod_info'],
            'mod_active'          => $LNG['mod_active'],
            'mod_deactive'        => $LNG['mod_deactive'],
            'mod_change_active'   => $LNG['mod_change_active'],
            'mod_change_deactive' => $LNG['mod_change_deactive'],
        ]);
    }
}
