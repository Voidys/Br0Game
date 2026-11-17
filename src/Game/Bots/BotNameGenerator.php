<?php

namespace App\Game\Bots;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Generates human-looking, unique usernames for bots.
 */
class BotNameGenerator
{
    use GameUtilities;

    private const WORDS = [
        'shadow', 'night', 'wolf', 'storm', 'pixel', 'turbo', 'galaxy', 'nova', 'cosmo', 'rocket',
        'phoenix', 'dragon', 'ghost', 'iron', 'steel', 'crimson', 'frost', 'blaze', 'viper', 'raven',
        'falcon', 'comet', 'orbit', 'lunar', 'solar', 'cyber', 'neo', 'alpha', 'omega', 'delta',
        'ninja', 'pirate', 'captain', 'admiral', 'commander', 'hunter', 'raider', 'miner', 'trader', 'nomad',
        'toxic', 'happy', 'lazy', 'crazy', 'silent', 'mad', 'dark', 'lucky', 'tiny', 'big',
        'kaiser', 'baron', 'vader', 'jedi', 'pilot', 'rebel', 'zero', 'echo', 'drift', 'spark',
        'bear', 'fox', 'hawk', 'tiger', 'panda', 'koala', 'otter', 'moose', 'badger', 'lynx',
        'thunder', 'glitch', 'static', 'quantum', 'stellar', 'void', 'ember', 'apex', 'titan', 'atlas',
    ];

    private const FIRST_NAMES = [
        'max', 'tim', 'jan', 'lukas', 'finn', 'jonas', 'leon', 'paul', 'tom', 'ben', 'felix', 'nico',
        'alex', 'chris', 'mike', 'dave', 'steve', 'kevin', 'sven', 'tobi', 'flo', 'basti', 'daniel',
        'anna', 'lisa', 'julia', 'nina', 'sarah', 'lena', 'mia', 'laura', 'jana', 'kim', 'sophie',
    ];

    private const SUFFIXES = ['xx', 'x', 'pro', 'hd', 'gg', 'tv', 'one', 'real', 'official', '1337'];

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
    ) {}

    /**
     * Returns a valid username that is not yet taken in the given universe.
     */
    public function generate(int $universe): string
    {
        for ($i = 0; $i < 60; $i++) {
            $name = self::candidate();
            if ($this->isUsable($name, $universe)) {
                return $name;
            }
        }

        // extremely unlikely: fall back to a random handle that is unique by construction
        do {
            $name = 'user' . random_int(100000, 99999999);
        } while (!$this->isUsable($name, $universe));

        return $name;
    }

    /**
     * Random element of a non-empty list.
     *
     * @param array<mixed> $list
     */
    private static function pick(array $list): string
    {
        return $list[random_int(0, count($list) - 1)];
    }

    /** One random handle in one of ten styles (word combinations, first names, numbers/years, suffixes, sometimes leetspeak), max 24 characters; availability is not checked. */
    private static function candidate(): string
    {
        $number = static function (): string {
            return match (random_int(0, 5)) {
                0 => (string) random_int(1, 99),
                1 => (string) random_int(70, 99),
                2 => (string) random_int(1985, 2012),
                3 => (string) random_int(1, 9),
                4 => (string) random_int(100, 999),
                default => '',
            };
        };

        $style = random_int(0, 9);
        switch ($style) {
            case 0:
                $name = self::pick(self::WORDS) . self::pick(self::WORDS) . $number();
                break;
            case 1:
                $name = self::pick(self::WORDS) . '_' . self::pick(self::WORDS);
                break;
            case 2:
                $name = self::pick(self::FIRST_NAMES) . $number();
                break;
            case 3:
                $name = self::pick(self::FIRST_NAMES) . '_' . self::pick(self::WORDS) . $number();
                break;
            case 4:
                $name = self::pick(self::WORDS) . $number();
                break;
            case 5:
                $name = ucfirst(self::pick(self::WORDS)) . ucfirst(self::pick(self::WORDS));
                break;
            case 6:
                $name = ucfirst(self::pick(self::FIRST_NAMES)) . ucfirst(self::pick(self::WORDS)) . $number();
                break;
            case 7:
                $name = self::pick(self::WORDS) . '.' . self::pick(self::FIRST_NAMES);
                break;
            case 8:
                $name = strtoupper(self::pick(self::WORDS)) . $number();
                break;
            default:
                $name = self::pick(self::FIRST_NAMES) . self::pick(self::SUFFIXES);
                break;
        }

        // some handles get leetspeak, like real gamers do
        if (random_int(1, 100) <= 8) {
            $name = strtr($name, ['a' => '4', 'e' => '3', 'o' => '0']);
        }

        return substr($name, 0, 24);
    }

    /** Whether the name is free and acceptable: 3+ characters, no "bot", valid game name, unused as player name in the universe and as account name. */
    public function isUsable(string $name, int $universe): bool
    {
        if (strlen($name) < 3 || stripos($name, 'bot') !== false) {
            return false;
        }

        if (!$this->playerRegistration()->isNameValid($name)) {
            return false;
        }

        $db = $this->db;
        $inUsers = $db->selectSingle(
            'SELECT COUNT(*) as count FROM %%USERS%% WHERE universe = :universe AND username = :name;',
            [':universe' => $universe, ':name' => $name],
            'count',
        );
        $inAccounts = $db->selectSingle(
            'SELECT COUNT(*) as count FROM %%ACCOUNTS%% WHERE accountname = :name;',
            [':name' => $name],
            'count',
        );

        return $inUsers == 0 && $inAccounts == 0;
    }
}
