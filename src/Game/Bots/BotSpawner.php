<?php

namespace App\Game\Bots;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use Throwable;

/**
 * Creates and removes bot players (account + user + home planet + bot_players row).
 */
class BotSpawner
{
    use GameUtilities;

    public const PERSONALITY_CATEGORIES = ['mines', 'energy', 'storage', 'facilities', 'research', 'ships', 'defense'];

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly BotProfiles $profiles,
        private readonly BotPersona $persona,
        private readonly BotNameGenerator $names,
        private readonly BotLog $botLog,
    ) {}

    /**
     * Creates up to $count bots (capped by bots_max_count) with the given profile.
     *
     * @return int[] ids of the created users
     */
    public function spawn(int $universe, int $profileId, int $count): array
    {
        $config = $this->configs->get($universe);
        $profile = $this->profiles->load($profileId);
        if ($profile === null) {
            throw new \Exception('Unknown bot profile ' . $profileId);
        }

        // the count check and the inserts of concurrent spawn requests of a universe must not interleave
        $lockName = $this->db->getDatabaseName() . ':bots_spawn_' . $universe;
        if (!(int) $this->db->selectSingle('SELECT GET_LOCK(:name, 10) AS acquired;', [':name' => $lockName], 'acquired')) {
            throw new \Exception('Another bot spawn is running, try again in a moment.');
        }
        try {
            return $this->spawnLocked($universe, $profileId, $count, $profile, $config);
        } finally {
            $this->db->selectSingle('SELECT RELEASE_LOCK(:name) AS released;', [':name' => $lockName], 'released');
        }
    }

    /**
     * spawn() with the universe's spawn lock held: counts the existing bots, then creates up to the allowed number.
     *
     * @param array<string, mixed> $profile
     * @return int[] ids of the created users
     */
    private function spawnLocked(int $universe, int $profileId, int $count, array $profile, mixed $config): array
    {
        $existing = (int) $this->db->selectSingle(
            'SELECT COUNT(*) as count FROM %%BOT_PLAYERS%% WHERE universe = :universe;',
            [':universe' => $universe],
            'count',
        );
        $max = max(0, (int) $config->bots_max_count);
        $count = max(0, min($count, $max - $existing));

        $created = [];
        $lastError = null;
        for ($i = 0; $i < $count; $i++) {
            try {
                $created[] = $this->spawnOne($universe, $profile);
            } catch (Throwable $e) {
                $lastError = $e;
                error_log('BotSpawner: ' . $e->getMessage());
            }
        }

        if (empty($created) && $lastError !== null) {
            throw $lastError;
        }

        return $created;
    }

    /**
     * Creates one bot: nickname and persona (LLM or local), an account with a random password and a placeholder address
     * (`...@bots.invalid`, never used to log in), the player with a home planet (account is removed again if that fails), the
     * bot_players row (skill from the profile's `skill_start`, random category preferences 0.7-1.3 as `personality`, first tick within
     * 10 minutes) and, when a persona exists, a persona-style home planet name. Writes a 'spawn' log entry.
     *
     * @param array<string, mixed> $profile profile as returned by BotProfiles::load()
     * @return int id of the new user
     */
    private function spawnOne(int $universe, array $profile): int
    {
        $s = $profile['settings'];

        [$name, $persona] = $this->chooseIdentity($universe, $profile);

        // account, player, home planet and bot row succeed or fail together (no half-created player without bot row)
        [$userId, $planetId] = $this->db->transactional(function () use ($universe, $name, $s, $profile, $persona): array {
            [$userId, $planetId] = $this->createPlayerAccount($universe, $name);
            $this->insertBotRow($universe, $profile, (int) $userId, $s, $persona);

            return [$userId, $planetId];
        });

        if ($persona !== null) {
            try {
                $botRow = $this->db->selectSingle('SELECT * FROM %%BOT_PLAYERS%% WHERE user_id = :id;', [':id' => $userId]);
                $this->persona->renamePlanet((int) $planetId, $this->persona->planetName($botRow, $persona, true));
            } catch (Throwable $e) {
                error_log('BotSpawner planet name: ' . $e->getMessage());
            }
        }

        $this->botLog->log($userId, $universe, 'spawn', substr('profile ' . $profile['row']['profile_key'] . ' as ' . $name, 0, 255));

        return (int) $userId;
    }

    /**
     * Inserts the bot_players row of a new bot: skill from the profile's `skill_start`, random category preferences 0.7-1.3 as
     * `personality`, first tick within 10 minutes.
     *
     * @param array<string, mixed> $profile profile as returned by BotProfiles::load()
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed>|null $persona
     */
    private function insertBotRow(int $universe, array $profile, int $userId, array $s, ?array $persona): void
    {
        $personality = ['patience' => round(0.7 + mt_rand() / mt_getrandmax() * 0.6, 2)];
        foreach (self::PERSONALITY_CATEGORIES as $cat) {
            $personality[$cat] = round(0.7 + mt_rand() / mt_getrandmax() * 0.6, 2);
        }

        $now = time();
        $this->db->insert(
            'INSERT INTO %%BOT_PLAYERS%% SET
                user_id = :userId, universe = :universe, profile_id = :profileId, skill = :skill,
                personality = :personality, next_tick = :nextTick, persona = :persona, created_at = :now;',
            [
                ':userId' => $userId,
                ':universe' => $universe,
                ':profileId' => $profile['row']['id'],
                ':skill' => (float) $s['skill_start'],
                ':personality' => json_encode($personality),
                ':nextTick' => $now + random_int(0, 600),
                ':persona' => $persona !== null ? json_encode($persona) : null,
                ':now' => $now,
            ],
        );
    }

    /**
     * Picks the nickname and persona of a new bot: the generated (LLM or local) persona when its nickname is usable,
     * otherwise a generated name without persona. A failing persona generator is logged and ignored.
     *
     * @param array<string, mixed> $profile profile as returned by BotProfiles::load()
     * @return array{0: string, 1: array<string, mixed>|null} name, persona
     */
    private function chooseIdentity(int $universe, array $profile): array
    {
        $persona = null;
        $name = null;
        try {
            $generated = $this->persona->generate($universe, $profile['row']);
            if (!empty($generated['nickname']) && $this->names->isUsable((string) $generated['nickname'], $universe)) {
                $name = (string) $generated['nickname'];
                $persona = $generated['persona'] ?? null;
            }
        } catch (Throwable $e) {
            error_log('BotSpawner persona: ' . $e->getMessage());
        }
        if ($name === null) {
            $name = $this->names->generate($universe);
        }

        return [$name, $persona];
    }

    /**
     * Creates the account (random password, placeholder address `...@bots.invalid`, the universe's language and time
     * zone) and the player with a home planet "Home"; the account is removed again when the player cannot be created.
     *
     * @return array{0: int|string, 1: int|string} user id, home planet id
     */
    private function createPlayerAccount(int $universe, string $name): array
    {
        $config = $this->configs->get($universe);
        $password = bin2hex(random_bytes(16));
        $email = 'bot-' . bin2hex(random_bytes(8)) . '@bots.invalid';
        $lang = (string) $config->lang;

        $accountId = $this->accountUtil()->createAccount(
            $name,
            $this->accountUtil()->cryptPassword($password),
            $email,
            $lang,
            0,
            '0.0.0.0',
            (string) $config->timezone,
        );

        try {
            [$userId, $planetId] = $this->playerUtil()->createPlayer(
                $accountId,
                $universe,
                $name,
                $lang,
                null,
                null,
                null,
                'Home',
                0,
                '0.0.0.0',
            );
        } catch (Throwable $e) {
            $this->db->delete('DELETE FROM %%ACCOUNTS%% WHERE id = :id;', [':id' => $accountId]);
            throw $e;
        }

        return [$userId, $planetId];
    }

    /**
     * Removes a bot completely: the player (via deletePlayer()), its account when no other player uses it (never the root
     * account), and the bot_players and bot_log rows.
     */
    public function remove(int $userId): void
    {
        $db = $this->db;

        $accountId = (int) $db->selectSingle(
            'SELECT account_id FROM %%ACCOUNTS_TO_USERS%% WHERE user_id = :id;',
            [':id' => $userId],
            'account_id',
        );

        $this->playerUtil()->deletePlayer($userId);

        if ($accountId > 0) {
            $others = (int) $db->selectSingle(
                'SELECT COUNT(*) as count FROM %%ACCOUNTS_TO_USERS%% WHERE account_id = :id;',
                [':id' => $accountId],
                'count',
            );
            if ($others === 0 && $accountId !== ROOT_USER) {
                $db->delete('DELETE FROM %%ACCOUNTS%% WHERE id = :id;', [':id' => $accountId]);
                $db->delete('DELETE FROM %%ACCOUNTS_BANNED%% WHERE account_id = :id;', [':id' => $accountId]);
            }
        }

        $db->delete('DELETE FROM %%BOT_PLAYERS%% WHERE user_id = :id;', [':id' => $userId]);
        $db->delete('DELETE FROM %%BOT_LOG%% WHERE user_id = :id;', [':id' => $userId]);
    }
}
