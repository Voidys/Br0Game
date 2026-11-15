<?php

namespace App\Game\Core;

use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * One-time codes of the wiki single sign-on: the game stores the logged-in account under a
 * random code (WikiLoginController), the wiki resolves it once server-to-server
 * (App\Controller\WikiSsoResolveController). Codes expire after self::TTL seconds.
 *
 * Backend from config/game.php: $wikiSsoCodeStore = 'file' (var/game/wiki_sso/) or 'redis'
 * ($wikiSsoRedisUrl).
 */
final class WikiSsoCodeStore
{
    public const TTL = 120;

    private function __construct(private readonly AdapterInterface $cache) {}

    public static function create(string $store, string $redisUrl = '', ?string $directory = null): self
    {
        if ($store === 'redis') {
            return new self(new RedisAdapter(RedisAdapter::createConnection($redisUrl), 'wiki_sso', self::TTL));
        }

        return new self(new FilesystemAdapter('', self::TTL, $directory ?? dirname(__DIR__, 3) . '/var/game/wiki_sso'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(string $code, array $payload): void
    {
        $item = $this->cache->getItem($code);
        $item->set($payload)->expiresAfter(self::TTL);
        $this->cache->save($item);
    }

    /**
     * Returns the payload and invalidates the code; null if unknown, used or expired.
     *
     * @return array<string, mixed>|null
     */
    public function consume(string $code): ?array
    {
        $item = $this->cache->getItem($code);
        if (!$item->isHit()) {
            return null;
        }
        $this->cache->deleteItem($code);
        $payload = $item->get();

        return is_array($payload) ? $payload : null;
    }
}
