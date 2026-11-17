<?php

namespace App\EventListener;

use App\Controller\GameControllerInterface;
use App\Routing\GamePage;
use App\Runtime\GameKernel;
use App\Runtime\GameResponseFactory;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wraps game page controllers: bootstraps the game code for the controller's area and runs the
 * controller through GameResponseFactory, so game helpers that end the request (redirects,
 * error pages) or set native headers/cookies still produce the right Response.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
final class GameControllerListener
{
    public function __construct(
        private readonly GameKernel $kernel,
        private readonly GameResponseFactory $responses,
    ) {}

    /**
     * Replaces the controller of a game page (GameControllerInterface) by a closure that, inside
     * GameResponseFactory::run(), boots the game for the controller's mode and entry script, calls
     * `initialize()` and then the original action. Also exposes the `/uniN` route attribute as REDIRECT_UNI so the
     * game's Universe picks the right universe. Other controllers and sub-requests are untouched.
     */
    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $controller = $event->getController();
        $object = is_array($controller) ? $controller[0] : $controller;
        if (!$object instanceof GameControllerInterface || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        // route attribute `uni` is "uni5/" or ""
        $uni = substr((string) $request->attributes->get('uni'), 3, -1);
        if ($uni !== '' && !$request->server->has('REDIRECT_UNI')) {
            $request->server->set('REDIRECT_UNI', $uni);
        }

        $area = (string) $request->attributes->get('_area');
        $mode = GamePage::MODES[$area];
        $kernel = $this->kernel;
        $responses = $this->responses;
        // over the in-game request budget (RateLimitListener): boot the game for the theme and show the message page instead of the page
        $retryAfter = $request->attributes->get(RateLimitListener::RETRY_AFTER);
        if (is_int($retryAfter) && method_exists($object, 'showRateLimited')) {
            $event->setController(static function () use ($object, $kernel, $responses, $retryAfter, $mode, $area) {
                return $responses->run(static function () use ($object, $kernel, $retryAfter, $mode, $area): void {
                    $kernel->boot($mode, $area);
                    $object->showRateLimited($retryAfter);
                });
            });

            return;
        }
        $event->setController(static function (...$arguments) use ($controller, $object, $kernel, $responses, $mode, $area) {
            return $responses->run(static function () use ($controller, $object, $kernel, $arguments, $mode, $area) {
                $kernel->boot($mode, $area);
                $object->initialize();

                return $controller(...$arguments);
            });
        });
    }
}
