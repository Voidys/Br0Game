<?php

namespace App\Controller\Shared;

use App\Game\Core\EndOfRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Error page of an area whose base class has messageResponse() (login, account): shows a message in the area's layout.
 * Built with `new` by pages that refuse a request (errorResponse(), returned by the action) or by the entry controller
 * fallbacks (showError(), ends the request). There is no route of its own.
 */
trait ErrorResponsePage
{
    /**
     * Message page of the area as Response (initializes the controller first, it was built with `new`).
     *
     * @param array{0: string, 1: int}|null $redirect [url, seconds] automatic redirect
     */
    public function errorResponse(string|\Twig\Markup $Message, bool $fullSide = true, ?array $redirect = null): Response
    {
        $this->initialize();

        return $this->messageResponse($Message, null, $redirect, $fullSide);
    }

    /**
     * Ends the request with the message page: for code outside the action (entry controller fallbacks).
     *
     * @param array{0: string, 1: int}|null $redirect
     */
    public function showError(string|\Twig\Markup $Message, bool $fullSide = true, ?array $redirect = null): never
    {
        throw EndOfRequest::withResponse($this->errorResponse($Message, $fullSide, $redirect));
    }
}
