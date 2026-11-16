<?php

namespace App\Game\Bots;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use Throwable;

/**
 * Messaging part of the bot social behaviour: answers private messages (combined, throttled, tone by relation stance), reacts to attacks on the bot,
 * taunts after raids and sends the messages (same forbidden-text rules as for players).
 */
class BotSocialMessaging
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
        'hostile' => [
            'de' => ['Lass mich in Ruhe.', 'Ich hab keine Lust auf dich. Das war alles.', 'Schreib mir nicht nochmal.'],
            'en' => ['Leave me alone.', 'I am not interested in talking to you. That is all.', 'Do not write to me again.'],
        ],
        'friendly' => [
            'de' => ['Hey, schoen von dir zu hoeren! Danke dir.', 'Gerne, sag Bescheid wenn ich helfen kann.', 'Super, danke dir, dir auch viel Erfolg!'],
            'en' => ['Hey, good to hear from you! Thanks.', 'Sure, let me know if I can help.', 'Great, thanks, good luck to you too!'],
        ],
        'default' => [
            'de' => ['Ok, danke dir.', 'Alles klar.', 'Joa, kann man so machen.', 'Verstehe, danke fuer die Info.'],
            'en' => ['Ok, thanks.', 'Alright.', 'Yeah, can do.', 'I see, thanks for the info.'],
        ],
    ];

    /** Short reaction after a player attacked the bot (subject, text), by stance and language. */
    private const ATTACK_REACTIONS = [
        'hostile' => [
            'de' => [['Ernsthaft?', 'Das wirst du bereuen.'], ['Hm', 'Merk dir: ich vergesse sowas nicht.']],
            'en' => [['Seriously?', 'You will regret that.'], ['Hm', 'Remember: I do not forget things like this.']],
        ],
        'neutral' => [
            'de' => [['Hey', 'Warum greifst du mich an? Lass das bitte.'], ['Nicht nett', 'Das war nicht noetig.']],
            'en' => [['Hey', 'Why are you attacking me? Please stop.'], ['Not nice', 'That was not necessary.']],
        ],
        'friendly' => [
            'de' => [['Autsch', 'Ich dachte, wir verstehen uns? Warum der Angriff?'], ['Schade', 'Das hat weh getan. Was ist los?']],
            'en' => [['Ouch', 'I thought we got along? Why the attack?'], ['Too bad', 'That hurt. What is going on?']],
        ],
    ];

    /** Short messages after a successful raid (subject, text), by language. */
    private const TAUNTS = [
        'de' => [
            ['Danke', 'Danke fuer die Rohstoffe.'],
            ['Bis bald', 'Das war leicht. Bis zum naechsten Mal!'],
            ['Lecker', 'Deine Minen laufen gut, ich komme wieder.'],
            ['Hi', 'Schoenes Lager hast du da.'],
        ],
        'en' => [
            ['Thanks', 'Thanks for the resources.'],
            ['See you', 'That was easy. See you next time!'],
            ['Nice', 'Nice mines you have, I will be back.'],
            ['Hi', 'Nice stockpile you have there.'],
        ],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly BotPersona $persona,
        private readonly BotLog $botLog,
        private readonly BotRelations $relations,
    ) {}

    /**
     * Answers the private messages to the bot, one sender per turn. Unhandled player messages (not from bots or the bot, not
     * deleted, at least 30 seconds old, newer than the sender's relation pointer `last_handled_message_id`, or for players without a
     * relation the bot's legacy `last_message_id`) are grouped by sender. A sender is due when their oldest unhandled message is
     * at least `bot_reply_delay_hours` old; the most overdue one is handled: every message is recorded as a relation event, then
     * with the profile's `reply_chance` (percent), no DM block and `bot_chatter` on, ONE reply answers all of them (LLM with the
     * messages numbered plus the relation, else a canned reply by combined text and stance). In every case all of those
     * messages count as handled (pointer advanced, marked read). Returns true when a reply was sent.
     *
     * @param array<string, mixed> $ctx bot context built by BotSocial::act()
     */
    public function stepReply(array $ctx): bool
    {
        $db = $this->db;
        $bot = $ctx['bot'];
        $uni = $ctx['uni'];
        $now = time();
        $config = $this->configs->get($uni);
        $delay = max(0, (int) ($config->bot_reply_delay_hours ?? 3)) * 3600;

        // the bot's inbox is users_to_messages (a message can have several receivers; message_owner is only the first)
        $rows = $db->select(
            'SELECT m.message_id, m.message_group, m.message_sender, m.message_subject, m.message_text, m.message_time
             FROM %%MESSAGES%% m
             INNER JOIN %%USERS_TO_MESSAGES%% um ON um.message_id = m.message_id AND um.user_id = :bot
             LEFT JOIN %%BOT_RELATIONS%% r ON r.bot_id = :bot4 AND r.player_id = m.message_sender
             WHERE m.message_type = :type AND m.message_universe = :uni
               AND m.message_id > COALESCE(r.last_handled_message_id, CAST(:last AS UNSIGNED)) AND m.message_sender > 0 AND m.message_sender <> :bot2
               AND um.sender_id IS NOT NULL AND um.sender_id <> :bot3
               AND um.deleted_at IS NULL AND m.message_deleted IS NULL AND m.message_time <= :minAge
               AND NOT EXISTS (SELECT 1 FROM %%BOT_PLAYERS%% b WHERE b.user_id = m.message_sender)
             ORDER BY m.message_id ASC LIMIT 300;',
            [':bot' => $bot, ':bot2' => $bot, ':bot3' => $bot, ':bot4' => $bot, ':type' => MESSAGE_TYPE_USER, ':uni' => $uni, ':last' => (int) ($ctx['row']['last_message_id'] ?? 0), ':minAge' => $now - 30],
        );
        if (empty($rows)) {
            return false;
        }

        $bySender = [];
        foreach ($rows as $row) {
            $bySender[(int) $row['message_sender']][] = $row;
        }
        $senderId = 0;
        $oldest = PHP_INT_MAX;
        foreach ($bySender as $id => $list) {
            $age = min(array_map(static fn(array $m): int => (int) $m['message_time'], $list));
            if ($age <= $now - $delay && $age < $oldest) {
                $oldest = $age;
                $senderId = $id;
            }
        }
        if ($senderId === 0) {
            return false;
        }
        $msgs = $bySender[$senderId];
        $lastId = (int) end($msgs)['message_id'];
        $ids = array_map(static fn(array $m): int => (int) $m['message_id'], $msgs);

        foreach ($msgs as $unused) {
            $this->relations->record($bot, $senderId, $uni, BotRelations::EVENT_MESSAGE);
        }
        $relation = $this->relations->get($bot, $senderId);
        $stance = $this->relations->stance($relation);
        $replied = false;

        $chance = (int) ($ctx['profile']['reply_chance'] ?? 80);
        $blocked = $this->playerUtil()->get_block_status($bot, $senderId)['block_dm'] == 1
            || $this->playerUtil()->get_block_status($senderId, $bot)['block_dm'] == 1;

        if (empty($config->bot_chatter)) {
            $this->botLog->log($bot, $uni, 'reply', 'chatter off, handled ' . count($msgs) . ' msg of #' . $senderId);
        } elseif ($blocked || mt_rand(1, 100) > $chance) {
            $this->botLog->log($bot, $uni, 'reply', 'skipped #' . $senderId . ' (' . count($msgs) . ' msg, ' . ($blocked ? 'dm block' : 'roll') . ')');
        } else {
            $numbered = [];
            $combined = '';
            foreach ($msgs as $i => $m) {
                $body = self::plain((string) $m['message_text'], 400);
                $subj = self::plain((string) $m['message_subject'], 100);
                $numbered[] = ($i + 1) . '. Subject: ' . $subj . ' | Message: ' . $body;
                $combined .= ' ' . $body;
            }
            $combined = trim($combined);
            $subjectPlain = self::plain((string) $msgs[0]['message_subject'], 200);
            $lang = self::detectLanguage($combined, $ctx['persona']['language'] ?? 'de');
            $text = null;

            $tone = match ($stance) {
                'hostile' => 'You dislike this player (curt, cold, may warn them off, but no insults or real threats).',
                'friendly' => 'You like this player (warm and friendly).',
                default => 'You have no strong feelings about this player.',
            };
            $prompt = 'Another player wrote you ' . count($msgs) . " private message(s), oldest first:\n" . implode("\n", $numbered)
                . "\nRelationship: " . $this->relations->summary($relation) . '. ' . $tone
                . "\nYour situation: " . $this->situation($ctx)
                . "\nWrite ONE reply that answers all messages, in " . ($lang === 'de' ? 'German' : 'English') . ', max 400 characters.';
            $llm = $this->persona->ask($uni, $bot, self::systemPrompt($ctx, 400), $prompt, 300);
            if ($llm !== null) {
                $clean = BotPersona::cleanText($llm, 400);
                if (BotPersona::looksBroken($clean)) {
                    $this->botLog->log($bot, $uni, 'llm_error', 'reply rejected');
                } else {
                    $text = $clean;
                }
            }
            if ($text === null) {
                $text = self::cannedReply($combined, $lang, $ctx['persona'], $stance);
            }

            $group = !empty($msgs[0]['message_group']) ? (string) $msgs[0]['message_group'] : null;
            $replied = $this->send($ctx, $senderId, 'Re: ' . self::trimSubject($subjectPlain), $text, $group);
            if ($replied) {
                $this->relations->markBotMessage($bot, $senderId, $uni);
            }
            $this->botLog->log($bot, $uni, 'reply', ($replied ? 'to #' : 'skipped #') . $senderId . ' (' . count($msgs) . ' msg, ' . $stance . ')' . ($llm !== null ? ' llm' : ''));
        }

        // handled: advance the sender's pointer and mark read
        $this->relations->markHandled($bot, $senderId, $uni, $lastId);
        $in = implode(',', $ids);
        $db->update('UPDATE %%MESSAGES%% SET message_unread = 0 WHERE message_id IN (' . $in . ') AND message_owner = :bot;', [':bot' => $bot]);
        $db->update('UPDATE %%USERS_TO_MESSAGES%% SET message_unread = 0 WHERE message_id IN (' . $in . ') AND user_id = :bot;', [':bot' => $bot]);

        return $replied;
    }

    /**
     * Notices attacks and spy probes on the bot since the last check, from the fleet log (`log_fleets`: target_owner = bot,
     * missions attack/ACS/destroy and espionage, arrival time inside the window, sender is a human). The window starts at
     * `bot_players.last_threat_check` (at most one hour back) and ends now. Every probe/attack is a relation event; after an attack the
     * bot reacts (see reactToAttack()). Returns true when a reaction was sent.
     *
     * @param array<string, mixed> $ctx bot context
     */
    public function stepThreats(array $ctx): bool
    {
        $db = $this->db;
        $bot = $ctx['bot'];
        $uni = $ctx['uni'];
        $now = time();
        $from = max((int) $db->selectSingle('SELECT last_threat_check FROM %%BOT_PLAYERS%% WHERE user_id = :bot;', [':bot' => $bot], 'last_threat_check'), $now - 3600);
        $db->update('UPDATE %%BOT_PLAYERS%% SET last_threat_check = :now WHERE user_id = :bot;', [':now' => $now, ':bot' => $bot]);

        $rows = $db->select(
            'SELECT l.owner, l.mission FROM %%LOG_FLEETS%% l
             WHERE l.target_owner = :bot AND l.universe = :uni AND l.owner <> :bot2 AND l.mission IN (1, 2, 6, 9)
               AND l.arrival_time > :from AND l.arrival_time <= :now
               AND NOT EXISTS (SELECT 1 FROM %%BOT_PLAYERS%% b WHERE b.user_id = l.owner)
             ORDER BY l.arrival_time ASC;',
            [':bot' => $bot, ':bot2' => $bot, ':uni' => $uni, ':from' => $from, ':now' => $now],
        );
        $attackers = [];
        foreach ($rows as $row) {
            $player = (int) $row['owner'];
            if ((int) $row['mission'] === 6) {
                $this->relations->record($bot, $player, $uni, BotRelations::EVENT_PLAYER_SPIED);
            } else {
                $this->relations->record($bot, $player, $uni, BotRelations::EVENT_PLAYER_ATTACKED);
                $attackers[$player] = true;
            }
        }

        $sent = false;
        foreach (array_keys($attackers) as $player) {
            $sent = $this->reactToAttack($ctx, $player) || $sent;
        }

        return $sent;
    }

    /**
     * Short reaction to a player who attacked the bot, canned by stance and language. Sent regardless of `bot_chatter`, only if the
     * player is contacted (always true after an attack), no DM block exists and the bot did not write to them within
     * `bot_reply_delay_hours`. Returns true when sent.
     *
     * @param array<string, mixed> $ctx bot context
     */
    private function reactToAttack(array $ctx, int $player): bool
    {
        $bot = $ctx['bot'];
        $uni = $ctx['uni'];
        $relation = $this->relations->get($bot, $player);
        if (empty($relation['player_contacted']) || !$this->throttleOpen($relation, $uni)) {
            $this->botLog->log($bot, $uni, 'attack_reaction', 'none for #' . $player . ' (throttled)');

            return false;
        }
        if ($this->dmBlocked($bot, $player)) {
            return false;
        }
        $stance = $this->relations->stance($relation);
        $targetLang = (string) $this->db->selectSingle('SELECT lang FROM %%USERS_SETTINGS%% WHERE user_id = :id;', [':id' => $player], 'lang');
        $lang = $this->pickLanguage($ctx, self::ATTACK_REACTIONS[$stance], $targetLang);
        [$subject, $text] = self::pick(self::ATTACK_REACTIONS[$stance][$lang]);
        $sent = $this->send($ctx, $player, $subject, self::stylize($text, $ctx['persona']), null);
        if ($sent) {
            $this->relations->markBotMessage($bot, $player, $uni);
        }
        $this->botLog->log($bot, $uni, 'attack_reaction', ($sent ? 'reacted to #' : 'no reaction for #') . $player . ' (' . $stance . ')');

        return $sent;
    }

    /** Whether the bot may write to the player again: `bot_reply_delay_hours` since its last message to them. */
    private function throttleOpen(array $relation, int $uni): bool
    {
        $delay = max(0, (int) ($this->configs->get($uni)->bot_reply_delay_hours ?? 3)) * 3600;

        return time() - (int) ($relation['last_bot_message_at'] ?? 0) >= $delay;
    }

    /** True when either side blocks direct messages. */
    private function dmBlocked(int $bot, int $player): bool
    {
        return $this->playerUtil()->get_block_status($bot, $player)['block_dm'] == 1
            || $this->playerUtil()->get_block_status($player, $bot)['block_dm'] == 1;
    }

    /** Persona language if the phrase set has it, else the target's, else 'de'. */
    private function pickLanguage(array $ctx, array $set, string $targetLang): string
    {
        $lang = $ctx['persona']['language'] ?? $targetLang;

        return isset($set[$lang]) ? $lang : (isset($set[$targetLang]) ? $targetLang : 'de');
    }

    /**
     * Short taunt after a successful raid, to the raided player: a canned phrase in the bot's language ('de' or 'en', else the
     * target's, else German), styled like the persona writes. Only goes to players who contacted the bot before (it never opens
     * contact) and only if `bot_reply_delay_hours` passed since its last message to them. Refused DM blocks and forbidden
     * texts return false.
     *
     * @param array<string, mixed> $ctx bot context (bot, uni, me, persona, profile; see BotSocial::act())
     */
    public function taunt(array $ctx, int $receiverId, string $targetLang): bool
    {
        $bot = (int) $ctx['bot'];
        $relation = $this->relations->get($bot, $receiverId);
        if (empty($relation['player_contacted']) || !$this->throttleOpen($relation, (int) $ctx['uni']) || $this->dmBlocked($bot, $receiverId)) {
            return false;
        }
        $lang = $this->pickLanguage($ctx, self::TAUNTS, $targetLang);
        [$subject, $text] = self::pick(self::TAUNTS[$lang]);
        $sent = $this->send($ctx, $receiverId, $subject, self::stylize($text, $ctx['persona']), null);
        if ($sent) {
            $this->relations->markBotMessage($bot, $receiverId, (int) $ctx['uni']);
        }

        return $sent;
    }

    // ------------------------------------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------------------------------------

    /**
     * Whether an admin-entered forbidden-text pattern matches. A pattern that is not a valid regex counts as
     * "no match": PCRE's compile warning is swallowed for this one call only (there is no way to validate a
     * pattern up front without triggering it).
     */
    private function forbiddenMatch(string $regex, string $text): bool
    {
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            return preg_match($regex, $text) === 1;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Sends a private message from the bot (type MESSAGE_TYPE_USER, sender label "name [g:s:p]"). `$text`/`$subject` are raw text,
     * cleaned (600/60 characters) and escaped here; `$group` continues a conversation. Refuses (returns false) when the text
     * is empty or matches the game's forbidden-text patterns, so a bot never sends what would get a player banned.
     */
    public function send(array $ctx, int $receiverId, string $subject, string $text, ?string $group): bool
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
                if ($this->forbiddenMatch($regex, $text) || $this->forbiddenMatch($regex, $subject)) {
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

    /** One-sentence status of the bot (rank, points, planet count) for LLM prompts; '' when it cannot be read. */
    public function situation(array $ctx): string
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

    /**
     * System prompt that makes the LLM write as the bot's persona (bio, tone, age hint, quirks, profile chat style) and
     * never reveal being a bot; the output is limited to `$maxLen` characters.
     *
     * @param array<string, mixed> $ctx bot context (me, persona, profile)
     */
    public static function systemPrompt(array $ctx, int $maxLen): string
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

    /** HTML message text to plain text: <br> to newline, tags stripped, entities decoded, cut to `$max` characters. */
    private static function plain(string $html, int $max): string
    {
        $text = preg_replace('~<br\s*/?>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');

        return trim(mb_substr($text, 0, $max));
    }

    /** Subject without leading "Re:" prefixes (so replies do not stack them), '-' if empty, max 40 characters. */
    private static function trimSubject(string $subject): string
    {
        $subject = trim(preg_replace('/^(\s*re:\s*)+/i', '', $subject));
        if ($subject === '') {
            $subject = '-';
        }

        return mb_substr($subject, 0, 40);
    }

    /** 'de' or 'en' by counting typical words of each language; a tie falls back to the persona language (or 'de'). */
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

    /** Fixed-phrase reply chosen by keyword intent (attack, trade, alliance, question, greeting, default) and stance (hostile always, friendly for small talk) in the given language, then stylized. */
    private static function cannedReply(string $incoming, string $lang, array $persona, string $stance = 'neutral'): string
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
        if ($stance === 'hostile' || ($stance === 'friendly' && in_array($intent, ['default', 'greeting', 'question'], true))) {
            $intent = $stance;
        }
        $text = self::pick(self::REPLIES[$intent][$lang] ?? self::REPLIES[$intent]['en']);

        return self::stylize($text, $persona);
    }

    /** Applies the persona's typo rate and the quirks "lowercase" / "no punctuation" (also German wording) to a text. */
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

    /** Random element of a non-empty list. */
    public static function pick(array $list): mixed
    {
        return $list[array_rand($list)];
    }
}
