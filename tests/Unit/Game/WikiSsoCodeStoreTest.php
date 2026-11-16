<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game;

use App\Game\Core\WikiSsoCodeStore;
use PHPUnit\Framework\TestCase;

final class WikiSsoCodeStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/wikisso_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }

    public function testCodeWorksExactlyOnce(): void
    {
        $store = WikiSsoCodeStore::create('file', '', $this->directory);
        $store->store('code-one', ['accountId' => 7, 'accountname' => 'x']);

        $this->assertSame(['accountId' => 7, 'accountname' => 'x'], $store->consume('code-one'));
        $this->assertNull($store->consume('code-one'));
    }

    public function testUnknownCodeIsRejected(): void
    {
        $this->assertNull(WikiSsoCodeStore::create('file', '', $this->directory)->consume('nope'));
    }

    public function testExpiredCodeIsRejected(): void
    {
        $store = WikiSsoCodeStore::create('file', '', $this->directory);
        $store->store('old', ['accountId' => 1, 'accountname' => 'a']);
        $file = $this->directory . '/' . hash('sha256', 'old') . '.json';
        $data = json_decode((string) file_get_contents($file), true);
        $data['expires'] = time() - 1;
        file_put_contents($file, json_encode($data));

        $this->assertNull($store->consume('old'));
    }
}
