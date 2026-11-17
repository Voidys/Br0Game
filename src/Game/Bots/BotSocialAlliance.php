<?php

namespace App\Game\Bots;

use App\Game\Enum\MessageType;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Alliance part of the bot social behaviour: applies to alliances, founds one, accepts or declines applications to the
 * alliance of the bot.
 */
class BotSocialAlliance
{
    use GameUtilities;

    private const APPLY_TEXTS = [
        'de' => ['Hallo, ich wuerde gern bei euch mitmachen. Bin regelmaessig online.', 'Hi, suche eine nette Allianz. Darf ich beitreten?', 'Moin, nehmt ihr noch Leute auf? Bin aktiv.'],
        'en' => ['Hello, I would like to join you. I am online regularly.', 'Hi, looking for a nice alliance. May I join?', 'Hey, are you still taking members? I am active.'],
    ];


    private const WELCOME = [
        'de' => ['Willkommen und viel Spass!', 'Schoen dass du dabei bist.', 'Herzlich willkommen!'],
        'en' => ['Welcome and have fun!', 'Glad to have you.', 'Welcome aboard!'],
    ];


    private const ALLY_WORDS = [
        'Nebula', 'Vanguard', 'Phoenix', 'Legion', 'Titans', 'Wolves', 'Order', 'Syndicate', 'Raiders', 'Union',
        'Sternenbund', 'Nachtwache', 'Falken', 'Horde', 'Kartell', 'Garde', 'Orbit', 'Eclipse', 'Aurora', 'Pioneers',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly BotPersona $persona,
        private readonly BotLog $botLog,
        private readonly BotSocialMessaging $messaging,
    ) {}

    /**
     * Alliance step of a social turn. A bot in an alliance it owns handles the oldest application (processApplications()).
     * Universe settings: `bots_alliances_enabled` off makes bots lone wolves (no applications, no new alliances, members
     * leave; an alliance a bot founded stays); `bots_join_human_alliances` off keeps bots among themselves (they apply only
     * to alliances founded by bots, leave player alliances and withdraw applications there). A bot without alliance and
     * without pending application first rolls `alliance_join_chance` (percent, default 5): on success it applies to one of
     * the three best scored open alliances (accepting applications, not full, min points <= the bot's points; scored by
     * similar average points, same galaxy as members, plus randomness). Otherwise it rolls `alliance_chance` (percent) to
     * found its own (maybeFound()). Always returns false (never counts as the turn's chat message).
     *
     * @param array<string, mixed> $ctx bot context built by BotSocial::act()
     */
    public function stepAlliance(array $ctx): bool
    {
        $db = $this->db;
        $bot = $ctx['bot'];
        $uni = $ctx['uni'];
        $allyId = (int) $ctx['me']['ally_id'];

        $config = $this->configs->get($uni);
        $alliancesOn = !isset($config->bots_alliances_enabled) || !empty($config->bots_alliances_enabled);
        $joinHumans = !empty($config->bots_join_human_alliances);

        if (!$alliancesOn || !$joinHumans) {
            $this->withdrawApplications($ctx, $alliancesOn);
        }

        if ($allyId > 0) {
            $owner = $db->selectSingle('SELECT * FROM %%ALLIANCE%% WHERE id = :id AND ally_owner = :bot;', [':id' => $allyId, ':bot' => $bot]);
            if (!empty($owner)) {
                $this->processApplications($ctx, $owner);
            } elseif (!$alliancesOn) {
                $this->leaveAlliance($ctx, $allyId, 'lone wolf');
            } elseif (!$joinHumans && !$this->isBotAlliance($allyId)) {
                $this->leaveAlliance($ctx, $allyId, 'human alliance');
            }

            return false;
        }

        if (!$alliancesOn) {
            return false;
        }

        $pending = $db->selectSingle('SELECT COUNT(*) AS c FROM %%ALLIANCE_REQUEST%% WHERE userID = :bot;', [':bot' => $bot], 'c');
        if ((int) $pending > 0) {
            return false;
        }

        $joinChance = (int) ($ctx['profile']['alliance_join_chance'] ?? 5);
        $foundChance = (int) ($ctx['profile']['alliance_chance'] ?? 20);
        $wantsToJoin = mt_rand(1, 100) <= $joinChance;
        if (!$wantsToJoin && mt_rand(1, 100) > $foundChance) {
            return false;
        }

        $points = (float) $db->selectSingle(
            'SELECT total_points FROM %%STATPOINTS%% WHERE id_owner = :bot AND stat_type = 1;',
            [':bot' => $bot],
            'total_points',
        );

        $candidates = $db->select(
            'SELECT a.id, a.ally_tag, a.ally_owner, a.ally_request_min_points,
                    (SELECT AVG(s.total_points) FROM %%USERS%% u INNER JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = 1 WHERE u.ally_id = a.id) AS avg_points,
                    (SELECT COUNT(*) FROM %%USERS%% u WHERE u.ally_id = a.id AND u.galaxy = :gal) AS same_gal
             FROM %%ALLIANCE%% a
             WHERE a.ally_universe = :uni AND a.ally_request_notallow = 0 AND a.ally_members < a.ally_max_members
               AND a.ally_request_min_points <= :points
               AND (:humans = 1 OR a.ally_owner IN (SELECT b.user_id FROM %%BOT_PLAYERS%% b WHERE b.universe = :uni2));',
            [':uni' => $uni, ':uni2' => $uni, ':gal' => (int) $ctx['me']['galaxy'], ':points' => $points, ':humans' => $joinHumans ? 1 : 0],
        );

        if ($wantsToJoin && !empty($candidates)) {
            $scored = [];
            foreach ($candidates as $c) {
                $avg = (float) ($c['avg_points'] ?? 0);
                $closeness = $avg > 0 && $points > 0 ? min($avg, $points) / max($avg, $points) : 0.3;
                $score = 1 + $closeness * 3 + ((int) $c['same_gal'] > 0 ? 2 : 0) + mt_rand(0, 100) / 100;
                $scored[] = [$score, $c];
            }
            usort($scored, static fn($a, $b) => $b[0] <=> $a[0]);
            $pick = $scored[mt_rand(0, min(2, count($scored) - 1))][1];
            $this->applyTo($ctx, $pick);

            return false;
        }

        $this->maybeFound($ctx, $points);

        return false;
    }

    /** Whether the alliance was founded by (is owned by) a bot of this universe. */
    private function isBotAlliance(int $allianceId): bool
    {
        return (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%ALLIANCE%% a INNER JOIN %%BOT_PLAYERS%% b ON b.user_id = a.ally_owner WHERE a.id = :id;',
            [':id' => $allianceId],
            'c',
        ) > 0;
    }

    /**
     * The bot (a member, not the owner) leaves the alliance like a player leaving: user and statistics row detached, member
     * counter recomputed. `$reason` goes to the bot log (lone wolf / human alliance).
     *
     * @param array<string, mixed> $ctx bot context
     */
    private function leaveAlliance(array $ctx, int $allianceId, string $reason): void
    {
        $db = $this->db;
        $bot = $ctx['bot'];
        $tag = (string) $db->selectSingle('SELECT ally_tag FROM %%ALLIANCE%% WHERE id = :id;', [':id' => $allianceId], 'ally_tag');
        $db->update('UPDATE %%USERS%% SET ally_id = 0, ally_register_time = 0, ally_rank_id = 0 WHERE id = :bot;', [':bot' => $bot]);
        $db->update('UPDATE %%STATPOINTS%% SET id_ally = 0 WHERE id_owner = :bot AND stat_type = 1;', [':bot' => $bot]);
        // member counter recomputed like AllianceRepository::recountMembers()
        $db->update('UPDATE %%ALLIANCE%% SET ally_members = (SELECT COUNT(*) FROM %%USERS%% WHERE ally_id = :id) WHERE id = :id2;', [':id' => $allianceId, ':id2' => $allianceId]);
        $this->botLog->log($bot, $ctx['uni'], 'alliance_leave', 'left [' . $tag . '] #' . $allianceId . ' (' . $reason . ')');
    }

    /**
     * Withdraws open applications of the bot: only those at alliances founded by humans (`$humanOnly`, bots kept among
     * themselves) or all of them (lone wolves).
     *
     * @param array<string, mixed> $ctx bot context
     */
    private function withdrawApplications(array $ctx, bool $humanOnly): void
    {
        $this->db->delete(
            'DELETE r FROM %%ALLIANCE_REQUEST%% r INNER JOIN %%ALLIANCE%% a ON a.id = r.allianceID
             LEFT JOIN %%BOT_PLAYERS%% b ON b.user_id = a.ally_owner
             WHERE r.userID = :bot AND (:all = 1 OR b.user_id IS NULL);',
            [':bot' => $ctx['bot'], ':all' => $humanOnly ? 0 : 1],
        );
        if ($this->db->rowCount() > 0) {
            $this->botLog->log($ctx['bot'], $ctx['uni'], 'alliance_apply', $humanOnly ? 'withdrew applications at human alliances' : 'withdrew all applications (lone wolf)');
        }
    }

    /**
     * Files an application at `$alliance`: the text comes from the LLM when available and not broken, otherwise from the
     * canned APPLY_TEXTS (de/en, with the persona's typos). Inserts the alliance_request row and sends an alliance message to
     * the owner and every member with the manage-applications right, like the human application flow.
     *
     * @param array<string, mixed> $ctx      bot context
     * @param array<string, mixed> $alliance candidate row (id, ally_tag, ...)
     */
    private function applyTo(array $ctx, array $alliance): void
    {
        $db = $this->db;
        $bot = $ctx['bot'];
        $uni = $ctx['uni'];
        $lang = $ctx['persona']['language'] ?? 'de';
        $text = null;

        $llm = $this->persona->ask(
            $uni,
            $bot,
            BotSocialMessaging::systemPrompt($ctx, 250),
            'Write a short application text (max 250 characters, in ' . ($lang === 'de' ? 'German' : 'English')
                . ') to join the alliance "' . $alliance['ally_tag'] . '". ' . $this->messaging->situation($ctx),
            200,
        );
        if ($llm !== null) {
            $clean = BotPersona::cleanText($llm, 250);
            if (!BotPersona::looksBroken($clean)) {
                $text = $clean;
            }
        }
        if ($text === null) {
            $text = BotPersona::typos(BotSocialMessaging::pick(self::APPLY_TEXTS[$lang] ?? self::APPLY_TEXTS['en']), (float) ($ctx['persona']['typo_rate'] ?? 0));
        }
        $text = BotPersona::escapeForStorage(str_replace("\n", ' ', $text), false);

        $db->insert(
            'INSERT INTO %%ALLIANCE_REQUEST%% SET allianceId = :a, text = :t, time = :time, userId = :u;',
            [':a' => $alliance['id'], ':t' => $text, ':time' => time(), ':u' => $bot],
        );

        // notify the managers like AllianceController::apply() does
        $receivers = $db->select(
            'SELECT u.id, us.lang FROM %%USERS%% u
             INNER JOIN %%USERS_SETTINGS%% us ON u.id = us.user_id
             JOIN %%ALLIANCE%% a ON u.ally_id = a.id
             LEFT JOIN %%ALLIANCE_RANK%% r ON u.ally_id = r.allianceID AND u.ally_rank_id = r.rankID
             WHERE u.ally_id = :ally AND (r.MANAGEAPPLY = 1 OR u.id = a.ally_owner);',
            [':ally' => $alliance['id']],
        );
        foreach ($receivers as $receiver) {
            $l = $this->playerLookup()->getLanguage($receiver['lang']);
            $this->playerMessenger()->sendMessage(
                (int) $receiver['id'],
                0,
                $l['al_alliance'],
                MessageType::Alliance->value,
                $l['al_request'],
                $l->formatHtml('al_new_apply', $bot, $ctx['me']['username'], $ctx['me']['username']),
                time(),
                1,
                $uni,
            );
        }
        $this->botLog->log($bot, $uni, 'alliance_apply', 'applied to [' . $alliance['ally_tag'] . '] #' . $alliance['id']);
    }

    /**
     * Possibly founds an alliance for the bot. Per call the chance is `alliance_chance` / 10 percent (so rare); requires at
     * least 2 planets (planet type 1, not destroyed) and a rank within the best 30 % of the universe by points. Tries up
     * to ten random name/tag combinations from ALLY_WORDS that pass name validation and are free in the universe,
     * then creates the alliance (bot as owner, 1 member) and assigns it in the users and statpoints tables.
     *
     * @param array<mixed> $ctx
     */
    private function maybeFound(array $ctx, float $points): void
    {
        $db = $this->db;
        $bot = $ctx['bot'];
        $uni = $ctx['uni'];

        $chance = (float) ($ctx['profile']['alliance_chance'] ?? 20) / 10;
        if (mt_rand(0, 9999) / 100 >= $chance) {
            return;
        }
        $planets = (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%PLANETS%% WHERE id_owner = :bot AND planet_type = 1 AND destruyed = 0;', [':bot' => $bot], 'c');
        if ($planets < 2) {
            return;
        }
        $total = (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%STATPOINTS%% WHERE universe = :uni AND stat_type = 1;', [':uni' => $uni], 'c');
        $better = (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%STATPOINTS%% WHERE universe = :uni AND stat_type = 1 AND total_points > :p;', [':uni' => $uni, ':p' => $points], 'c');
        if ($total < 1 || $better / $total > 0.3) {
            return;
        }

        for ($i = 0; $i < 10; ++$i) {
            $word = BotSocialMessaging::pick(self::ALLY_WORDS);
            $name = $word . (mt_rand(0, 2) === 0 ? ' ' . BotSocialMessaging::pick(self::ALLY_WORDS) : '');
            $tag = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $word), 0, mt_rand(3, 5)));
            $name = preg_replace('/[^a-zA-Z0-9 (-)_äöüßÄÖÜ]/', '', $name);
            if ($tag === '' || $name === '' || !$this->playerRegistration()->isNameValid($name) || !$this->playerRegistration()->isNameValid($tag)) {
                continue;
            }
            $exists = (int) $db->selectSingle(
                'SELECT COUNT(*) AS c FROM %%ALLIANCE%% WHERE ally_universe = :uni AND (ally_tag = :tag OR ally_name = :name);',
                [':uni' => $uni, ':tag' => $tag, ':name' => $name],
                'c',
            );
            if ($exists !== 0) {
                continue;
            }

            $lang = $this->playerLookup()->getLanguage(null, $bot);
            $db->insert(
                'INSERT INTO %%ALLIANCE%% SET ally_name = :name, ally_tag = :tag, ally_owner = :bot, ally_owner_range = :range,
                 ally_members = 1, ally_register_time = :time, ally_universe = :uni;',
                [':name' => $name, ':tag' => $tag, ':bot' => $bot, ':range' => $lang['al_default_leader_name'], ':time' => time(), ':uni' => $uni],
            );
            $allianceId = $db->lastInsertId();
            $db->update('UPDATE %%USERS%% SET ally_id = :a, ally_rank_id = 0, ally_register_time = :time WHERE id = :bot;', [':a' => $allianceId, ':time' => time(), ':bot' => $bot]);
            $db->update('UPDATE %%STATPOINTS%% SET id_ally = :a WHERE id_owner = :bot;', [':a' => $allianceId, ':bot' => $bot]);
            $this->botLog->log($bot, $uni, 'alliance_found', $name . ' [' . $tag . '] #' . $allianceId);

            return;
        }
    }

    /**
     * Decides the oldest application to the bot's alliance: 15 % stay pending, 70 % are accepted, 15 % declined. An
     * accept is re-checked under row locks (applicant still without alliance, alliance not full) in one transaction and
     * falls back to a decline when the alliance filled up meanwhile. Updates user, statpoints and alliance member count,
     * then messages the applicant (accept text with a welcome line, or decline) in their language.
     *
     * @param array<string, mixed> $ctx      bot context
     * @param array<string, mixed> $alliance row of the bot's own alliance
     */
    private function processApplications(array $ctx, array $alliance): void
    {
        $db = $this->db;
        $bot = $ctx['bot'];
        $uni = $ctx['uni'];
        $apply = $db->selectSingle('SELECT applyID, userID FROM %%ALLIANCE_REQUEST%% WHERE allianceID = :a ORDER BY applyID ASC LIMIT 1;', [':a' => $alliance['id']]);
        if (empty($apply)) {
            return;
        }

        $roll = mt_rand(1, 100);
        if ($roll > 85) {
            return; // leave it pending for a while
        }
        $accept = $roll <= 70;
        $userId = (int) $apply['userID'];
        $applyId = (int) $apply['applyID'];
        $l = $this->playerLookup()->getLanguage(null, $userId);
        $welcome = $accept ? BotPersona::escapeForStorage(BotSocialMessaging::pick(self::WELCOME[$l->getLanguage() ?? 'en'] ?? self::WELCOME['en']), false) : '';

        if ($accept) {
            $db->startTransaction();
            $applicantAlly = $db->selectSingle('SELECT ally_id FROM %%USERS%% WHERE id = :u FOR UPDATE;', [':u' => $userId], 'ally_id');
            if ($applicantAlly != 0) {
                $db->delete('DELETE FROM %%ALLIANCE_REQUEST%% WHERE applyID = :id;', [':id' => $applyId]);
                $db->commit();

                return;
            }
            $members = (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS%% WHERE ally_id = :a FOR UPDATE;', [':a' => $alliance['id']], 'c');
            if ($members >= (int) $alliance['ally_max_members']) {
                $db->rollback();
                $accept = false;
            } else {
                $db->delete('DELETE FROM %%ALLIANCE_REQUEST%% WHERE applyID = :id AND allianceId = :a;', [':id' => $applyId, ':a' => $alliance['id']]);
                $db->update('UPDATE %%USERS%% SET ally_id = :a, ally_register_time = :time, ally_rank_id = 0 WHERE id = :u;', [':a' => $alliance['id'], ':time' => time(), ':u' => $userId]);
                $db->update('UPDATE %%STATPOINTS%% SET id_ally = :a WHERE id_owner = :u AND stat_type = 1;', [':a' => $alliance['id'], ':u' => $userId]);
                $db->update('UPDATE %%ALLIANCE%% SET ally_members = ally_members + 1 WHERE id = :a;', [':a' => $alliance['id']]);
                $db->commit();
            }
        }
        if (!$accept) {
            $db->delete('DELETE FROM %%ALLIANCE_REQUEST%% WHERE applyID = :id AND allianceId = :a;', [':id' => $applyId, ':a' => $alliance['id']]);
        }

        if ($accept) {
            $text = $l['al_hi_the_alliance'] . $alliance['ally_name'] . $l['al_has_accepted'] . $welcome;
            $subject = $l['al_you_was_acceted'] . $alliance['ally_name'];
        } else {
            $text = $l['al_hi_the_alliance'] . $alliance['ally_name'] . $l['al_has_declined'];
            $subject = $l['al_you_was_declined'] . $alliance['ally_name'];
        }
        $this->playerMessenger()->sendMessage(
            $userId,
            $bot,
            $l['al_the_alliance'] . $alliance['ally_name'] . ' [' . $alliance['ally_tag'] . ']',
            MessageType::Alliance->value,
            $subject,
            $text,
            time(),
            1,
            $uni,
        );
        $this->botLog->log($bot, $uni, 'alliance_apply', ($accept ? 'accepted #' : 'declined #') . $userId);
    }
}
