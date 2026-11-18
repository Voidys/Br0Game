<?php

namespace App\Game\Cronjob;

use RuntimeException;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\DisposableEmailDomainBlocklist;
use App\Game\Core\Singleton;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Keeps the list of disposable e-mail domains (used to refuse registrations) current; daily 03:00.
 */
class DisposableEmailDomainsCronjob extends AbstractCronjobTask
{
    private const SOURCE_URL = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/master/disposable_email_blocklist.conf';

    public function __construct(
        Database $db,
        ConfigRegistry $configs,
        Singleton $state,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct($db, $configs, $state);
    }

    /**
     * Downloads the public disposable-email blocklist (GET, https only, 10 s idle / 30 s total, max 10 MB,
     * redirects followed) and caches it via DisposableEmailDomainBlocklist.
     *
     * @throws RuntimeException on transport error, non-2xx status or an invalid list
     */
    public function run(): void
    {
        try {
            $response = $this->httpClient->request('GET', self::SOURCE_URL, [
                'timeout' => 10,
                'max_duration' => 30,
                'headers' => ['User-Agent' => 'V0Game disposable-domain-list updater'],
                // https only, also after redirects
                'on_progress' => static function (int $downloaded, int $total, array $info): void {
                    if (!str_starts_with((string) ($info['url'] ?? 'https://'), 'https://')) {
                        throw new RuntimeException('redirect to a non-https URL');
                    }
                    if ($downloaded > 10 * 1024 * 1024 || $total > 10 * 1024 * 1024) {
                        throw new RuntimeException('too large');
                    }
                },
            ]);
            $statusCode = $response->getStatusCode();
            $contents = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not download the disposable domain list: ' . $e->getMessage());
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('The disposable domain list returned HTTP status ' . $statusCode . '.');
        }
        if (!DisposableEmailDomainBlocklist::updateFromList($contents)) {
            throw new RuntimeException('The downloaded disposable domain list was invalid or could not be cached.');
        }
    }
}
