<?php

namespace App\EventListener;

use App\Controller\GameControllerInterface;
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

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $controller = $event->getController();
        $object = is_array($controller) ? $controller[0] : $controller;
        if (!$object instanceof GameControllerInterface || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $uni = $request->attributes->get('uni');
        if ($uni !== null && !$request->server->has('REDIRECT_UNI')) {
            $request->server->set('REDIRECT_UNI', $uni);
        }

        $kernel = $this->kernel;
        $responses = $this->responses;
        $event->setController(static function (...$arguments) use ($controller, $object, $kernel, $responses) {
            return $responses->run(static function () use ($controller, $object, $kernel, $arguments) {
                $kernel->boot($object::getGameMode(), $object::getEntryScript());
                $object->initialize();

                return $controller(...$arguments);
            });
        });
    }
}
