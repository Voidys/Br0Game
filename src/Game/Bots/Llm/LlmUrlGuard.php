<?php

declare(strict_types=1);

namespace App\Game\Bots\Llm;

/**
 * SSRF guard for the LLM base URL: only http(s) without credentials, and the host must resolve to public addresses
 * only (no loopback, private, link-local/metadata or reserved ranges, IPv4 and IPv6). The result carries the checked
 * addresses so the HTTP client can be pinned to them (no second DNS lookup that could answer differently).
 */
final class LlmUrlGuard
{
    /** @var \Closure(string): list<string> host name => its IP addresses */
    private readonly \Closure $resolver;

    /** @param (\Closure(string): list<string>)|null $resolver replaces the DNS lookup (tests) */
    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::dnsLookup(...);
    }

    /**
     * Checks the URL and returns the host with its (public) addresses.
     *
     * @return array{host: string, ips: list<string>}
     * @throws LlmException for a bad scheme, credentials, an unresolvable host or any non-public address
     */
    public function check(string $url): array
    {
        $parts = parse_url(trim($url));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new LlmException('Invalid base URL.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw new LlmException('The base URL host cannot be resolved.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublic($ip)) {
                throw new LlmException('The base URL points to a private or reserved address.');
            }
        }

        return ['host' => $host, 'ips' => array_values($ips)];
    }

    /** True for a globally routable address (IPv4-mapped IPv6 is judged by its IPv4 part). */
    public static function isPublic(string $ip): bool
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m) === 1) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // ranges PHP's flags miss: carrier-grade NAT, IPv6 unique-local/link-local/loopback/unspecified/documentation, 6to4/Teredo-style tunnels
        if (str_contains($ip, ':')) {
            $packed = inet_pton($ip);
            $first = ord($packed[0]);
            $second = ord($packed[1]);

            return !($packed === inet_pton('::') || $packed === inet_pton('::1') || ($first & 0xfe) === 0xfc || ($first === 0xfe && ($second & 0xc0) === 0x80)
                || ($first === 0x20 && $second === 0x01 && ord($packed[2]) === 0x0d && ord($packed[3]) === 0xb8));
        }
        $long = ip2long($ip);

        return !($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
    }

    /** @return list<string> */
    private static function dnsLookup(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ips[] = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
        }

        return array_values(array_filter($ips));
    }
}
