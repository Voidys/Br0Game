<?php

namespace App\Game\Cronjob;

use RuntimeException;
use App\Game\Core\DisposableEmailDomainBlocklist;

class DisposableEmailDomainsCronjob extends AbstractCronjobTask
{
    private const SOURCE_URL = 'https://raw.githubusercontent.com/disposable-email-domains/disposable-email-domains/master/disposable_email_blocklist.conf';

    public function run(): void
    {
        $curl = curl_init(self::SOURCE_URL);
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the disposable domain list request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_MAXFILESIZE => 10 * 1024 * 1024,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Br0Game disposable-domain-list updater',
        ]);

        $contents = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($contents === false) {
            throw new RuntimeException('Could not download the disposable domain list: ' . $curlError);
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException('The disposable domain list returned HTTP status ' . $statusCode . '.');
        }
        if (!DisposableEmailDomainBlocklist::updateFromList($contents)) {
            throw new RuntimeException('The downloaded disposable domain list was invalid or could not be cached.');
        }
    }
}
