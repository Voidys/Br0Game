<?php

namespace App\Controller;

/**
 * Controllers whose pages can be switched off by a module (#[RequireModule] on the class): GameControllerListener calls
 * assertModuleAvailable() after initialize() and before the action, for every route of the controller.
 */
interface ModuleGuard
{
    /** Ends the request with the "module inactive" page when the controller's module is off. */
    public function assertModuleAvailable(): void;
}
