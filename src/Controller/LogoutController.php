<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;

/** Logout path of the firewall (security.yaml); Symfony handles the request before this runs. */
final class LogoutController
{
    #[Route('/logout', name: 'app_logout')]
    public function __invoke(): never
    {
        throw new \LogicException('Handled by the firewall logout.');
    }
}
