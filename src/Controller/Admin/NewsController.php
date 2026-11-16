<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use App\Runtime\SafeFile;
use App\Content\HtmlContent;
use App\Content\ImageProcessor;
use App\Runtime\ProjectPaths;

/**
 * News editor: list, create, edit and delete the news shown on the login page.
 */
#[\App\Routing\GamePage('admin.php', 'news')]
#[\App\Routing\GamePage('admin.php', 'news', uniPrefix: true)]
class NewsController extends AbstractAdminController
{
    /**
     * News editor for the login page. `action` (GET): edit (id; form of an existing item), create (empty form); default is the list, filter = all,
     * published or drafts. POST action=save with id (0 = new), title, summary, text (sanitized rich text), status, pinned, date, optional image upload and
     * remove_image: needs a title and text (otherwise the form is shown again with an error), inserts or updates NEWS, stores or replaces the image under
     * public/uploads/news/ and redirects to the editor with saved=1 (an image error re-renders the form instead). POST action=delete with id removes the
     * item and its image and redirects with deleted=1.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $html = new HtmlContent();
        $now = $this->state->runtime->timestamp;
        $isPost = ($this->state->requestServer['REQUEST_METHOD'] ?? '') === 'POST';
        $post = $this->state->requestPost ?? [];
        $action = (string) $this->http()->_GP('action', '');

        if ($isPost && $action === 'save') {
            return $this->saveNews($html, $post);
        }

        if ($isPost && $action === 'delete') {
            $this->deleteNews($post);
        }

        if ($action === 'edit') {
            $id = (int) $this->http()->_GP('id', 0);
            $row = $this->db->selectSingle("SELECT * FROM %%NEWS%% WHERE id = :id;", [':id' => $id]);
            if (!empty($row)) {
                $content = $row['format'] === 'text' ? $html->fromPlainText($row['text']) : $html->rich($row['text']);
                return $this->renderEditor($id, [
                    'title' => $row['title'], 'summary' => $row['summary'], 'text' => $content,
                    'status' => (int) $row['status'], 'pinned' => (int) $row['pinned'], 'date' => (int) $row['date'], 'image' => (string) $row['image'],
                ], null, $this->http()->_GP('saved', 0) ? $LNG['nws_saved'] : null);
            }
        } elseif ($action === 'create') {
            return $this->renderEditor(0, [
                'title' => '', 'summary' => '', 'text' => '', 'status' => 0, 'pinned' => 0, 'date' => $now, 'image' => '',
            ]);
        }

        return $this->newsList();
    }

    /**
     * POST action=save: reads and sanitizes the form, re-shows the editor with an error when title or text is empty,
     * inserts or updates the NEWS row, applies the image upload/removal and redirects to the editor with saved=1
     * (an image error re-renders the editor instead).
     */
    private function saveNews(HtmlContent $html, array $post): Response
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $now = $this->state->runtime->timestamp;

        $id = (int) ($post['id'] ?? 0);
        $title = mb_substr(trim(strip_tags((string) ($post['title'] ?? ''))), 0, 160);
        $summary = mb_substr(trim(strip_tags((string) ($post['summary'] ?? ''))), 0, 300);
        $text = $html->rich((string) ($post['text'] ?? ''));
        $status = empty($post['status']) ? 0 : 1;
        $pinned = empty($post['pinned']) ? 0 : 1;
        $date = strtotime((string) ($post['date'] ?? '')) ?: $now;

        if ($title === '' || $html->isEmpty($text)) {
            return $this->renderEditor($id, [
                'title' => $title, 'summary' => $summary, 'text' => $text,
                'status' => $status, 'pinned' => $pinned, 'date' => $date,
            ], $LNG['nws_error_empty']);
        }

        $oldImage = $id ? (string) ($db->selectSingle("SELECT image FROM %%NEWS%% WHERE id = :id;", [':id' => $id], 'image') ?? '') : '';

        $id = $this->storeNewsRow($id, $title, $summary, $text, $status, $pinned, $date);

        [$image, $imageError] = $this->applyNewsImage($id, $oldImage, $post);

        if ($imageError !== '') {
            return $this->renderEditor($id, [
                'title' => $title, 'summary' => $summary, 'text' => $text,
                'status' => $status, 'pinned' => $pinned, 'date' => $date, 'image' => $image,
            ], $imageError, $LNG['nws_saved']);
        }
        $this->http()->redirectTo('admin?page=news&action=edit&id=' . $id . '&saved=1');
    }

    /**
     * Inserts a new NEWS row (id 0: author is the acting admin) or updates the existing one (no author change); the
     * format is always `html`.
     *
     * @return int the id of the row
     */
    private function storeNewsRow(int $id, string $title, string $summary, string $text, int $status, int $pinned, int $date): int
    {
        $db = $this->db;
        $USER = $this->state->USER;
        $now = $this->state->runtime->timestamp;

        if ($id === 0) {
            $db->insert("INSERT INTO %%NEWS%% (`user`, `date`, `title`, `summary`, `text`, `status`, `pinned`, `updated_at`, `format`) VALUES (:username, :date, :title, :summary, :text, :status, :pinned, :updated, 'html');", [
                ':username' => $USER['username'],
                ':date' => $date,
                ':title' => $title,
                ':summary' => $summary,
                ':text' => $text,
                ':status' => $status,
                ':pinned' => $pinned,
                ':updated' => $now,
            ]);
            $id = (int) $db->lastInsertId();
        } else {
            $db->update("UPDATE %%NEWS%% SET `title` = :title, `summary` = :summary, `text` = :text, `status` = :status, `pinned` = :pinned, `date` = :date, `updated_at` = :updated, `format` = 'html' WHERE `id` = :id LIMIT 1;", [
                ':title' => $title,
                ':summary' => $summary,
                ':text' => $text,
                ':status' => $status,
                ':pinned' => $pinned,
                ':date' => $date,
                ':updated' => $now,
                ':id' => $id,
            ]);
        }

        return $id;
    }

    /**
     * Applies the image part of the news form: removal (`remove_image`), a new upload (stored under public/uploads/news/,
     * replacing the old file, max width 1600) and the NEWS.image update when it changed. Upload errors are returned,
     * not thrown.
     *
     * @return array{0: string, 1: string} stored image name (empty = none) and the error text (empty = none)
     */
    private function applyNewsImage(int $id, string $oldImage, array $post): array
    {
        $db = $this->db;

        $image = $oldImage;
        $imageError = '';
        $dir = ProjectPaths::ROOT . 'public/uploads/news/';
        $file = $this->state->request->files->get('image');
        if (!empty($post['remove_image']) && $image !== '') {
            $this->removeImage($image);
            $image = '';
        }
        if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE) {
            try {
                $stored = (new ImageProcessor())->storeUpload($file, $dir, 'news-' . $id . '-' . bin2hex(random_bytes(4)), 1600);
                if ($image !== '') {
                    $this->removeImage($image);
                }
                $image = $stored;
            } catch (\RuntimeException $e) {
                $imageError = $e->getMessage();
            }
        }
        if ($image !== $oldImage) {
            $db->update("UPDATE %%NEWS%% SET `image` = :image WHERE `id` = :id LIMIT 1;", [':image' => $image, ':id' => $id]);
        }

        return [$image, $imageError];
    }

    /**
     * POST action=delete: removes the news item with its image and redirects to the list with deleted=1.
     */
    private function deleteNews(array $post): void
    {
        $db = $this->db;

        $delId = (int) ($post['id'] ?? 0);
        $img = (string) ($db->selectSingle("SELECT image FROM %%NEWS%% WHERE id = :id;", [':id' => $delId], 'image') ?? '');
        if ($img !== '') {
            $this->removeImage($img);
        }
        $db->delete("DELETE FROM %%NEWS%% WHERE `id` = :id;", [
            ':id' => $delId,
        ]);
        $this->http()->redirectTo('admin?page=news&deleted=1');
    }

    /**
     * News list (filter: all, published, drafts) with the published/scheduled/draft/pinned flags per item.
     */
    private function newsList(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;
        $now = $this->state->runtime->timestamp;

        $filter = (string) $this->http()->_GP('filter', 'all');
        $where = match ($filter) {
            'published' => 'WHERE `status` = 1',
            'drafts' => 'WHERE `status` = 0',
            default => '',
        };
        if (!in_array($filter, ['published', 'drafts'], true)) {
            $filter = 'all';
        }

        $NewsList = [];
        foreach ($db->select("SELECT * FROM %%NEWS%% $where ORDER BY `date` DESC, `id` DESC;") as $u) {
            $NewsList[] = [
                'id'        => $u['id'],
                'title'     => htmlspecialchars($u['title'], ENT_QUOTES, 'UTF-8'),
                'date'      => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $u['date'], $USER['timezone']),
                'user'      => htmlspecialchars($u['user'], ENT_QUOTES, 'UTF-8'),
                'published' => (int) $u['status'] === 1 && $u['date'] <= $now,
                'scheduled' => (int) $u['status'] === 1 && $u['date'] > $now,
                'draft'     => (int) $u['status'] === 0,
                'pinned'    => (int) $u['pinned'] === 1,
                'confirm'   => $LNG->format('nws_confirm', $u['title']),
            ];
        }

        $this->assign([
            'NewsList'   => $NewsList,
            'newsFilter' => $filter,
            'newsNotice' => $this->http()->_GP('deleted', 0) ? $LNG['nws_deleted'] : null,
        ]);
        return $this->render('admin/NewsPage.html.twig');
    }

    private function renderEditor(int $id, array $data, ?string $error = null, ?string $notice = null): Response
    {
        $LNG = $this->state->LNG;
        $this->assign([
            'editor'      => true,
            'news_id'     => $id,
            'nws_head'    => $id ? $LNG->format('nws_head_edit', htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8')) : $LNG['nws_head_create'],
            'news'        => [
                'title'   => htmlspecialchars($data['title'], ENT_QUOTES, 'UTF-8'),
                'summary' => htmlspecialchars($data['summary'], ENT_QUOTES, 'UTF-8'),
                'text'    => htmlspecialchars($data['text'], ENT_QUOTES, 'UTF-8'),
                'status'  => $data['status'],
                'pinned'  => $data['pinned'],
                'date'    => date('Y-m-d\TH:i', $data['date']),
                'image'   => $data['image'] ?? '',
            ],
            'newsError'   => $error,
            'newsNotice'  => $notice,
        ]);
        return $this->render('admin/NewsPage.html.twig');
    }

    private function removeImage(string $name): void
    {
        $path = ProjectPaths::ROOT . 'public/uploads/news/' . basename($name);
        if (is_file($path)) {
            SafeFile::remove($path);
        }
    }
}
