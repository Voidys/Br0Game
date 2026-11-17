<?php

namespace App\Controller;

/** assertModuleAvailable() for controllers that implement ModuleGuard (see RequireModule). */
trait GuardsModule
{
    public function assertModuleAvailable(): void
    {
        $requireModule = (new \ReflectionClass(static::class))->getAttributes(RequireModule::class)[0] ?? null;
        if ($requireModule !== null && !$this->viewHelpers()->isModuleAvailable(constant($requireModule->newInstance()->module))) {
            $this->printPageError($this->state->LNG['sys_module_inactive']);
        }
    }

    /** Error page of the area; must end the request. */
    abstract protected function printPageError(string $message): never;
}
