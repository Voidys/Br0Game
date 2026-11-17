<?php

namespace App\Controller\Shared;

/** Error page of an area: shows a message in the area's layout and ends the request. */
trait ErrorPage
{
    public function showError($Message, $fullSide = true, $redirect = null)
    {
        $this->initialize();
        $this->printMessage($Message, null, $redirect, $fullSide);
    }

    public function show() {}
}
