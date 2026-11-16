<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\PasswordSupport;
use PHPUnit\Framework\TestCase;

/**
 * Pins the old-account compatibility of the raw-password fix: hashes made from the former input filter's output
 * ("ab&amp;cd", "p?ssw?rd") still match the typed password through the legacy variants.
 */
final class PasswordSupportTest extends TestCase
{
    public function testPlainPasswordHasNoLegacyVariant(): void
    {
        $this->assertSame([], PasswordSupport::legacyVariants('plainpassword1'));
    }

    public function testSpecialCharactersAreHtmlEncodedInTheLegacyForm(): void
    {
        $this->assertContains('ab&amp;cd&quot;&lt;e&gt;f&#039;', PasswordSupport::legacyVariants('ab&cd"<e>f\''));
    }

    public function testNonAsciiBecomesQuestionMarksInTheSettingsVariant(): void
    {
        $variants = PasswordSupport::legacyVariants('pässwörd');

        $this->assertContains('p??ssw??rd', $variants);
        // the registration variant kept the UTF-8 characters, which equal the raw value and are therefore not listed
        $this->assertNotContains('pässwörd', $variants);
    }

    public function testLegacyHashMatchesOnlyThroughTheLegacyCheck(): void
    {
        $hash = password_hash('ab&amp;cd', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertFalse(PasswordSupport::verify('ab&cd', $hash));
        $this->assertTrue(PasswordSupport::matchesLegacy('ab&cd', $hash));
        $this->assertTrue(PasswordSupport::verifyAny('ab&cd', $hash));
        $this->assertFalse(PasswordSupport::verifyAny('ab&ce', $hash));
    }

    public function testSettingsVariantHashMatches(): void
    {
        $hash = password_hash('p??ssw??rd&amp;1', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertTrue(PasswordSupport::matchesLegacy('pässwörd&1', $hash));
    }

    public function testRawHashMatchesDirectlyAndEmptyHashNever(): void
    {
        $this->assertTrue(PasswordSupport::verify('ab&cd', password_hash('ab&cd', PASSWORD_BCRYPT, ['cost' => 4])));
        $this->assertFalse(PasswordSupport::verifyAny('x', ''));
    }
}
