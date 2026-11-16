<?php

namespace App\Controller\Login;

use Symfony\Component\HttpFoundation\Response;
use App\Runtime\SafeFile;
use App\Content\HtmlContent;
use App\Routing\GamePage;
use App\Runtime\ProjectPaths;

/**
 * ./?page=news: paginated list, ./?page=news&id=N single article.
 */
#[GamePage('index.php', 'news')]
#[GamePage('index.php', 'news', uniPrefix: true)]
final class NewsController extends AbstractLoginController
{
    public static $requireModule = 0;

    private const PER_PAGE = 10;

    /**
     * News list (paged, pinned first) or, with id, one article; unknown ids end in a message. Request: id, p (page).
     * Article texts are rendered through the HTML sanitizer.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $html = new HtmlContent();
        $now = $this->state->runtime->timestamp;

        $id = (int) $this->http()->_GP('id', 0);
        if ($id > 0) {
            $row = $this->db->selectSingle("SELECT * FROM %%NEWS%% WHERE id = :id AND status = 1 AND date <= :now;", [
                ':id' => $id,
                ':now' => $now,
            ]);
            if (empty($row)) {
                $this->printMessage($LNG['news_does_not_exist'], [['label' => $LNG['news_back'], 'url' => './?page=news']]);
            }
            $this->assign([
                'article' => $this->present($row, $html) + ['text' => $this->body($row, $html)],
            ]);
            return $this->render('login/page.news.default.html.twig');
        }

        $total = (int) ($this->db->selectSingle("SELECT COUNT(*) AS n FROM %%NEWS%% WHERE status = 1 AND date <= :now;", [':now' => $now], 'n') ?? 0);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $this->http()->_GP('p', 1)));

        $rows = $this->db->select("SELECT * FROM %%NEWS%% WHERE status = 1 AND date <= :now ORDER BY pinned DESC, date DESC, id DESC LIMIT " . self::PER_PAGE . " OFFSET " . (($page - 1) * self::PER_PAGE) . ";", [':now' => $now]);
        $newsList = [];
        foreach ($rows as $row) {
            $item = $this->present($row, $html);
            $item['teaser'] = htmlspecialchars($row['summary'] !== '' ? $row['summary'] : $html->excerpt($this->body($row, $html), 240), ENT_QUOTES, 'UTF-8');
            $newsList[] = $item;
        }

        $this->assign([
            'newsList' => $newsList,
            'page'     => $page,
            'pages'    => $pages,
        ]);

        return $this->render('login/page.news.default.html.twig');
    }

    private function body(array $row, HtmlContent $html): string
    {
        return $row['format'] === 'text' ? $html->fromPlainText($row['text']) : $html->rich($row['text']);
    }

    private function present(array $row, HtmlContent $html): array
    {
        $LNG = $this->state->LNG;

        return [
            'id'     => (int) $row['id'],
            'title'  => htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'),
            'date'   => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $row['date']),
            'author' => htmlspecialchars($row['user'], ENT_QUOTES, 'UTF-8'),
            'pinned' => (int) $row['pinned'] === 1,
            'image'  => $this->image($row),
        ];
    }

    /** @return array{src: string, width: int, height: int}|null */
    private function image(array $row): ?array
    {
        $name = basename((string) ($row['image'] ?? ''));
        $path = ProjectPaths::ROOT . 'public/uploads/news/' . $name;
        if ($name === '' || !is_file($path) || ($size = SafeFile::imageSize($path)) === false) {
            return null;
        }

        return ['src' => 'uploads/news/' . rawurlencode($name), 'width' => $size[0], 'height' => $size[1]];
    }
}
