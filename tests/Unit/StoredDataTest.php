<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\StoredData;
use PHPUnit\Framework\TestCase;

/**
 * StoredData: JSON for database columns, tolerant reading of legacy serialize() rows, nothing else.
 */
final class StoredDataTest extends TestCase
{
    private const QUEUE = [[1, 5, 182.0, 1794778000.0, 'build', [901 => 303.75, 902 => 75.9375]], [4, 6, 69, 1794778500, 'destroy']];

    public function testRoundTripKeepsStructureKeysAndTypes(): void
    {
        $this->assertSame(self::QUEUE, StoredData::decode(StoredData::encode(self::QUEUE)));
        $this->assertSame([202 => 5.0, 204 => 3], StoredData::decode(StoredData::encode([202 => 5.0, 204 => 3])));
    }

    public function testEncodeWritesJson(): void
    {
        $this->assertSame('[[202,8.0],[204,5]]', StoredData::encode([[202, 8.0], [204, 5]]));
        $this->assertSame('[]', StoredData::encode([]));
    }

    public function testEmptyValuesGiveAnEmptyArray(): void
    {
        $this->assertSame([], StoredData::decode(null));
        $this->assertSame([], StoredData::decode(''));
    }

    public function testLegacySerializedRowsAreStillReadable(): void
    {
        $this->assertSame(self::QUEUE, StoredData::decode(serialize(self::QUEUE)));
        $this->assertSame([], StoredData::decode('a:0:{}'));
    }

    public function testLegacyObjectsAreNeverInstantiated(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        StoredData::decode('O:8:"stdClass":0:{}');
    }

    public function testObjectsNestedInALegacyArrayBecomeIncompleteClasses(): void
    {
        $data = StoredData::decode('a:1:{i:0;O:8:"stdClass":1:{s:1:"a";i:1;}}');

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $data[0]);
    }

    public function testGarbageThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        StoredData::decode('not stored data');
    }

    public function testScalarJsonIsNotAccepted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        StoredData::decode('5');
    }

    public function testTryDecodeFallsBack(): void
    {
        $this->assertSame(['x'], StoredData::tryDecode('garbage', ['x']));
        $this->assertSame([1], StoredData::tryDecode('[1]'));
    }

    public function testInvalidUtf8IsSubstitutedNotFatal(): void
    {
        $this->assertSame(["a\u{FFFD}b"], StoredData::decode(StoredData::encode(["a\xFFb"])));
    }
}
