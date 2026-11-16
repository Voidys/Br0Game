<?php

namespace App\Game\Core;

/**
 * One-time codes of the wiki single sign-on: the game stores the logged-in account under a
 * random code (WikiLoginController), the wiki resolves it once server-to-server
 * (App\Controller\WikiSsoResolveController). Codes expire after self::TTL seconds.
 *
 * Backend from config/game.php: $wikiSsoCodeStore = 'file' (var/game/wiki_sso/) or 'redis'
 * ($wikiSsoRedisUrl). consume() is atomic in both: a code is handed out to exactly one caller (file: the
 * rename of the code's file succeeds once; redis: GETDEL).
 */
final class WikiSsoCodeStore
{
    public const TTL = 120;

    /** @param \Redis|\Predis\ClientInterface|null $redis connection of the redis backend, null = filesystem */
    private function __construct(
        private readonly ?object $redis,
        private readonly string $directory,
    ) {}

    /**
     * Builds the store.
     * @param string $store 'redis' or anything else for the filesystem.
     * @param string $redisUrl Redis DSN, used with 'redis'.
     * @param string|null $directory filesystem folder (default var/game/wiki_sso).
     */
    public static function create(string $store, string $redisUrl = '', ?string $directory = null): self
    {
        if ($store === 'redis') {
            return new self(\Symfony\Component\Cache\Adapter\RedisAdapter::createConnection($redisUrl), '');
        }

        return new self(null, $directory ?? dirname(__DIR__, 3) . '/var/game/wiki_sso');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(string $code, array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        if ($this->redis !== null) {
            $this->redis->setex($this->key($code), self::TTL, $json);

            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create ' . $this->directory);
        }
        $this->purgeExpired();
        file_put_contents($this->file($code), json_encode(['expires' => time() + self::TTL, 'payload' => $payload], JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /**
     * Returns the payload and invalidates the code; null if unknown, used or expired.
     *
     * @return array<string, mixed>|null
     */
    public function consume(string $code): ?array
    {
        if ($this->redis !== null) {
            $json = $this->redis->getDel($this->key($code));
            $payload = is_string($json) ? json_decode($json, true) : null;

            return is_array($payload) ? $payload : null;
        }

        // rename() is atomic: of several parallel callers exactly one moves the file away and gets the payload
        $file = $this->file($code);
        $claimed = $file . '.' . bin2hex(random_bytes(4)) . '.used';
        if (!@rename($file, $claimed)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($claimed), true);
        @unlink($claimed);
        if (!is_array($data) || (int) ($data['expires'] ?? 0) < time() || !is_array($data['payload'] ?? null)) {
            return null;
        }

        return $data['payload'];
    }

    private function key(string $code): string
    {
        return 'wiki_sso:' . hash('sha256', $code);
    }

    private function file(string $code): string
    {
        return $this->directory . '/' . hash('sha256', $code) . '.json';
    }

    /** removes code files that expired (and claimed leftovers) so the folder does not grow */
    private function purgeExpired(): void
    {
        foreach (glob($this->directory . '/*.{json,used}', GLOB_BRACE) ?: [] as $file) {
            if (@filemtime($file) < time() - 2 * self::TTL) {
                @unlink($file);
            }
        }
    }
}
