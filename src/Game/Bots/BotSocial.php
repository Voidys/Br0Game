<?php

namespace App\Game\Bots;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use Throwable;

/**
 * Social behaviour of bots: answering private messages, buddy requests, alliances, greeting neighbours.
 * Everything goes through the game's own tables / $this->playerUtil()->sendMessage, each step rolled by a chance.
 */
class BotSocial
{
    use GameUtilities;

    private const DE_WORDS = ['hallo', 'hi', 'ich', 'du', 'nicht', 'und', 'bitte', 'danke', 'wie', 'was', 'kannst', 'hast',
        'bist', 'ein', 'eine', 'mit', 'fuer', 'für', 'moin', 'servus', 'allianz', 'wir', 'dein', 'deine', 'mal', 'ja', 'nein'];
    private const EN_WORDS = ['hello', 'the', 'you', 'your', 'and', 'please', 'thanks', 'how', 'what', 'can', 'have', 'are',
        'with', 'for', 'alliance', 'we', 'yes', 'no', 'hey', 'join', 'want'];

    private const REPLIES = [
        'greeting' => [
            'de' => ['Hi, na?', 'Moin! Alles gut bei dir?', 'Hallo, was gibts?', 'Hey, danke fuer die Nachricht.'],
            'en' => ['Hi there!', 'Hey, whats up?', 'Hello, thanks for writing.', 'Hey, how is it going?'],
        ],
        'attack' => [
            'de' => ['Wieso greifst du mich an? Lass mich bitte in Ruhe.', 'Das war nicht nett. Ich baue nur vor mich hin.', 'Hab ich dir was getan?'],
            'en' => ['Why are you attacking me? Please leave me alone.', 'That was not nice. I just build my stuff.', 'Did I do something to you?'],
        ],
        'trade' => [
            'de' => ['Ich brauch selber alles, sorry.', 'Hmm, schwierig. Was genau hast du dir vorgestellt?', 'Eher nicht, ich bin knapp dran.'],
            'en' => ['I need everything myself, sorry.', 'Hmm, tricky. What exactly do you have in mind?', 'Probably not, I am short on resources.'],
        ],
        'alliance' => [
            'de' => ['Allianz klingt interessant, muss ich mir mal ansehen.', 'Hab schon drueber nachgedacht, melde mich.', 'Wie aktiv seid ihr denn so?'],
            'en' => ['An alliance sounds interesting, I will take a look.', 'Been thinking about it, will get back to you.', 'How active are you guys?'],
        ],
        'question' => [
            'de' => ['Gute Frage, ich bin da selber noch am Rumprobieren.', 'Kann ich dir gerade nicht sagen, sorry.', 'Bin mir nicht sicher, was meinst du genau?'],
            'en' => ['Good question, I am still figuring that out myself.', 'Cannot tell you right now, sorry.', 'Not sure, what do you mean exactly?'],
        ],
        'default' => [
            'de' => ['Ok, danke dir.', 'Alles klar.', 'Joa, kann man so machen.', 'Verstehe, danke fuer die Info.'],
            'en' => ['Ok, thanks.', 'Alright.', 'Yeah, can do.', 'I see, thanks for the info.'],
        ],
    ];

    private const GREETINGS = [
        'de' => [
            ['Hallo', 'Hi, ich bin neu in deiner Gegend. Spielst du schon lange?'],
            ['Moin', 'Hey, wir sind Nachbarn. Falls du Tipps hast, immer her damit.'],
            ['Hi', 'Na, auch hier unterwegs? Wuensch dir viel Erfolg!'],
        ],
        'en' => [
            ['Hello', 'Hi, I am new in your neighbourhood. Been playing for long?'],
            ['Hey', 'Hey, looks like we are neighbours. Any tips are welcome.'],
            ['Hi', 'Oh, you play here as well? Good luck out there!'],
        ],
    ];

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
    ) {}

    public function act(array $botRow, array $user, array $profile): void
    {
        $botId = (int) ($botRow['user_id'] ?? $user['id'] ?? 0);
        $universe = (int) ($botRow['universe'] ?? $user['universe'] ?? 1);
        if ($botId <= 0) {
            return;
        }

        try {
            $db = $this->db;
            $me = $db->selectSingle('SELECT id, username, ally_id, galaxy, `system`, planet, universe FROM %%USERS%% WHERE id = :id;', [':id' => $botId]);
            if (empty($me)) {
                return;
            }
            $ctx = [
                'bot' => $botId,
                'uni' => $universe,
                'me' => $me,
                'row' => $botRow,
                'persona' => BotPersona::decode($botRow['persona'] ?? null),
                'profile' => $profile,
            ];
        } catch (Throwable $e) {
            return;
        }

        $sent = false;
        $steps = [
            'reply' => fn() => $this->stepReply($ctx),
            'buddy' => fn() => $this->stepBuddy($ctx),
            'alliance' => fn() => $this->stepAlliance($ctx),
            'message' => function () use ($ctx, &$sent) {
                return $sent ? false : $this->stepMessage($ctx);
            },
        ];
        foreach ($steps as $name => $step) {
            try {
                $result = $step();
                if (($name === 'reply' || $name === 'message') && $result === true) {
                    $sent = true;
                }
            } catch (Throwable $e) {
                $this->botLog->log($botId, $universe, 'social', $name . ': ' . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------------------------------------
    // a. replies
    // ------------------------------------------------------------------------------------------------

    private function stepReply(array $ctx): bool
    {
        $db = $this->db;
        $bot = $ctx['bot'];
        $uni = $ctx['uni'];
        $last = (int) ($ctx['row']['last_message_id'] ?? 0);
        $now = time();

        // the bot's inbox is users_to_messages (a message can have several receivers; message_owner is only the first)
        $msg = $db->selectSingle(
            'SELECT m.message_id, m.message_group, m.message_sender, m.message_subject, m.message_text, m.message_time
             FROM %%MESSAGES%% m
             INNER JOIN %%USERS_TO_MESSAGES%% um ON um.message_id = m.message_id AND um.user_id = :bot
             WHERE m.message_type = :type AND m.message_universe = :uni
               AND m.message_id > :last AND m.message_sender > 0 AND m.message_sender <> :bot2
               AND um.sender_id IS NOT NULL AND um.sender_id <> :bot3
               AND um.deleted_at IS NULL AND m.message_deleted IS NULL AND m.message_time <= :minAge
               AND NOT EXISTS (SELECT 1 FROM %%BOT_PLAYERS%% b WHERE b.user_id = m.message_sender)
             ORDER BY m.message_id ASC LIMIT 1;',
            [':bot' => $bot, ':bot2' => $bot, ':bot3' => $bot, ':type' => MESSAGE_TYPE_USER, ':uni' => $uni, ':last' => $last, ':minAge' => $now - 30],
        );
        if (empty($msg)) {
            return false;
        }

        $messageId = (int) $msg['message_id'];
        $senderId = (int) $msg['message_sender'];
        $replied = false;

        $settings = $ctx['profile'];
        $chance = (int) ($settings['reply_chance'] ?? 80);
        $blocked = $this->playerUtil()->get_block_status($bot, $senderId)['block_dm'] == 1
            || $this->playerUtil()->get_block_status($senderId, $bot)['block_dm'] == 1;

        if (!$blocked && mt_rand(1, 100) <= $chance) {
            $incoming = self::plain((string) $msg['message_text'], 600);
            $subjectPlain = self::plain((string) $msg['message_subject'], 200);
            $lang = self::detectLanguage($incoming, $ctx['persona']['language'] ?? 'de');
            $text = null;

            $situation = $this->situation($ctx);
            $system = self::systemPrompt($ctx, 400);
            $prompt = "Another player wrote you a private message.\nSubject: " . $subjectPlain
                . "\nMessage: " . $incoming . "\nYour situation: " . $situation
                . "\nWrite your reply in " . ($lang === 'de' ? 'German' : 'English') . ', max 400 characters.';
            $llm = $this->persona->ask($uni, $bot, $system, $prompt, 300);
            if ($llm !== null) {
                $clean = BotPersona::cleanText($llm, 400);
                if (BotPersona::looksBroken($clean)) {
                    $this->botLog->log($bot, $uni, 'llm_error', 'reply rejected');
                } else {
                    $text = $clean;
                }
            }
            if ($text === null) {
                $text = self::cannedReply($incoming, $lang, $ctx['persona']);
            }

            $subject = 'Re: ' . self::trimSubject($subjectPlain);
            $group = !empty($msg['message_group']) ? (string) $msg['message_group'] : null;
            $replied = $this->send($ctx, $senderId, $subject, $text, $group);
            $this->botLog->log($bot, $uni, 'reply', ($replied ? 'to #' : 'skipped #') . $senderId . ' msg ' . $messageId . ($llm !== null ? ' llm' : ''));
        }

        // handled: advance pointer and mark read
        $db->update('UPDATE %%BOT_PLAYERS%% SET last_message_id = :id WHERE user_id = :bot;', [':id' => $messageId, ':bot' => $bot]);
        $db->update('UPDATE %%MESSAGES%% SET message_unread = 0 WHERE message_id = :id AND message_owner = :bot;', [':id' => $messageId, ':bot' => $bot]);
        $db->update('UPDATE %%USERS_TO_MESSAGES%% SET message_unread = 0 WHERE message_id = :id AND user_id = :bot;', [':id' => $messageId, ':bot' => $bot]);

        return $replied;
    }

    // ------------------------------------------------------------------------------------------------
    // b. buddy requests
    // ------------------------------------------------------------------------------------------------

    private function stepBuddy(array $ctx): bool
    {
        $db = $this->db;
        $bot = $ctx['bot'];
        $req = $db->selectSingle(
            'SELECT b.id, b.sender, u.username, us.lang FROM %%BUDDY%% b
             INNER JOIN %%BUDDY_REQUEST%% r ON r.id = b.id
             INNER JOIN %%USERS%% u ON u.id = b.sender
             LEFT JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id
             WHERE b.owner = :bot AND b.universe = :uni ORDER BY b.id ASC LIMIT 1;',
            [':bot' => $bot, ':uni' => $ctx['uni']],
        );
        if (empty($req)) {
            return false;
        }

        $lang = $this->playerUtil()->getLanguage($req['lang'] ?: 'en');
        $accept = mt_rand(1, 100) <= (int) ($ctx['profile']['buddy_accept_chance'] ?? 60);
        if ($accept) {
            $db->delete('DELETE FROM %%BUDDY_REQUEST%% WHERE id = :id;', [':id' => $req['id']]);
            $this->playerUtil()->sendMessage(
                (int) $req['sender'],
                $bot,
                $ctx['me']['username'],
                MESSAGE_TYPE_SYSTEM,
                $lang['bu_accepted_request_title'],
                sprintf($lang['bu_accepted_request_body'], $req['username'], $ctx['me']['username']),
                time(),
                1,
                $ctx['uni'],
            );
            $this->botLog->log($bot, $ctx['uni'], 'buddy', 'accepted #' . $req['sender']);
        } elseif (mt_rand(1, 100) <= 80) {
            $this->playerUtil()->sendMessage(
                (int) $req['sender'],
                $bot,
                $ctx['me']['username'],
                MESSAGE_TYPE_SYSTEM,
                $lang['bu_rejected_request_title'],
                sprintf($lang['bu_rejected_request_body'], $req['username'], $ctx['me']['username']),
                time(),
                1,
                $ctx['uni'],
            );
            $db->delete('DELETE b.*, r.* FROM %%BUDDY%% b LEFT JOIN %%BUDDY_REQUEST%% r USING (id) WHERE b.id = :id;', [':id' => $req['id']]);
            $this->botLog->log($bot, $ctx['uni'], 'buddy', 'declined #' . $req['sender']);
        }

        return false;
    }

    // ------------------------------------------------------------------------------------------------
    // c. alliance
    // ------------------------------------------------------------------------------------------------

    private function stepAlliance(array $ctx): bool
    {
        $db = $this->db;
        $bot = $ctx['bot'];
        $uni = $ctx['uni'];
        $allyId = (int) $ctx['me']['ally_id'];

        if ($allyId > 0) {
            $owner = $db->selectSingle('SELECT * FROM %%ALLIANCE%% WHERE id = :id AND ally_owner = :bot;', [':id' => $allyId, ':bot' => $bot]);
            if (!empty($owner)) {
                $this->processApplications($ctx, $owner);
            }

            return false;
        }

        $pending = $db->selectSingle('SELECT COUNT(*) AS c FROM %%ALLIANCE_REQUEST%% WHERE userID = :bot;', [':bot' => $bot], 'c');
        if ((int) $pending > 0) {
            return false;
        }

        $chance = (int) ($ctx['profile']['alliance_chance'] ?? 20);
        if (mt_rand(1, 100) > $chance) {
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
               AND a.ally_request_min_points <= :points;',
            [':uni' => $uni, ':gal' => (int) $ctx['me']['galaxy'], ':points' => $points],
        );

        if (!empty($candidates)) {
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
            self::systemPrompt($ctx, 250),
            'Write a short application text (max 250 characters, in ' . ($lang === 'de' ? 'German' : 'English')
                . ') to join the alliance "' . $alliance['ally_tag'] . '". ' . $this->situation($ctx),
            200,
        );
        if ($llm !== null) {
            $clean = BotPersona::cleanText($llm, 250);
            if (!BotPersona::looksBroken($clean)) {
                $text = $clean;
            }
        }
        if ($text === null) {
            $text = BotPersona::typos(self::pick(self::APPLY_TEXTS[$lang] ?? self::APPLY_TEXTS['en']), (float) ($ctx['persona']['typo_rate'] ?? 0));
        }
        $text = BotPersona::escapeForStorage(str_replace("\n", ' ', $text), false);

        $db->insert(
            'INSERT INTO %%ALLIANCE_REQUEST%% SET allianceId = :a, text = :t, time = :time, userId = :u;',
            [':a' => $alliance['id'], ':t' => $text, ':time' => time(), ':u' => $bot],
        );

        // notify the managers like ShowAlliancePage::apply does
        $receivers = $db->select(
            'SELECT u.id, us.lang FROM %%USERS%% u
             INNER JOIN %%USERS_SETTINGS%% us ON u.id = us.user_id
             JOIN %%ALLIANCE%% a ON u.ally_id = a.id
             LEFT JOIN %%ALLIANCE_RANK%% r ON u.ally_id = r.allianceID AND u.ally_rank_id = r.rankID
             WHERE u.ally_id = :ally AND (r.MANAGEAPPLY = 1 OR u.id = a.ally_owner);',
            [':ally' => $alliance['id']],
        );
        foreach ($receivers as $receiver) {
            $l = $this->playerUtil()->getLanguage($receiver['lang']);
            $this->playerUtil()->sendMessage(
                (int) $receiver['id'],
                0,
                $l['al_alliance'],
                MESSAGE_TYPE_ALLIANCE,
                $l['al_request'],
                sprintf($l['al_new_apply'], $bot, $ctx['me']['username'], $ctx['me']['username']),
                time(),
                1,
                $uni,
            );
        }
        $this->botLog->log($bot, $uni, 'alliance_apply', 'applied to [' . $alliance['ally_tag'] . '] #' . $alliance['id']);
    }

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
            $word = self::pick(self::ALLY_WORDS);
            $name = $word . (mt_rand(0, 2) === 0 ? ' ' . self::pick(self::ALLY_WORDS) : '');
            $tag = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $word), 0, mt_rand(3, 5)));
            $name = preg_replace('/[^a-zA-Z0-9 (-)_äöüßÄÖÜ]/', '', $name);
            if ($tag === '' || $name === '' || !$this->playerUtil()->isNameValid($name) || !$this->playerUtil()->isNameValid($tag)) {
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

            $lang = $this->playerUtil()->getLanguage(null, $bot);
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
        $l = $this->playerUtil()->getLanguage(null, $userId);
        $welcome = $accept ? BotPersona::escapeForStorage(self::pick(self::WELCOME[$l->getLanguage() ?? 'en'] ?? self::WELCOME['en']), false) : '';

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
        $this->playerUtil()->sendMessage(
            $userId,
            $bot,
            $l['al_the_alliance'] . $alliance['ally_name'] . ' [' . $alliance['ally_tag'] . ']',
            MESSAGE_TYPE_ALLIANCE,
            $subject,
            $text,
            time(),
            1,
            $uni,
        );
        $this->botLog->log($bot, $uni, 'alliance_apply', ($accept ? 'accepted #' : 'declined #') . $userId);
    }

    // ------------------------------------------------------------------------------------------------
    // d. proactive message
    // ------------------------------------------------------------------------------------------------

    private function stepMessage(array $ctx): bool
    {
        $chance = (int) ($ctx['profile']['message_chance'] ?? 5);
        if (mt_rand(1, 100) > $chance) {
            return false;
        }
        $db = $this->db;
        $bot = $ctx['bot'];
        $uni = $ctx['uni'];
        $now = time();

        $sentToday = (int) $db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%MESSAGES%% WHERE message_sender = :bot AND message_type = :t AND message_time > :since;',
            [':bot' => $bot, ':t' => MESSAGE_TYPE_USER, ':since' => $now - 86400],
            'c',
        );
        if ($sentToday >= 5) {
            return false;
        }

        $target = $db->selectSingle(
            'SELECT u.id, u.username, u.galaxy, u.`system`, u.planet, us.lang FROM %%USERS%% u
             LEFT JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id
             WHERE u.universe = :uni AND u.id <> :bot AND u.galaxy = :gal AND u.`system` BETWEEN :s1 AND :s2
               AND u.onlinetime > :online
               AND NOT EXISTS (SELECT 1 FROM %%BOT_PLAYERS%% b WHERE b.user_id = u.id)
               AND NOT EXISTS (SELECT 1 FROM %%MESSAGES%% m WHERE m.message_sender = :bot AND m.message_owner = u.id
                               AND m.message_type = :t AND m.message_time > :week)
             ORDER BY RAND() LIMIT 1;',
            [
                ':uni' => $uni, ':bot' => $bot, ':gal' => (int) $ctx['me']['galaxy'],
                ':s1' => (int) $ctx['me']['system'] - 10, ':s2' => (int) $ctx['me']['system'] + 10,
                ':online' => $now - 30 * 86400, ':t' => MESSAGE_TYPE_USER, ':week' => $now - 7 * 86400,
            ],
        );
        if (empty($target)) {
            return false;
        }
        if (
            $this->playerUtil()->get_block_status($bot, (int) $target['id'])['block_dm'] == 1
            || $this->playerUtil()->get_block_status((int) $target['id'], $bot)['block_dm'] == 1
        ) {
            return false;
        }

        $lang = in_array($target['lang'] ?? '', ['de', 'en'], true) ? $target['lang'] : 'en';
        $text = null;
        $subject = null;
        $llm = $this->persona->ask(
            $uni,
            $bot,
            self::systemPrompt($ctx, 250),
            'Write a short, friendly first message (max 250 characters, in ' . ($lang === 'de' ? 'German' : 'English')
                . ') to a neighbouring player named "' . $target['username'] . '" at ' . $target['galaxy'] . ':' . $target['system']
                . '. Just greet them or ask something casual about the game. ' . $this->situation($ctx),
            200,
        );
        if ($llm !== null) {
            $clean = BotPersona::cleanText($llm, 250);
            if (!BotPersona::looksBroken($clean)) {
                $text = $clean;
                $subject = $lang === 'de' ? 'Hallo' : 'Hello';
            }
        }
        if ($text === null) {
            [$subject, $text] = self::pick(self::GREETINGS[$lang]);
            $text = self::stylize($text, $ctx['persona']);
        }

        $ok = $this->send($ctx, (int) $target['id'], $subject, $text, null);
        $this->botLog->log($bot, $uni, 'message', ($ok ? 'greeted #' : 'skipped #') . $target['id'] . ($llm !== null ? ' llm' : ''));

        return $ok;
    }

    // ------------------------------------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------------------------------------

    /** Sends a private message from the bot. $text/$subject are raw text; they are escaped here. */
    private function send(array $ctx, int $receiverId, string $subject, string $text, ?string $group): bool
    {
        $text = BotPersona::cleanText($text, 600);
        $subject = BotPersona::cleanText(str_replace("\n", ' ', $subject), 60);
        if ($text === '') {
            return false;
        }
        if ($subject === '') {
            $subject = '-';
        }

        // forbidden-text filter of the game: never send something that would get a real player banned
        try {
            foreach ($this->db->select('SELECT `text` FROM %%MESSAGES_FORBIDDEN%%;') as $row) {
                $regex = '/' . $row['text'] . '/i';
                if (@preg_match($regex, $text) || @preg_match($regex, $subject)) {
                    return false;
                }
            }
        } catch (Throwable $e) {
            // table missing or bad regex: ignore
        }

        $me = $ctx['me'];
        $sender = $me['username'] . ' [' . $me['galaxy'] . ':' . $me['system'] . ':' . $me['planet'] . ']';
        $this->playerUtil()->sendMessage(
            $receiverId,
            $ctx['bot'],
            $sender,
            MESSAGE_TYPE_USER,
            BotPersona::escapeForStorage($subject, false),
            BotPersona::escapeForStorage($text),
            time(),
            1,
            $ctx['uni'],
            $group,
        );

        return true;
    }

    private static function systemPrompt(array $ctx, int $maxLen): string
    {
        $p = $ctx['persona'];
        $prompt = 'You are a human player named "' . $ctx['me']['username'] . '" in a browser space strategy game (like OGame). '
            . 'Persona: ' . ($p['bio'] ?? '') . ' Tone: ' . ($p['tone'] ?? 'casual') . '. Age: ' . ($p['age_hint'] ?? 'unknown') . '.';
        if (!empty($p['quirks'])) {
            $prompt .= ' Quirks: ' . implode('; ', (array) $p['quirks']) . '.';
        }
        $prompt .= ' Writing style: ' . ($ctx['profile']['chat_style'] ?? 'short, casual') . '. '
            . 'Rules: stay in character as a normal human player, never reveal or hint that you are an AI, bot or program, '
            . 'no links, no markdown, no emojis spam, plain text only, at most ' . $maxLen . ' characters. '
            . 'Output only the message text, nothing else.';

        return $prompt;
    }

    private function situation(array $ctx): string
    {
        try {
            $db = $this->db;
            $stat = $db->selectSingle('SELECT total_rank, total_points FROM %%STATPOINTS%% WHERE id_owner = :b AND stat_type = 1;', [':b' => $ctx['bot']]);
            $planets = $db->selectSingle('SELECT COUNT(*) AS c FROM %%PLANETS%% WHERE id_owner = :b AND planet_type = 1 AND destruyed = 0;', [':b' => $ctx['bot']], 'c');

            return 'You are rank ' . (int) ($stat['total_rank'] ?? 0) . ' with ' . (int) ($stat['total_points'] ?? 0)
                . ' points and own ' . (int) $planets . ' planet(s).';
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function plain(string $html, int $max): string
    {
        $text = preg_replace('~<br\s*/?>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');

        return trim(mb_substr($text, 0, $max));
    }

    private static function trimSubject(string $subject): string
    {
        $subject = trim(preg_replace('/^(\s*re:\s*)+/i', '', $subject));
        if ($subject === '') {
            $subject = '-';
        }

        return mb_substr($subject, 0, 40);
    }

    private static function detectLanguage(string $text, string $fallback): string
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $de = count(array_intersect($words, self::DE_WORDS));
        $en = count(array_intersect($words, self::EN_WORDS));
        if ($de > $en) {
            return 'de';
        }
        if ($en > $de) {
            return 'en';
        }

        return in_array($fallback, ['de', 'en'], true) ? $fallback : 'de';
    }

    private static function cannedReply(string $incoming, string $lang, array $persona): string
    {
        $t = mb_strtolower($incoming);
        $intent = 'default';
        if (preg_match('/angriff|attack|attackiert|angegriffen|farm|raid/', $t)) {
            $intent = 'attack';
        } elseif (preg_match('/tausch|trade|handel|ressourcen|resources|deut|metall|metal|kristall/', $t)) {
            $intent = 'trade';
        } elseif (preg_match('/allianz|alliance|ally|beitreten|join/', $t)) {
            $intent = 'alliance';
        } elseif (str_contains($t, '?')) {
            $intent = 'question';
        } elseif (preg_match('/^(hi|hallo|hey|moin|hello|servus|na)\b/', $t)) {
            $intent = 'greeting';
        }
        $text = self::pick(self::REPLIES[$intent][$lang] ?? self::REPLIES[$intent]['en']);

        return self::stylize($text, $persona);
    }

    private static function stylize(string $text, array $persona): string
    {
        $text = BotPersona::typos($text, (float) ($persona['typo_rate'] ?? 0));
        $quirks = strtolower(implode(' ', (array) ($persona['quirks'] ?? [])));
        if (str_contains($quirks, 'lowercase') || str_contains($quirks, 'klein')) {
            $text = mb_strtolower($text);
        }
        if (str_contains($quirks, 'punctuation') || str_contains($quirks, 'satzzeichen')) {
            $text = rtrim($text, '.!');
        }

        return $text;
    }

    private static function pick(array $list): mixed
    {
        return $list[array_rand($list)];
    }
}
