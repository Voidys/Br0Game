<?php

declare(strict_types=1);

namespace App\Controller\Game;

use App\Community\VoteSites;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Vote sites: the active sites of the universe with the reward and the cooldown of the player, and the "vote" action
 * that logs the click, pays sites without verification and forwards to the vote site.
 */
#[\App\Controller\RequireModule('MODULE_VOTING')]
class VoteController extends AbstractGameController
{
    private VoteSites $voteSites;

    private ?\App\Security\CspNonce $cspNonce = null;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setVoteSites(VoteSites $voteSites): void
    {
        $this->voteSites = $voteSites;
    }

    /** the response's CSP nonce: the vote forms post to this site and are redirected to the vote sites, which the policy has to allow as form target */
    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setCspNonce(\App\Security\CspNonce $cspNonce): void
    {
        $this->cspNonce = $cspNonce;
    }

    /**
     * The vote page: every active site of the universe with its reward, the vote button or the time left until the next vote, and a notice
     * after a vote (`notice` = rewarded, pending or cooldown).
     */
    #[GameRoute('game', 'game/vote', 'vote')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $now = $this->state->runtime->timestamp;
        $userId = (int) $this->state->USER['id'];

        $sites = [];
        foreach ($this->voteSites->sites((int) $this->state->USER['universe'], true) as $site) {
            $this->cspNonce?->allowSource('form-action', (string) $site['vote_url']);
            $remaining = $this->voteSites->cooldownRemaining($site, $userId, $now);
            $rewards = [];
            foreach ($site['rewards'] as $resource => $amount) {
                if ($amount > 0) {
                    $rewards[] = ['name' => $LNG['tech'][$resource], 'amount' => $amount];
                }
            }
            $sites[] = [
                'id' => $site['id'],
                'name' => $site['name'],
                'image' => $site['image_url'],
                'rewards' => $rewards,
                'postback' => $site['verification'] === VoteSites::VERIFY_POSTBACK,
                'ready' => $remaining === 0,
                'hours' => intdiv((int) ceil($remaining / 60), 60),
                'minutes' => (int) ceil($remaining / 60) % 60,
            ];
        }

        $notice = $this->input()->string('notice');
        return $this->render('game/page.vote.default.html.twig', [
            'voteSites' => $sites,
            'voteNotice' => in_array($notice, ['rewarded', 'pending', 'cooldown'], true) ? $LNG['vt_notice_' . $notice] : null,
        ]);
    }

    /**
     * POST id: the player votes on the site. A site on cooldown (or an unknown/inactive one) sends the player back to the list; otherwise the click is
     * logged with a fresh token, a site without verification pays the reward at once, and the player is redirected (302) to the vote URL of the site
     * with {token} and {userId} filled in. Only POST is routed (the global CSRF layer checks the token).
     */
    #[GameRoute('game', 'game/vote/go', 'vote', 'go', ['POST'])]
    public function go(): Response
    {
        $site = $this->voteSites->find($this->input()->int('id'));
        if ($site === null || (int) $site['active'] !== 1 || (int) $site['universe'] !== (int) $this->state->USER['universe']) {
            return $this->redirectToRoute('game_vote_show');
        }

        $result = $this->voteSites->click($site, (int) $this->state->USER['id'], (string) $this->state->request->getClientIp(), $this->state->runtime->timestamp);
        if ($result['url'] === null) {
            return $this->redirectToRoute('game_vote_show', ['notice' => $result['status'] === 'cooldown' ? 'cooldown' : 'error']);
        }

        return $this->redirectResponse($result['url'], true);
    }
}
