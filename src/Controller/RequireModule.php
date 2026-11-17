<?php

namespace App\Controller;

/**
 * Module switch of a game page (enforced for every route by GameControllerListener, see GuardsModule): the page is only available while the module is active.
 * The MODULE_* constant is given by name, because it only exists once the game is bootstrapped.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class RequireModule
{
    public function __construct(public readonly string $module) {}
}
