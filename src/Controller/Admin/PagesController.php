<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use App\Content\ContentPages;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Editable default pages (contacts, data privacy, rules) per language, see App\Content\ContentPages.
 */
#[\App\Routing\GamePage('admin.php', 'pages')]
#[\App\Routing\GamePage('admin.php', 'pages', uniPrefix: true)]
class PagesController extends AbstractAdminController
{
    /** slug => public URL */
    private const URLS = [
        'contacts' => './?page=disclamer',
        'dataPrivacy' => './?page=dataPrivacy',
        'rules' => './?page=rules',
    ];

    private ContentPages $contentPages;

    #[Required]
    public function setContentPages(ContentPages $contentPages): void
    {
        $this->contentPages = $contentPages;
    }

    public function show(): Response
    {
        $LNG = &$this->state->LNG;
        $USER = &$this->state->USER;
        $languages = $this->contentPages->languages();

        $slug = (string) $this->http()->_GP('slug', '', true);
        $lang = (string) $this->http()->_GP('lang', '', true);
        $action = (string) $this->http()->_GP('action', '', true);
        $message = '';

        if (array_key_exists($slug, ContentPages::SLUGS) && isset($languages[$lang])) {
            if ($action === 'save') {
                $this->contentPages->save(
                    $slug,
                    $lang,
                    (string) ($this->state->requestPost['title'] ?? ''), // raw: _GP() would HTML-encode the markup
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
                'url' => self::URLS[$pageSlug],
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
                    'url' => self::URLS[$slug],
                    'title' => $page['title'],
                    'content' => $page['content'],
                    'edited' => $page['edited'],
                ],
            ];
        }

        $this->assign($vars);
        return $this->render('admin/PagesPage.html.twig');
    }
}
