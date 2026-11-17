<?php

namespace App\Game\Admin\AccountEditor;

use App\Game\Admin\AdminPageResponder;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base of the tabs of the admin account editor (`admin/accounts/edit/<tab>`): one class per tab, each with the
 * form display and the save of that tab. Pages and messages go through the AdminPageResponder of the controller.
 */
abstract class AccountEditorTab
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        protected AdminPageResponder $responder,
    ) {}

    /** Shows the tab form, or saves the posted changes (writes the admin log, then redirects with a message). */
    abstract public function handle(): Response;
}
