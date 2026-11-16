<?php

namespace App\Game\Bots;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use Throwable;

/**
 * Messaging part of the bot social behaviour: answers private messages with a reply picked from the situation of the
 * message (language, topic), greets neighbours, and sends the messages (same forbidden-text rules as for players).
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
    ) {}

    /**
     * Answers the oldest unhandled private message to the bot: a player message (not from a bot or itself, not deleted, at least 30
     * seconds old, newer than `last_message_id`). With the profile's `reply_chance` (percent) and no DM block between the
     * two, the reply is written by the LLM (if configured and sane) or picked from canned replies by intent and
     * language (detectLanguage(), cannedReply()). In every case the message counts as handled: the pointer
     * `last_message_id` advances and the message is marked read. Returns true when a reply was sent.
     *
     * @param array<string, mixed> $ctx bot context built by BotSocial::act()
     */
    public function stepReply(array $ctx): bool
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

    /**
     * Unprompted greeting to a neighbour: with `message_chance` (percent), at most 5 player messages per 24 h, to one random human
     * who was online within 30 days, plays in the same galaxy within 10 systems and was not messaged by this bot in
     * the last 7 days. LLM text with fallback to a canned greeting; blocked players are skipped. Returns true when sent.
     *
     * @param array<string, mixed> $ctx bot context
     */
    public function stepMessage(array $ctx): bool
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
               AND u.onlinetime > :online AND u.authlevel = 0 AND u.urlaubs_modus = 0 AND u.banaday < :nowban
               AND NOT EXISTS (SELECT 1 FROM %%BOT_PLAYERS%% b WHERE b.user_id = u.id)
               AND NOT EXISTS (SELECT 1 FROM %%MESSAGES%% m WHERE m.message_sender = :bot AND m.message_owner = u.id
                               AND m.message_type = :t AND m.message_time > :week)
             ORDER BY RAND() LIMIT 1;',
            [
                ':uni' => $uni, ':bot' => $bot, ':gal' => (int) $ctx['me']['galaxy'],
                ':s1' => (int) $ctx['me']['system'] - 10, ':s2' => (int) $ctx['me']['system'] + 10,
                ':online' => $now - 30 * 86400, ':nowban' => $now, ':t' => MESSAGE_TYPE_USER, ':week' => $now - 7 * 86400,
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

    /**
     * Short taunt after a successful raid, to the raided player: a canned phrase in the bot's language ('de' or 'en', else the
     * target's, else German), styled like the persona writes. Refused DM blocks and forbidden texts return false.
     *
     * @param array<string, mixed> $ctx bot context (bot, uni, me, persona, profile; see BotSocial::act())
     */
    public function taunt(array $ctx, int $receiverId, string $targetLang): bool
    {
        $bot = (int) $ctx['bot'];
        if (
            $this->playerUtil()->get_block_status($bot, $receiverId)['block_dm'] == 1
            || $this->playerUtil()->get_block_status($receiverId, $bot)['block_dm'] == 1
        ) {
            return false;
        }
        $lang = $ctx['persona']['language'] ?? $targetLang;
        $lang = isset(self::TAUNTS[$lang]) ? $lang : (isset(self::TAUNTS[$targetLang]) ? $targetLang : 'de');
        [$subject, $text] = self::pick(self::TAUNTS[$lang]);

        return $this->send($ctx, $receiverId, $subject, self::stylize($text, $ctx['persona']), null);
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

    /** Fixed-phrase reply chosen by keyword intent (attack, trade, alliance, question, greeting, default) in the given language, then stylized. */
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
