<?php

namespace App\Game\Core;

use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the handling of a game request (formerly exit/die).
 *
 * Thrown instead of exit() so the request can finish as a Symfony response. It extends
 * \Error (not \Exception) so the many "catch (Exception $e)" blocks of the game code
 * don't swallow it. The optional output is what exit('...') used to print; a page
 * controller can also attach the finished Response (display(), redirects, messages).
 */
final class EndOfRequest extends \Error
{
    private ?Response $response = null;

    public function __construct(private readonly string|int|null $output = null)
    {
        parent::__construct('End of request');
    }

    public static function withResponse(Response $response): self
    {
        $end = new self();
        $end->response = $response;

        return $end;
    }

    public function getOutput(): string
    {
        return is_string($this->output) ? $this->output : '';
    }

    public function getResponse(): ?Response
    {
        return $this->response;
    }
}
