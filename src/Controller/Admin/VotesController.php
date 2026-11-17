<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Community\VoteSites;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Vote sites of the universe the admin currently acts in: list, create, edit, activate and delete the sites, the postback
 * URL and secret of each site, the totals per site and the recent vote log.
 */
#[\App\Routing\GamePage('admin', 'votes')]
class VotesController extends AbstractAdminController
{
    private VoteSites $votes;

    #[Required]
    public function setVoteSites(VoteSites $votes): void
    {
        $this->votes = $votes;
    }

    /**
     * Vote site admin. `action` (GET): create (empty form), edit (id). POST action=save (id 0 = new, the site fields and renew_secret) stores a site and
     * redirects with saved=1 (invalid input shows the form again with the errors); POST action=toggle (id) flips the active flag, POST action=delete (id)
     * removes the site with its log. The global CSRF layer checks the token of every POST. Default: the list with totals and the recent log.
     */
    public function show(): Response
    {
        $uni = (int) $this->universe()->getEmulated();
        $action = (string) $this->http()->_GP('action', '');
        $isPost = ($this->state->requestServer['REQUEST_METHOD'] ?? '') === 'POST';
        $post = $this->state->requestPost ?? [];

        if ($isPost && $action === 'save') {
            return $this->saveSite($uni, $post);
        }
        if ($isPost && ($action === 'toggle' || $action === 'delete')) {
            $id = (int) ($post['id'] ?? 0);
            if ($action === 'toggle') {
                $this->votes->setActive($id, $uni, !empty($post['active']));
            } else {
                $this->votes->delete($id, $uni);
            }
            $this->http()->redirectTo('admin?page=votes&' . ($action === 'delete' ? 'deleted=1' : 'saved=1'));
        }

        if ($action === 'edit') {
            $site = $this->votes->find((int) $this->http()->_GP('id', 0));
            if ($site !== null && (int) $site['universe'] === $uni) {
                return $this->renderEditor($site, [], (bool) $this->http()->_GP('saved', 0));
            }
        } elseif ($action === 'create') {
            return $this->renderEditor($this->emptySite(), []);
        }

        return $this->renderList($uni);
    }

    /**
     * POST action=save: validates the form; errors show the editor again with the entered values, otherwise the site is stored and the
     * editor of the site opens with saved=1.
     *
     * @param array<string, mixed> $post
     */
    private function saveSite(int $uni, array $post): Response
    {
        $id = (int) ($post['id'] ?? 0);
        $existing = $id > 0 ? $this->votes->find($id) : null;
        if ($id > 0 && ($existing === null || (int) $existing['universe'] !== $uni)) {
            $this->http()->redirectTo('admin?page=votes');
        }

        [$values, $errors] = $this->votes->validate($post);
        if ($errors !== []) {
            $site = array_merge($existing ?? $this->emptySite(), $values, ['id' => $id]);
            $site['rewards'] = json_decode((string) $values['reward'], true);

            return $this->renderEditor($site, $errors);
        }

        $id = $this->votes->save($id, $uni, $values, $this->state->runtime->timestamp, !empty($post['renew_secret']));
        $this->http()->redirectTo('admin?page=votes&action=edit&id=' . $id . '&saved=1');
    }

    /** @return array<string, mixed> the defaults of a new site */
    private function emptySite(): array
    {
        return [
            'id' => 0, 'name' => '', 'vote_url' => '', 'image_url' => '', 'cooldown_hours' => 24, 'verification' => VoteSites::VERIFY_NONE,
            'active' => 1, 'sort' => 0, 'secret' => '', 'rewards' => array_fill_keys(array_keys(VoteSites::RESOURCES), 0),
        ];
    }

    /**
     * The site form; for a stored site with postback verification it also shows the callback URL with the secret.
     *
     * @param array<string, mixed> $site
     * @param list<string> $errors LNG keys of the validation errors
     */
    private function renderEditor(array $site, array $errors, bool $saved = false): Response
    {
        $LNG = $this->state->LNG;
        $runtime = $this->state->runtime;
        $postbackUrl = $site['id'] > 0 && $site['secret'] !== ''
            ? $runtime->protocol . $runtime->httpHost . '/vote/callback/' . $site['id'] . '?secret=' . $site['secret'] . '&token={token}'
            : '';

        $this->assign([
            'editor' => true,
            'site' => $site,
            'voteErrors' => array_map(static fn(string $key): string => $LNG[$key], $errors),
            'voteSaved' => $saved,
            'postbackUrl' => $postbackUrl,
            'resources' => array_keys(VoteSites::RESOURCES),
        ]);

        return $this->render('admin/VotesPage.html.twig');
    }

    /** The list of the sites with their totals, the recent log and a hint when the module is off. */
    private function renderList(int $uni): Response
    {
        $LNG = $this->state->LNG;
        $timezone = $this->state->USER['timezone'];
        $totals = $this->votes->totals($uni);
        $zero = ['clicks' => 0, 'verified' => 0, 'rewarded' => 0];

        $sites = [];
        foreach ($this->votes->sites($uni) as $site) {
            $sites[] = $site + ['totals' => $totals[(int) $site['id']] ?? $zero];
        }
        $log = [];
        foreach ($this->votes->recent($uni) as $row) {
            $row['time'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], (int) $row['clicked_at'], $timezone);
            $row['state'] = $row['rewarded_at'] !== null ? 'rewarded' : ($row['verified_at'] !== null ? 'verified' : 'pending');
            $log[] = $row;
        }

        $this->assign([
            'editor' => false,
            'voteSites' => $sites,
            'voteLog' => $log,
            'voteModuleOn' => $this->votes->moduleEnabled($uni),
            'voteNotice' => $this->http()->_GP('saved', 0) ? $LNG['vt_saved'] : ($this->http()->_GP('deleted', 0) ? $LNG['vt_deleted'] : null),
        ]);

        return $this->render('admin/VotesPage.html.twig');
    }
}
