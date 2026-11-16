<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\EndOfRequest;
use App\Game\Core\HTTP;

/**
 * ?mode= dispatch of the former page dispatcher for controllers routed at class level
 * (#[GamePage] on the class): runs the public method named by ?mode= (default "show"),
 * falls back to static::$defaultController, honours static::$requireModule.
 *
 * Only methods declared in the concrete controller class are reachable via ?mode=.
 */
trait PageModeDispatch
{
    /**
     * Route entry of a page controller: checks the module switch of the page ($requireModule, a module id or MODULE_* constant name; an inactive module ends
     * in the error page), reads `mode` (default `show`) and calls that public method. An unknown mode falls back to $defaultController, otherwise the "page does
     * not exist" error. A mode that returns a Response is used as is; modes ending in display(), redirectTo() or sendJSON() end the request with EndOfRequest.
     */
    public function __invoke(): Response
    {
        $LNG = $this->state->LNG;

        $requireModule = property_exists(static::class, 'requireModule') ? static::$requireModule : 0;
        // module constants (MODULE_*) are given by name: they only exist once the game is bootstrapped
        if (is_string($requireModule)) {
            $requireModule = constant($requireModule);
        }
        if ($requireModule !== 0 && !$this->viewHelpers()->isModuleAvailable($requireModule)) {
            $this->printPageError($LNG['sys_module_inactive']);
        }

        $mode = $this->http()->_GP('mode', 'show');
        if (!$this->isPageMode($mode)) {
            $default = property_exists(static::class, 'defaultController') ? static::$defaultController : null;
            if (!is_string($default) || !$this->isPageMode($default)) {
                $this->printPageError($LNG['page_doesnt_exist']);
            }
            $mode = $default;
        }

        $result = $this->{$mode}();

        // Modes end with display()/redirectTo()/sendJSON() (EndOfRequest) or return a Response;
        // a mode that just returns sends whatever it printed.
        if ($result instanceof Response) {
            return $result;
        }
        throw new EndOfRequest();
    }

    /**
     * Whether `mode` may be dispatched to a method of this controller: a public, non-static method declared by the
     * controller itself (not inherited helpers, not magic `__` methods), so the URL can't call arbitrary methods.
     */
    private function isPageMode(string $mode): bool
    {
        if ($mode === '' || str_starts_with($mode, '__') || !method_exists($this, $mode)) {
            return false;
        }
        $method = new \ReflectionMethod($this, $mode);

        return $method->isPublic() && !$method->isStatic() && $method->getDeclaringClass()->getName() === static::class;
    }

    /** Error page of the area; must end the request. */
    abstract protected function printPageError(string $message): never;
}
