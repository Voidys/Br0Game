<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ContentSecurityPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ContentSecurityPolicyTest extends TestCase
{
    public function testPolicyCarriesTheNonceAndForbidsInlineScripts(): void
    {
        $policy = (new ContentSecurityPolicy())->build('abc123');

        self::assertStringContainsString("script-src 'self' 'nonce-abc123'", $policy);
        self::assertDoesNotMatchRegularExpression("/script-src [^;]*unsafe-inline/", $policy);
        self::assertStringContainsString("object-src 'none'", $policy);
        self::assertStringContainsString("frame-ancestors 'self'", $policy);
        self::assertStringContainsString('report-uri /csp-report', $policy);
    }

    public function testEnforceModeEnforcesOnlyTheListedAreas(): void
    {
        $csp = new ContentSecurityPolicy('enforce', ['game']);

        self::assertSame(ContentSecurityPolicy::HEADER_ENFORCE, $csp->headerFor('game'));
        self::assertSame(ContentSecurityPolicy::HEADER_REPORT, $csp->headerFor('admin'));
        self::assertNull($csp->headerFor(null));
    }

    public function testReportAndOffModes(): void
    {
        self::assertSame(ContentSecurityPolicy::HEADER_REPORT, (new ContentSecurityPolicy('report', ['game']))->headerFor('game'));
        self::assertNull((new ContentSecurityPolicy('off'))->headerFor('game'));
    }

    public function testAreaComesFromTheControllerNamespace(): void
    {
        $csp = new ContentSecurityPolicy();
        $request = static fn(string $controller) => new Request(attributes: ['_controller' => $controller]);

        self::assertSame('game', $csp->areaOf($request('App\Controller\Game\OverviewController::show')));
        self::assertSame('admin', $csp->areaOf($request('App\Controller\Admin\ConfigController::show')));
        self::assertNull($csp->areaOf($request('App\Controller\HealthController')));
        self::assertNull($csp->areaOf(new Request()));
    }

    public function testRecaptchaSourcesOnlyWhenAsked(): void
    {
        $csp = new ContentSecurityPolicy();

        self::assertStringNotContainsString('google.com', $csp->build('n'));
        $policy = $csp->build('n', ['recaptcha']);
        self::assertStringContainsString("script-src 'self' 'nonce-n' https://www.google.com https://www.gstatic.com", $policy);
        self::assertStringContainsString("frame-src 'self' https://www.google.com", $policy);
        self::assertStringContainsString("connect-src 'self' https://www.google.com", $policy);
    }
}
