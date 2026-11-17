<?php

namespace App\Game\Admin\Creator;

use App\Game\Admin\AdminPageResponder;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base of the tabs of the admin creator (`admin/creator/<tab>`): one class per kind of object to create.
 * Pages and messages go through the AdminPageResponder of the controller.
 */
abstract class CreatorTab
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        protected AdminPageResponder $responder,
    ) {}

    /** Shows the tab form, or creates the posted object. */
    abstract public function handle(): Response;
}
