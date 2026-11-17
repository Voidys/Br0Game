<?php

namespace App\Controller\Shared;

use Symfony\Component\HttpFoundation\Response;

/** Logs out and shows the logout popup of the area. */
trait LogoutPage
{
    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('popup');
    }

    public function show(): Response
    {
        $this->state->auth->logout();

        return $this->render(static::templateArea() . '/page.logout.default.html.twig');
    }
}
