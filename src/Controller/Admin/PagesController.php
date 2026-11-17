<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Content\ContentPages;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Editable default pages (contacts, data privacy, rules) per language, see App\Content\ContentPages.
 */
class PagesController extends AbstractAdminController
{
    /** slug => route of the public page */
    private const ROUTES = [
        'contacts' => 'login_disclamer_show',
        'dataPrivacy' => 'login_data_privacy_show',
        'rules' => 'login_rules',
    ];

    private ContentPages $contentPages;

    #[Required]
    public function setContentPages(ContentPages $contentPages): void
    {
        $this->contentPages = $contentPages;
    }

    /** The page list with the editor of the page in the request (`slug`, `lang`) when both are valid. */
    #[GameRoute('admin', 'admin/pages', 'pages')]
    #[GameRoute('admin', 'admin/pages/{slug}/{lang}', 'pages', 'edit', requirements: ['slug' => '\w+', 'lang' => '[\w-]+'])]
    public function show(): Response
    {
        return $this->renderPages('');
    }

    /** POST: stores the submitted title and content of the page `slug` in `lang` (raw, the markup is sanitized on output). */
    #[GameRoute('admin', 'admin/pages/{slug}/{lang}/save', 'pages', 'save', ['POST'], ['slug' => '\w+', 'lang' => '[\w-]+'])]
    public function save(): Response
    {
        return $this->renderPages('save');
    }

    /** POST: resets the page `slug` in `lang` to the default text. */
    #[GameRoute('admin', 'admin/pages/{slug}/{lang}/reset', 'pages', 'reset', ['POST'], ['slug' => '\w+', 'lang' => '[\w-]+'])]
    public function reset(): Response
    {
        return $this->renderPages('reset');
    }

    /**
     * Editor for the CMS pages (contacts, data privacy, rules; ContentPages::SLUGS) per language. Request: slug, lang, $action save
     * or reset (back to the default text); without a valid slug and language shows the page list.
     */
    private function renderPages(string $action): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $languages = $this->contentPages->languages();

        $slug = $this->input()->string('slug', '', true);
        $lang = $this->input()->string('lang', '', true);
        $message = '';

        if (array_key_exists($slug, ContentPages::SLUGS) && isset($languages[$lang])) {
            if ($action === 'save') {
                $this->contentPages->save(
                    $slug,
                    $lang,
                    (string) ($this->state->requestPost['title'] ?? ''), // raw: the input helper would HTML-encode the markup
                    (string) ($this->state->requestPost['content'] ?? ''),
                    (string) $USER['username'],
                );
                $message = $LNG['cms_saved'];
            } elseif ($action === 'reset') {
                $this->contentPages->reset($slug, $lang);
                $message = $LNG['cms_reset_done'];
            }
        } else {
            $slug = $lang = '';
        }

        $pages = [];
        foreach (array_keys(ContentPages::SLUGS) as $pageSlug) {
            $edited = $this->contentPages->edited($pageSlug);
            $rows = [];
            foreach ($languages as $code => $name) {
                $info = $edited[$code] ?? null;
                $rows[] = [
                    'lang' => $code,
                    'name' => $name,
                    'edited' => $info !== null,
                    'date' => $info ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], $info['updated_at'], $USER['timezone']) : '',
                    'by' => $info['updated_by'] ?? '',
                ];
            }
            $pages[] = [
                'slug' => $pageSlug,
                'label' => $LNG['cms_page_' . $pageSlug],
                'url' => $this->router->generate(self::ROUTES[$pageSlug]),
                'rows' => $rows,
            ];
        }

        $vars = [
            'pages' => $pages,
            'message' => $message,
        ];

        if ($slug !== '') {
            $page = $this->contentPages->findExact($slug, $lang);
            $vars += [
                'edit' => [
                    'slug' => $slug,
                    'lang' => $lang,
                    'langName' => $languages[$lang],
                    'label' => $LNG['cms_page_' . $slug],
                    'url' => $this->router->generate(self::ROUTES[$slug]),
                    'title' => $page['title'],
                    'content' => $page['content'],
                    'edited' => $page['edited'],
                ],
            ];
        }

        return $this->render('admin/PagesPage.html.twig', $vars);
    }
}
