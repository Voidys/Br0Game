<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the Content-Security-Policy of the game's HTML pages and decides how each area gets it.
 *
 * Policy: everything from the own origin only; scripts additionally by per-request nonce (the
 * importmap is the one inline script left); inline styles stay allowed (`style-src 'unsafe-inline'`)
 * because templates, the theme loader and the tooltips use style="" attributes and a few <style>
 * blocks (signal colours); images may come from any https host (alliance logos and avatars are
 * user-supplied URLs). Violations are posted to /csp-report (CspReportController).
 *
 * Modes (env CSP_MODE): "enforce" (default), "report" (everything report-only), "off". In "enforce"
 * mode only the areas in app.csp.enforced_areas are enforced, the others stay report-only until they
 * are verified clean.
 */
final class ContentSecurityPolicy
{
    public const MODE_ENFORCE = 'enforce';
    public const MODE_REPORT = 'report';
    public const MODE_OFF = 'off';

    public const REPORT_PATH = '/csp-report';

    public const HEADER_ENFORCE = 'Content-Security-Policy';
    public const HEADER_REPORT = 'Content-Security-Policy-Report-Only';

    /** controller namespace segment => area name */
    private const AREAS = [
        'Game' => 'game',
        'Login' => 'login',
        'Account' => 'account',
        'Admin' => 'admin',
    ];

    /**
     * Optional third-party sources, opened only for pages that ask (csp_allow('<feature>')):
     * feature => directive => sources.
     */
    private const FEATURES = [
        'recaptcha' => [
            'script-src' => ['https://www.google.com', 'https://www.gstatic.com'],
            'frame-src' => ['https://www.google.com', 'https://www.gstatic.com'],
            'connect-src' => ['https://www.google.com'],
        ],
    ];

    /** @param list<string> $enforcedAreas areas that are enforced when the mode is "enforce" */
    public function __construct(
        private readonly string $mode = self::MODE_ENFORCE,
        private readonly array $enforcedAreas = ['game', 'login', 'account', 'admin', 'install'],
    ) {}

    /**
     * the policy string for a response whose inline scripts carry $nonce
     *
     * @param list<string> $features optional third-party features the page uses (keys of FEATURES)
     * @param array<string, list<string>> $extraSources extra per-response sources (directive => origins, see CspNonce::allowSource())
     */
    public function build(string $nonce, array $features = [], array $extraSources = []): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-" . $nonce . "'"],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:', 'https:'],
            'connect-src' => ["'self'"],
            'font-src' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
        ];
        foreach ($features as $feature) {
            foreach (self::FEATURES[$feature] ?? [] as $directive => $sources) {
                $directives[$directive] = [...($directives[$directive] ?? ["'self'"]), ...$sources];
            }
        }
        foreach ($extraSources as $directive => $origins) {
            $directives[$directive] = [...($directives[$directive] ?? ["'self'"]), ...$origins];
        }
        $parts = [];
        foreach ($directives as $directive => $sources) {
            $parts[] = $directive . ' ' . implode(' ', $sources);
        }
        $parts[] = 'report-uri ' . self::REPORT_PATH;

        return implode('; ', $parts);
    }

    /**
     * Which header carries the policy for this area: HEADER_ENFORCE, HEADER_REPORT or null (none).
     */
    public function headerFor(?string $area): ?string
    {
        if ($area === null || $this->mode === self::MODE_OFF) {
            return null;
        }
        if ($this->mode === self::MODE_ENFORCE && in_array($area, $this->enforcedAreas, true)) {
            return self::HEADER_ENFORCE;
        }

        return self::HEADER_REPORT;
    }

    /** area of the request (game, login, account, admin, install) or null for everything else */
    public function areaOf(Request $request): ?string
    {
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller)) {
            return null;
        }
        $class = explode('::', $controller, 2)[0];
        if (preg_match('/^App\\\\Controller\\\\(Game|Login|Account|Admin)\\\\/', $class, $match) === 1) {
            return self::AREAS[$match[1]];
        }
        if ($class === \App\Controller\GameEntryController::class && str_starts_with($request->getPathInfo(), '/install')) {
            return 'install';
        }

        return null;
    }
}
