<?php

namespace App\Controller\Login;

use App\Content\ContentPages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The rules page (/rules): the text is a CMS page (admin: pages), printed as sanitized HTML.
 */
final class RulesController extends AbstractController
{
    /**
     * Renders the rules CMS page in the request locale as sanitized HTML.
     */
    #[Route('/rules', name: 'login_rules', methods: ['GET'])]
    public function __invoke(Request $request, ContentPages $pages): Response
    {
        $page = $pages->find('rules', $request->getLocale());

        return $this->render('login/rules.html.twig', [
            'page_title' => $page['title'],
            'rules' => $page['content'],
        ]);
    }
}
