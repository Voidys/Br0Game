<?php

declare(strict_types=1);

namespace App\Controller;

use App\Runtime\ProjectPaths;
use App\Security\ContentSecurityPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives Content-Security-Policy violation reports (report-uri) and appends them as one JSON line
 * to var/log/csp.log, so production can monitor what the policy blocks. Rate limited per client IP
 * (limiter "csp_report"); the body is truncated, anything that is not a report is dropped. Always
 * answers 204.
 */
final class CspReportController
{
    private const MAX_BODY = 4096;

    public function __construct(
        #[Autowire(service: 'limiter.csp_report')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {}

    #[Route(ContentSecurityPolicy::REPORT_PATH, name: 'csp_report', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        if (!$this->limiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
            return $response;
        }

        $decoded = json_decode(substr($request->getContent(), 0, self::MAX_BODY), true);
        // legacy report-uri: {"csp-report": {...}}; Reporting API: [{"type": "csp-violation", "body": {...}}]
        $report = is_array($decoded) ? ($decoded['csp-report'] ?? $decoded[0]['body'] ?? null) : null;
        if (!is_array($report)) {
            return $response;
        }

        $keys = ['document-uri', 'documentURL', 'violated-directive', 'effectiveDirective', 'blocked-uri', 'blockedURL', 'source-file', 'sourceFile', 'line-number', 'lineNumber', 'disposition'];
        $entry = ['time' => gmdate('c'), 'ip' => $request->getClientIp()];
        foreach ($keys as $key) {
            if (isset($report[$key]) && is_scalar($report[$key])) {
                $entry[$key] = substr((string) $report[$key], 0, 300);
            }
        }
        $directory = ProjectPaths::ROOT . 'var/log';
        if (is_dir($directory) || mkdir($directory, 0775, true)) {
            file_put_contents($directory . '/csp.log', json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        }

        return $response;
    }
}
