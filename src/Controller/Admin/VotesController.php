<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Community\VoteSites;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Vote sites of the universe the admin currently acts in: list, create, edit, activate and delete the sites, the postback
 * URL and secret of each site, the totals per site and the recent vote log.
 */
class VotesController extends AbstractAdminController
{
    private VoteSites $votes;

    #[Required]
    public function setVoteSites(VoteSites $votes): void
    {
        $this->votes = $votes;
    }

    /** The list of the vote sites with totals and the recent log (`saved`/`deleted` show a notice). */
    #[GameRoute('admin', 'admin/votes', 'votes')]
    public function show(): Response
    {
        return $this->renderList((int) $this->universe()->getEmulated());
    }

    /** The empty form of a new site. */
    #[GameRoute('admin', 'admin/votes/new', 'votes', 'create')]
    public function create(): Response
    {
        return $this->renderEditor($this->emptySite(), []);
    }

    /** The form of the site `id` (`saved` shows a notice); an unknown site shows the list. */
    #[GameRoute('admin', 'admin/votes/{id}/edit', 'votes', 'edit', requirements: ['id' => '\d+'])]
    public function edit(): Response
    {
        $uni = (int) $this->universe()->getEmulated();
        $site = $this->votes->find($this->input()->int('id'));
        if ($site !== null && (int) $site['universe'] === $uni) {
            return $this->renderEditor($site, [], $this->input()->int('saved') !== 0);
        }

        return $this->renderList($uni);
    }

    /**
     * Validates the form (id 0 = new, the site fields and renew_secret); errors show the editor again with the entered values, otherwise the
     * site is stored and the editor of the site opens with saved=1.
     */
    #[GameRoute('admin', 'admin/votes/save', 'votes', 'save', ['POST'])]
    public function save(): Response
    {
        $uni = (int) $this->universe()->getEmulated();
        $post = $this->state->requestPost ?? [];
        $id = $this->input()->int('id');
        $existing = $id > 0 ? $this->votes->find($id) : null;
        if ($id > 0 && ($existing === null || (int) $existing['universe'] !== $uni)) {
            return $this->redirectToRoute('admin_votes_show');
        }

        [$values, $errors] = $this->votes->validate($post);
        if ($errors !== []) {
            $site = array_merge($existing ?? $this->emptySite(), $values, ['id' => $id]);
            $site['rewards'] = json_decode((string) $values['reward'], true);

            return $this->renderEditor($site, $errors);
        }

        $id = $this->votes->save($id, $uni, $values, $this->state->runtime->timestamp, !empty($post['renew_secret']));

        return $this->redirectToRoute('admin_votes_edit', ['id' => $id, 'saved' => 1]);
    }

    /** Flips the active flag of the site `id`. */
    #[GameRoute('admin', 'admin/votes/toggle', 'votes', 'toggle', ['POST'])]
    public function toggle(): Response
    {
        $this->votes->setActive($this->input()->int('id'), (int) $this->universe()->getEmulated(), !empty($this->state->requestPost['active']));

        return $this->redirectToRoute('admin_votes_show', ['saved' => 1]);
    }

    /** Removes the site `id` with its log. */
    #[GameRoute('admin', 'admin/votes/delete', 'votes', 'delete', ['POST'])]
    public function delete(): Response
    {
        $this->votes->delete($this->input()->int('id'), (int) $this->universe()->getEmulated());

        return $this->redirectToRoute('admin_votes_show', ['deleted' => 1]);
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

        return $this->render('admin/VotesPage.html.twig', [
            'editor' => true,
            'site' => $site,
            'voteErrors' => array_map(static fn(string $key): string => $LNG[$key], $errors),
            'voteSaved' => $saved,
            'postbackUrl' => $postbackUrl,
            'resources' => array_keys(VoteSites::RESOURCES),
        ]);
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

        return $this->render('admin/VotesPage.html.twig', [
            'editor' => false,
            'voteSites' => $sites,
            'voteLog' => $log,
            'voteModuleOn' => $this->votes->moduleEnabled($uni),
            'voteNotice' => $this->input()->int('saved') !== 0 ? $LNG['vt_saved'] : ($this->input()->int('deleted') !== 0 ? $LNG['vt_deleted'] : null),
        ]);
    }
}
