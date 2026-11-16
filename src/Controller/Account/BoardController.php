<?php

declare(strict_types=1);

namespace App\Controller\Account;

use Symfony\Component\HttpFoundation\Response;
use App\Content\HtmlContent;

/**
 * Built-in community board (reddit style), cross universe: identity is the account.
 * ?page=board               list (sort=hot|new|top, t=all|week, flair=, p=)
 * ?page=board&mode=post&id= post with threaded comments (csort=best|new)
 * Write actions are POST + CSRF token; voting answers JSON.
 * Visitors without login may read (list and post view, account stub with id 0); every other mode
 * sends them to the login.
 */
#[\App\Routing\GamePage('account.php', 'board')]
#[\App\Routing\GamePage('account.php', 'board', uniPrefix: true)]
class BoardController extends AbstractAccountController
{
    private const FLAIRS = ['general', 'guide', 'question', 'suggestion', 'bug', 'offtopic'];
    private const PER_PAGE = 25;
    private const POST_COOLDOWN = 60;
    private const COMMENT_COOLDOWN = 15;
    private const MAX_COMMENTS = 1500;
    private const MAX_BODY = 60000;

    private const GUEST_MODES = ['', 'show', 'post'];

    private ?HtmlContent $htmlContent = null;

    /**
     * Guests may only open the read-only modes (show, post); other modes redirect to the start page.
     * Guests get the public site frame.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->state->LNG->includeData(['INGAME', 'ACCOUNT']);
        if ($this->isGuest()) {
            if (!in_array($this->http()->_GP('mode', 'show'), self::GUEST_MODES, true)) {
                $this->redirectTo('./');
            }
            // visitors get the start page frame (account/layout.site)
            $this->setWindow('site');
        }
    }

    // ------------------------------------------------------------------ views

    /**
     * Post list of the board. Request: sort (hot, new, top, ...), t (time range for top), flair (filter), p (page).
     * Reads BOARD_POSTS with the author names and the vote of the viewing account (BOARD_VOTES).
     */
    public function show(): Response
    {
        $sort = in_array($this->http()->_GP('sort', 'hot'), ['hot', 'new', 'top'], true) ? $this->http()->_GP('sort', 'hot') : 'hot';
        $period = $this->http()->_GP('t', 'all') === 'week' ? 'week' : 'all';
        $flair = $this->http()->_GP('flair', '');
        $flair = in_array($flair, self::FLAIRS, true) ? $flair : '';
        $page = max(1, (int) $this->http()->_GP('p', 1));
        $now = $this->now();
        $filter = $this->postFilter($sort, $period, $flair, $now);
        $order = $this->postOrder($sort);

        $total = (int) $this->db->fetchValue(
            $filter($this->db->qb()->select('COUNT(*) AS c')->from($this->db->table('BOARD_POSTS'), 'p'))
        );
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;

        $rows = $this->postPage($filter, $order, $offset);

        $posts = $this->postRows($rows);

        [$tabs, $periods, $chips] = $this->listControls($sort, $period, $flair);
        $state = ['sort' => $sort, 't' => $period, 'flair' => $flair];

        $this->assign([
            'toplink' => 'account?page=account',
            'topname' => $this->state->LNG['Back'],
            'posts' => $posts,
            'tabs' => $tabs,
            'periods' => $periods,
            'chips' => $chips,
            'total' => $total,
            'pageNo' => $page,
            'pages' => $pages,
            'prevUrl' => $page > 1 ? $this->listUrl($state, ['p' => $page - 1]) : '',
            'nextUrl' => $page < $pages ? $this->listUrl($state, ['p' => $page + 1]) : '',
            'csrf' => $this->csrf(),
            'isMod' => $this->isMod(),
            'isGuest' => $this->isGuest(),
        ]);
        return $this->render('account/page.board.default.html.twig');
    }

    /**
     * Builds the condition adder shared by the count and the page query: not deleted, optional flair and, for "top",
     * the week range.
     *
     * @return \Closure(\Doctrine\DBAL\Query\QueryBuilder): \Doctrine\DBAL\Query\QueryBuilder
     */
    private function postFilter(string $sort, string $period, string $flair, int $now): \Closure
    {
        // optional filters (flair, "top of the week"), shared by the count and the page query
        $filter = static function (\Doctrine\DBAL\Query\QueryBuilder $qb) use ($flair, $sort, $period, $now): \Doctrine\DBAL\Query\QueryBuilder {
            $qb->where('p.deleted = 0');
            if ($flair !== '') {
                $qb->andWhere('p.flair = :flair')->setParameter('flair', $flair);
            }
            if ($sort === 'top' && $period === 'week') {
                $qb->andWhere('p.created_at >= :since')->setParameter('since', $now - 7 * 86400);
            }

            return $qb;
        };

        return $filter;
    }

    /**
     * SQL ORDER BY expression of the sort mode (one of three fixed expressions, never request input): new, top, or
     * "hot" (pinned first, then score decayed by age).
     */
    private function postOrder(string $sort): string
    {
        return match ($sort) {
            'new' => 'p.pinned DESC, p.created_at DESC',
            'top' => 'p.score DESC, p.created_at DESC',
            default => 'p.pinned DESC, (SIGN(p.score) * LOG10(GREATEST(ABS(p.score), 1)) + p.created_at / 45000) DESC',
        };
    }

    /**
     * Reads one page of posts (author name, viewing account's vote) with the filter and order; the id keeps equal rows
     * in a stable order.
     *
     * @return array<int, array<string, mixed>> post rows
     */
    private function postPage(\Closure $filter, string $order, int $offset): array
    {
        // $order is one of three fixed expressions (never request input); the id keeps equal rows in a stable order
        return $this->db->fetchAll(
            $filter(
                $this->db->qb()
                    ->select('p.id', 'p.flair', 'p.title', 'p.score', 'p.comment_count', 'p.pinned', 'p.locked', 'p.created_at', 'a.accountname', 'v.value AS myvote')
                    ->from($this->db->table('BOARD_POSTS'), 'p')
                    ->leftJoin('p', $this->db->table('ACCOUNTS'), 'a', 'a.id = p.account_id')
                    ->leftJoin('p', $this->db->table('BOARD_VOTES'), 'v', 'v.account_id = :me AND v.target_type = 1 AND v.target_id = p.id')
                    ->setParameter('me', $this->accountId())
            )
                ->orderBy($order . ', p.id DESC')
                ->setFirstResult($offset)
                ->setMaxResults(self::PER_PAGE)
        );
    }

    /**
     * Template rows of the posts (counts and flags cast, author and date formatted).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function postRows(array $rows): array
    {
        $posts = [];
        foreach ($rows as $row) {
            $posts[] = [
                'id' => (int) $row['id'],
                'url' => 'account?page=board&mode=post&id=' . (int) $row['id'],
                'title' => $row['title'],
                'flair' => $row['flair'],
                'score' => (int) $row['score'],
                'comments' => (int) $row['comment_count'],
                'pinned' => (bool) $row['pinned'],
                'locked' => (bool) $row['locked'],
                'author' => $this->authorName($row['accountname']),
                'ago' => $this->ago((int) $row['created_at']),
                'date' => $this->fullDate((int) $row['created_at']),
                'myvote' => (int) ($row['myvote'] ?? 0),
            ];
        }

        return $posts;
    }

    /**
     * Sort tabs, "top" period switch and flair chips with their links (each keeps the other list settings).
     *
     * @return array{0: list<array>, 1: list<array>, 2: list<array>} tabs, periods, chips
     */
    private function listControls(string $sort, string $period, string $flair): array
    {
        $state = ['sort' => $sort, 't' => $period, 'flair' => $flair];
        $tabs = [];
        foreach (['hot', 'new', 'top'] as $key) {
            $tabs[] = ['key' => $key, 'url' => $this->listUrl($state, ['sort' => $key]), 'active' => $sort === $key];
        }
        $periods = [];
        if ($sort === 'top') {
            foreach (['all', 'week'] as $key) {
                $periods[] = ['key' => $key, 'url' => $this->listUrl($state, ['t' => $key]), 'active' => $period === $key];
            }
        }
        $chips = [['key' => '', 'url' => $this->listUrl($state, ['flair' => '']), 'active' => $flair === '']];
        foreach (self::FLAIRS as $key) {
            $chips[] = ['key' => $key, 'url' => $this->listUrl($state, ['flair' => $key]), 'active' => $flair === $key];
        }

        return [$tabs, $periods, $chips];
    }

    /**
     * One post with its comment tree. Request: id (post), csort (best or new). Deleted posts and comments are shown as
     * placeholders (moderators and authors see more); an unknown id shows the "not found" message.
     */
    public function post(): Response
    {
        $id = (int) $this->http()->_GP('id', 0);
        $post = $this->loadPost($id);
        if ($post === null) {
            $this->fail($this->state->LNG['board_not_found']);
        }

        $me = $this->accountId();
        $isMod = $this->isMod();
        $csort = $this->http()->_GP('csort', 'best') === 'new' ? 'new' : 'best';
        $deleted = (bool) $post['deleted'];
        $own = (int) $post['account_id'] === $me;

        $vote = (int) ($this->db->selectSingle(
            'SELECT value FROM %%BOARD_VOTES%% WHERE account_id = :me AND target_type = 1 AND target_id = :id;',
            [':me' => $me, ':id' => $id],
            'value',
        ) ?: 0);

        $postView = [
            'id' => $id,
            'title' => $deleted ? '' : $post['title'],
            'flair' => $post['flair'],
            'body' => $deleted ? '' : $this->content()->basic($post['body']),
            'score' => (int) $post['score'],
            'comments' => (int) $post['comment_count'],
            'pinned' => (bool) $post['pinned'],
            'locked' => (bool) $post['locked'],
            'deleted' => $deleted,
            'author' => $this->authorName($post['accountname']),
            'ago' => $this->ago((int) $post['created_at']),
            'date' => $this->fullDate((int) $post['created_at']),
            'edited' => (int) $post['edited_at'] > 0 ? $this->fullDate((int) $post['edited_at']) : '',
            'myvote' => $vote,
            'canEdit' => $own && !$deleted,
            'canDelete' => ($own || $isMod) && !$deleted,
        ];

        $this->assign([
            'toplink' => 'account?page=board',
            'topname' => $this->state->LNG['Back'],
            'post' => $postView,
            'comments' => $this->loadComments($id, $csort, $me, $isMod),
            'csort' => $csort,
            'csortBest' => 'account?page=board&mode=post&id=' . $id . '&csort=best',
            'csortNew' => 'account?page=board&mode=post&id=' . $id . '&csort=new',
            'canComment' => !$deleted && !$post['locked'] && !$this->isGuest(),
            'csrf' => $this->csrf(),
            'isMod' => $isMod,
            'isGuest' => $this->isGuest(),
        ]);
        return $this->render('account/page.board.post.html.twig');
    }

    /**
     * Empty form for a new post.
     */
    public function submit(): Response
    {
        $this->assign([
            'toplink' => 'account?page=board',
            'topname' => $this->state->LNG['Back'],
            'flairs' => self::FLAIRS,
            'editing' => false,
            'form' => ['id' => 0, 'title' => '', 'flair' => '', 'body' => ''],
            'csrf' => $this->csrf(),
        ]);
        return $this->render('account/page.board.submit.html.twig');
    }

    /**
     * Edit form of an own, not deleted post (id); only the author may edit.
     */
    public function editPost(): Response
    {
        $post = $this->loadPost((int) $this->http()->_GP('id', 0));
        if ($post === null || (bool) $post['deleted'] || (int) $post['account_id'] !== $this->accountId()) {
            $this->fail($this->state->LNG['board_not_allowed']);
        }

        $this->assign([
            'toplink' => 'account?page=board&mode=post&id=' . (int) $post['id'],
            'topname' => $this->state->LNG['Back'],
            'flairs' => self::FLAIRS,
            'editing' => true,
            'form' => [
                'id' => (int) $post['id'],
                'title' => $post['title'],
                'flair' => $post['flair'],
                'body' => $this->content()->basic($post['body']),
            ],
            'csrf' => $this->csrf(),
        ]);
        return $this->render('account/page.board.submit.html.twig');
    }

    // ------------------------------------------------------------------ writes (POST)

    /**
     * Creates a post from the form (POST only). Rate limit: one post per POST_COOLDOWN (60 s) per account. Validates
     * title/body/flair through readPostForm(); redirects to the new post.
     */
    public function create()
    {
        $this->requirePost();
        $data = $this->readPostForm();
        $now = $this->now();
        $me = $this->accountId();

        $last = (int) $this->db->selectSingle(
            'SELECT MAX(created_at) AS t FROM %%BOARD_POSTS%% WHERE account_id = :me;',
            [':me' => $me],
            't',
        );
        if ($last > $now - self::POST_COOLDOWN) {
            $this->fail($this->state->LNG->format('board_err_rate_post', self::POST_COOLDOWN - ($now - $last)), 'account?page=board&mode=submit');
        }
        if ($data['error'] !== null) {
            $this->fail($data['error'], 'account?page=board&mode=submit');
        }

        $this->db->insert(
            'INSERT INTO %%BOARD_POSTS%% SET account_id = :me, flair = :flair, title = :title, body = :body,
             created_at = :now, last_activity = :now2;',
            [':me' => $me, ':flair' => $data['flair'], ':title' => $data['title'], ':body' => $data['body'], ':now' => $now, ':now2' => $now],
        );
        $this->redirectTo('account?page=board&mode=post&id=' . $this->db->lastInsertId());
    }

    /**
     * Saves an edited post (POST only, id): only the author, not a deleted post; sets `edited_at`. Redirects to the post.
     */
    public function savePost()
    {
        $this->requirePost();
        $id = (int) $this->http()->_GP('id', 0);
        $post = $this->loadPost($id);
        if ($post === null || (bool) $post['deleted'] || (int) $post['account_id'] !== $this->accountId()) {
            $this->fail($this->state->LNG['board_not_allowed']);
        }
        $data = $this->readPostForm();
        if ($data['error'] !== null) {
            $this->fail($data['error'], 'account?page=board&mode=editPost&id=' . $id);
        }

        $this->db->update(
            'UPDATE %%BOARD_POSTS%% SET flair = :flair, title = :title, body = :body, edited_at = :now WHERE id = :id;',
            [':flair' => $data['flair'], ':title' => $data['title'], ':body' => $data['body'], ':now' => $this->now(), ':id' => $id],
        );
        $this->redirectTo('account?page=board&mode=post&id=' . $id);
    }

    /**
     * Adds a comment to a post (POST only). Request: post_id, parent_id (0 = top level), body. Refused for deleted or
     * locked posts, a parent from another post, an empty/oversized body or within COMMENT_COOLDOWN (15 s) of the last comment.
     * Increments the post's comment counter and its last activity; redirects to the new comment.
     */
    public function comment()
    {
        $this->requirePost();
        $postId = (int) $this->http()->_GP('post_id', 0);
        $parentId = (int) $this->http()->_GP('parent_id', 0);
        $back = 'account?page=board&mode=post&id=' . $postId;
        $post = $this->loadPost($postId);
        if ($post === null || (bool) $post['deleted']) {
            $this->fail($this->state->LNG['board_not_found']);
        }
        if ($post['locked']) {
            $this->fail($this->state->LNG['board_err_locked'], $back);
        }
        if ($parentId > 0) {
            $parent = $this->db->selectSingle(
                'SELECT post_id FROM %%BOARD_COMMENTS%% WHERE id = :id;',
                [':id' => $parentId],
            );
            if (!$parent || (int) $parent['post_id'] !== $postId) {
                $this->fail($this->state->LNG['board_not_found'], $back);
            }
        }

        $body = $this->cleanBody();
        if ($body === null) {
            $this->fail($this->state->LNG['board_err_body'], $back);
        }
        $now = $this->now();
        $me = $this->accountId();
        $last = (int) $this->db->selectSingle(
            'SELECT MAX(created_at) AS t FROM %%BOARD_COMMENTS%% WHERE account_id = :me;',
            [':me' => $me],
            't',
        );
        if ($last > $now - self::COMMENT_COOLDOWN) {
            $this->fail($this->state->LNG->format('board_err_rate_comment', self::COMMENT_COOLDOWN - ($now - $last)), $back);
        }

        $this->db->insert(
            'INSERT INTO %%BOARD_COMMENTS%% SET post_id = :post, parent_id = :parent, account_id = :me, body = :body, created_at = :now;',
            [':post' => $postId, ':parent' => $parentId, ':me' => $me, ':body' => $body, ':now' => $now],
        );
        $commentId = $this->db->lastInsertId();
        $this->db->update(
            'UPDATE %%BOARD_POSTS%% SET comment_count = comment_count + 1, last_activity = :now WHERE id = :id;',
            [':now' => $now, ':id' => $postId],
        );
        $this->redirectTo($back . '#c' . $commentId);
    }

    /**
     * Saves an edited comment (POST only, id); author or moderator only.
     */
    public function saveComment()
    {
        $this->requirePost();
        $id = (int) $this->http()->_GP('id', 0);
        $comment = $this->db->selectSingle('SELECT * FROM %%BOARD_COMMENTS%% WHERE id = :id;', [':id' => $id]);
        if (!$comment || (bool) $comment['deleted'] || (int) $comment['account_id'] !== $this->accountId()) {
            $this->fail($this->state->LNG['board_not_allowed']);
        }
        $back = 'account?page=board&mode=post&id=' . (int) $comment['post_id'];
        $body = $this->cleanBody();
        if ($body === null) {
            $this->fail($this->state->LNG['board_err_body'], $back);
        }
        $this->db->update(
            'UPDATE %%BOARD_COMMENTS%% SET body = :body, edited_at = :now WHERE id = :id;',
            [':body' => $body, ':now' => $this->now(), ':id' => $id],
        );
        $this->redirectTo($back . '#c' . $id);
    }

    /**
     * Soft-deletes a post or comment (POST only). Request: id, type (post or comment). Allowed for the author and
     * moderators. A deleted comment decrements the post's comment counter, a deleted post is also unpinned.
     */
    public function delete()
    {
        $this->requirePost();
        $id = (int) $this->http()->_GP('id', 0);
        $isComment = $this->http()->_GP('type', 'post') === 'comment';
        $me = $this->accountId();

        if ($isComment) {
            $comment = $this->db->selectSingle('SELECT * FROM %%BOARD_COMMENTS%% WHERE id = :id;', [':id' => $id]);
            if (!$comment || ((int) $comment['account_id'] !== $me && !$this->isMod())) {
                $this->fail($this->state->LNG['board_not_allowed']);
            }
            $back = 'account?page=board&mode=post&id=' . (int) $comment['post_id'];
            if (!$comment['deleted']) {
                $this->db->update('UPDATE %%BOARD_COMMENTS%% SET deleted = 1 WHERE id = :id;', [':id' => $id]);
                $this->db->update(
                    'UPDATE %%BOARD_POSTS%% SET comment_count = GREATEST(comment_count - 1, 0) WHERE id = :id;',
                    [':id' => (int) $comment['post_id']],
                );
            }
            $this->redirectTo($back . '#c' . $id);
        }

        $post = $this->loadPost($id);
        if ($post === null || ((int) $post['account_id'] !== $me && !$this->isMod())) {
            $this->fail($this->state->LNG['board_not_allowed']);
        }
        $this->db->update('UPDATE %%BOARD_POSTS%% SET deleted = 1, pinned = 0 WHERE id = :id;', [':id' => $id]);
        $this->redirectTo('account?page=board&mode=post&id=' . $id);
    }

    /**
     * Moderator action on a post (POST only). Request: id, action (pin, unpin, lock, unlock). Moderators only.
     */
    public function moderate()
    {
        $this->requirePost();
        if (!$this->isMod()) {
            $this->fail($this->state->LNG['board_not_allowed']);
        }
        $id = (int) $this->http()->_GP('id', 0);
        $column = match ($this->http()->_GP('action', '')) {
            'pin' => ['pinned', 1],
            'unpin' => ['pinned', 0],
            'lock' => ['locked', 1],
            'unlock' => ['locked', 0],
            default => null,
        };
        if ($column === null || $this->loadPost($id) === null) {
            $this->fail($this->state->LNG['board_not_found']);
        }
        $this->db->updateRows('BOARD_POSTS', [$column[0] => $column[1]], ['id' => $id]);
        $this->redirectTo('account?page=board&mode=post&id=' . $id);
    }

    /**
     * Votes on a post or comment (XHR, CSRF token checked). Request: type (post or comment), id, value (-1, 0 removes, 1).
     * Runs in a transaction: replaces the account's vote and adjusts the score by the difference. Answers JSON
     * {ok, score, value} or {ok: false, error}.
     */
    public function vote()
    {
        if (!$this->checkCsrf()) {
            $this->sendJSON(['ok' => false, 'error' => $this->state->LNG['board_err_csrf']]);
        }
        $type = $this->http()->_GP('type', 'post') === 'comment' ? 2 : 1;
        $id = (int) $this->http()->_GP('id', 0);
        $value = max(-1, min(1, (int) $this->http()->_GP('value', 0)));
        $me = $this->accountId();

        $this->db->startTransaction();
        try {
            $table = $type === 1 ? '%%BOARD_POSTS%%' : '%%BOARD_COMMENTS%%';
            $target = $this->db->selectSingle("SELECT id, deleted FROM $table WHERE id = :id FOR UPDATE;", [':id' => $id]);
            if (!$target || $target['deleted']) {
                $this->db->rollback();
                $this->sendJSON(['ok' => false, 'error' => $this->state->LNG['board_not_found']]);
            }

            $old = (int) ($this->db->selectSingle(
                'SELECT value FROM %%BOARD_VOTES%% WHERE account_id = :me AND target_type = :t AND target_id = :id;',
                [':me' => $me, ':t' => $type, ':id' => $id],
                'value',
            ) ?: 0);

            if ($value === 0) {
                $this->db->delete(
                    'DELETE FROM %%BOARD_VOTES%% WHERE account_id = :me AND target_type = :t AND target_id = :id;',
                    [':me' => $me, ':t' => $type, ':id' => $id],
                );
            } else {
                $this->db->insert(
                    'INSERT INTO %%BOARD_VOTES%% SET account_id = :me, target_type = :t, target_id = :id, value = :v
                     ON DUPLICATE KEY UPDATE value = VALUES(value);',
                    [':me' => $me, ':t' => $type, ':id' => $id, ':v' => $value],
                );
            }
            $delta = $value - $old;
            if ($delta !== 0) {
                $this->db->update("UPDATE $table SET score = score + :d WHERE id = :id;", [':d' => $delta, ':id' => $id]);
            }
            $score = (int) $this->db->selectSingle("SELECT score FROM $table WHERE id = :id;", [':id' => $id], 'score');
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($e instanceof \App\Game\Core\EndOfRequest) {
                throw $e;
            }
            $this->db->rollback();
            $this->sendJSON(['ok' => false, 'error' => 'error']);
        }

        $this->sendJSON(['ok' => true, 'score' => $score, 'value' => $value]);
    }

    // ------------------------------------------------------------------ helpers

    private function content(): HtmlContent
    {
        return $this->htmlContent ??= new HtmlContent();
    }

    private function accountId(): int
    {
        return (int) $this->state->ACCOUNT['id'];
    }

    private function isGuest(): bool
    {
        return $this->accountId() === 0;
    }

    private function isMod(): bool
    {
        return (int) $this->state->ACCOUNT['authlevel'] >= AUTH_MOD;
    }

    private function now(): int
    {
        return (int) $this->state->runtime->timestamp;
    }

    private function csrf(): string
    {
        if ($this->isGuest()) {
            return '';
        }
        $session = $this->sessions()->symfonySession();
        $token = $session->get('board_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            $session->set('board_csrf', $token);
        }

        return $token;
    }

    private function checkCsrf(): bool
    {
        if ($this->isGuest()) {
            return false;
        }
        $known = $this->sessions()->symfonySession()->get('board_csrf');
        $given = $this->http()->post()['csrf'] ?? '';

        return is_string($known) && $known !== '' && is_string($given) && hash_equals($known, $given);
    }

    private function requirePost(): void
    {
        if (!$this->checkCsrf()) {
            $this->fail($this->state->LNG['board_err_csrf']);
        }
    }

    private function fail(string $message, string $back = 'account?page=board'): never
    {
        $this->printMessage($message, [['label' => $this->state->LNG['sys_back'], 'url' => $back]]);
        throw new \App\Game\Core\EndOfRequest();
    }

    /** sanitized body from the posted form, null when empty or too long */
    private function cleanBody(): ?string
    {
        $raw = (string) ($this->http()->post()['body'] ?? '');
        if (strlen($raw) > self::MAX_BODY * 2) {
            return null;
        }
        $clean = $this->content()->basic($raw);
        if ($this->content()->isEmpty($clean) || strlen($clean) > self::MAX_BODY) {
            return null;
        }

        return $clean;
    }

    /** @return array{title:string,flair:string,body:string,error:?string} */
    private function readPostForm(): array
    {
        $post = $this->http()->post();
        $title = trim(preg_replace('/\s+/u', ' ', (string) ($post['title'] ?? '')) ?? '');
        $flair = (string) ($post['flair'] ?? '');
        $flair = in_array($flair, self::FLAIRS, true) ? $flair : '';
        $body = $this->cleanBody();
        $error = null;
        if ($title === '') {
            $error = $this->state->LNG['board_err_title'];
        } elseif (mb_strlen($title) > 200) {
            $error = $this->state->LNG['board_err_title_long'];
        } elseif ($body === null) {
            $error = $this->state->LNG['board_err_body'];
        }

        return ['title' => $title, 'flair' => $flair, 'body' => (string) $body, 'error' => $error];
    }

    private function loadPost(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $row = $this->db->selectSingle(
            'SELECT p.*, a.accountname FROM %%BOARD_POSTS%% p LEFT JOIN %%ACCOUNTS%% a ON a.id = p.account_id WHERE p.id = :id;',
            [':id' => $id],
        );

        return $row ?: null;
    }

    /** flat, depth-first list of the comments with their depth (max visual indent is applied by CSS) */
    private function loadComments(int $postId, string $csort, int $me, bool $isMod): array
    {
        $rows = $this->db->select(
            'SELECT c.*, a.accountname, v.value AS myvote FROM %%BOARD_COMMENTS%% c
             LEFT JOIN %%ACCOUNTS%% a ON a.id = c.account_id
             LEFT JOIN %%BOARD_VOTES%% v ON v.account_id = :me AND v.target_type = 2 AND v.target_id = c.id
             WHERE c.post_id = :post ORDER BY c.id LIMIT ' . self::MAX_COMMENTS . ';',
            [':me' => $me, ':post' => $postId],
        );

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $children = [];
        foreach ($byId as $id => $row) {
            $parent = (int) $row['parent_id'];
            $children[isset($byId[$parent]) ? $parent : 0][] = $id;
        }
        $sorter = static function (int $a, int $b) use ($byId, $csort): int {
            if ($csort === 'best' && $byId[$a]['score'] !== $byId[$b]['score']) {
                return (int) $byId[$b]['score'] <=> (int) $byId[$a]['score'];
            }

            return $csort === 'new' ? $b <=> $a : $a <=> $b;
        };

        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, &$children, $byId, $sorter, $me, $isMod): void {
            $ids = $children[$parent] ?? [];
            usort($ids, $sorter);
            foreach ($ids as $id) {
                $row = $byId[$id];
                $deleted = (bool) $row['deleted'];
                $own = (int) $row['account_id'] === $me;
                $out[] = [
                    'id' => $id,
                    'depth' => $depth,
                    'body' => $deleted ? '' : $this->content()->basic($row['body']),
                    'score' => (int) $row['score'],
                    'deleted' => $deleted,
                    'author' => $deleted ? '' : $this->authorName($row['accountname']),
                    'ago' => $this->ago((int) $row['created_at']),
                    'date' => $this->fullDate((int) $row['created_at']),
                    'edited' => (int) $row['edited_at'] > 0,
                    'myvote' => (int) ($row['myvote'] ?? 0),
                    'canEdit' => $own && !$deleted,
                    'canDelete' => ($own || $isMod) && !$deleted,
                ];
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    private function authorName(?string $name): string
    {
        return $name !== null && $name !== '' ? $name : $this->state->LNG['board_deleted_user'];
    }

    private function listUrl(array $state, array $override): string
    {
        $q = array_merge($state, $override);
        if (!array_key_exists('p', $override)) {
            unset($q['p']);
        }
        if (($q['sort'] ?? 'hot') !== 'top') {
            unset($q['t']);
        }
        $q = array_filter($q, static fn($v) => $v !== '' && $v !== 'all' && $v !== 1);
        if (($q['sort'] ?? '') === 'hot') {
            unset($q['sort']);
        }

        return 'account?' . http_build_query(['page' => 'board'] + $q);
    }

    private function fullDate(int $ts): string
    {
        return $this->viewHelpers()->formatDate($this->state->LNG['php_tdformat'], $ts, $this->state->ACCOUNT['timezone']);
    }

    private function ago(int $ts): string
    {
        $LNG = $this->state->LNG;
        $diff = max(0, $this->now() - $ts);

        return match (true) {
            $diff < 60 => $LNG['board_ago_now'],
            $diff < 3600 => $LNG->format('board_ago_min', intdiv($diff, 60)),
            $diff < 86400 => $LNG->format('board_ago_hours', intdiv($diff, 3600)),
            $diff < 86400 * 30 => $LNG->format('board_ago_days', intdiv($diff, 86400)),
            default => $this->fullDate($ts),
        };
    }
}
