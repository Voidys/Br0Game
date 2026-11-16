<?php

namespace App\Controller\Admin;

use App\Controller\GameControllerInterface;
use App\Controller\RendersPages;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\EndOfRequest;

/**
 * Base for the admin area controllers (formerly admin + TwoMoons\Pages\Admin\AbstractAdminPage):
 * players are sent back to the game, the admin password prompt is shown until the session has
 * admin access, admins can emulate a universe (?uni=), and every page except the frame pages
 * requires the matching right (rights are stored under the former class names, ShowXyzPage).
 *
 * Admin pages handle their sub actions themselves; the route calls show(), which returns the
 * rendered page (RendersPages::render(), templates in templates/admin/ include the admin shell via
 * overall_header/overall_footer). message() shows the admin notice page.
 */
abstract class AbstractAdminController implements GameControllerInterface
{
    use \App\Game\Core\GameUtilities;

    use \App\Controller\GameServices;

    use RendersPages;

    /** Pages every admin may open (former class names). */
    private const ALWAYS_ALLOWED = ['ShowIndexPage', 'ShowErrorPage', 'ShowOverviewPage', 'ShowMenuPage', 'ShowTopnavPage'];

    public static function getGameMode(): string
    {
        return 'ADMIN';
    }

    public static function getEntryScript(): string
    {
        return 'admin.php';
    }

    /**
     * Former admin checks, then the page's own constructor work (construct()), in the
     * original order: entry checks -> page constructor -> AbstractAdminPage permission check.
     */
    final public function initialize(): void
    {
        $USER = $this->state->USER;

        if ($USER['authlevel'] == AUTH_USR) {
            $this->http()->redirectTo('game');
        }

        $session = $this->sessions()->create();
        $session->universe = $USER['universe'];

        if ($session->adminAccess != 1) {
            // the former page constructor ran the permission check for the login page too
            $this->checkPermission('ShowLoginPage');
            throw EndOfRequest::withResponse((new LoginController())->withGameServicesOf($this)->show());
        }

        $uni = $this->http()->_GP('uni', 0);
        if ($USER['authlevel'] == AUTH_ADM && !empty($uni)) {
            $this->universe()->setEmulated($uni);
        }

        $this->construct();
    }

    /** Former page constructor; pages that override it call parent::construct() like before. */
    protected function construct(): void
    {
        // the former class name of this page, e.g. MultiIPController -> ShowMultiIPPage
        $short = substr(static::class, strrpos(static::class, '\\') + 1);
        $this->checkPermission('Show' . preg_replace('/Controller$/', '', $short) . 'Page');
    }

    /**
     * Route entry: runs show() and returns its Response; a page that printed its own output (downloads, plain text) ends the request through EndOfRequest.
     */
    public function __invoke(): Response
    {
        $result = $this->show();
        if ($result instanceof Response) {
            return $result;
        }

        // a page that printed its output itself (downloads, plain text)
        throw new EndOfRequest();
    }

    /** the page action: returns the rendered page */
    abstract public function show();

    protected static function templateArea(): string
    {
        return 'admin';
    }

    /** admin shell, clock and theme (AdminShell::pageData()) */
    protected function layoutData(string $template): array
    {
        return (new AdminShell($this->db, $this->configs, $this->state))->pageData($template);
    }

    /**
     * Shows the admin notice page and ends the request.
     *
     * @param string|\Twig\Markup $message plain text is escaped, pass Twig\Markup for HTML
     * @param string|false $redirect URL to continue to after $delay seconds
     */
    protected function message(string|\Twig\Markup $message, string|false $redirect = false, int $delay = 3, bool $fatal = false): never
    {
        throw EndOfRequest::withResponse($this->gameView()->messagePage($message, $redirect, $delay, $fatal));
    }

    /** Ends the request with the admin "permission denied" page (HTTP 403) instead of the error page (503). */
    protected function deny(): never
    {
        $response = $this->gameView()->messagePage($this->state->LNG['adm_permission_error'], false, 3, true);
        $response->setStatusCode(Response::HTTP_FORBIDDEN);

        throw EndOfRequest::withResponse($response);
    }

    /** Only authlevel ADM may use the page (rights, creator of admins, ...): the menu hides it, this enforces it. */
    protected function requireAdmin(): void
    {
        if ((int) $this->state->USER['authlevel'] !== AUTH_ADM) {
            $this->deny();
        }
    }

    /**
     * May the acting admin change the player `$userId` (any universe)? Never user 1 unless the actor is user 1; never
     * a player of equal or higher authlevel (the actor itself is allowed when `$allowSelf`). Unknown ids are allowed
     * (the caller reports "not found").
     */
    protected function mayActOn(int $userId, bool $allowSelf = false): bool
    {
        $USER = $this->state->USER;
        if ((int) $USER['id'] === (int) $userId) {
            return $allowSelf;
        }
        if ($userId === ROOT_USER) {
            return false;
        }
        $level = $this->db->selectSingle('SELECT `authlevel` FROM %%USERS%% WHERE `id` = :id;', [':id' => $userId], 'authlevel');

        return $level === null || $level === false || (int) $level < (int) $USER['authlevel'];
    }

    /** Former AbstractAdminPage constructor check (rights are stored under the former class names). */
    private function checkPermission(string $pageName): void
    {
        if (!in_array($pageName, self::ALWAYS_ALLOWED, true) && !$this->viewHelpers()->allowedTo($pageName)) {
            $this->deny();
        }

        $USER = $this->state->USER;
        if ($USER['authlevel'] == AUTH_USR) {
            $this->deny();
        }
    }
}
