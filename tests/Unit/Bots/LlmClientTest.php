<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bots;

use App\Game\Bots\Llm\LlmClient;
use App\Game\Bots\Llm\LlmException;
use App\Game\Core\Database;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Pins the request shape and error mapping of LlmClient (no network: MockHttpClient). The provider quirks are
 * the point: openai needs max_completion_tokens, claude uses /messages with x-api-key, opencode_go sends a
 * session header, and errors must carry the HTTP status and provider message but never the API key.
 */
final class LlmClientTest extends TestCase
{
    private const KEY = 'sk-secret-key-123';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    /** @param list<MockResponse>|MockResponse $responses */
    private function client(string $provider, array|MockResponse $responses, string $base = ''): LlmClient
    {
        $queue = new \ArrayIterator(\is_array($responses) ? $responses : [$responses]);
        $recording = new MockHttpClient(function (string $method, string $url, array $options) use ($queue): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $response = $queue->current();
            $queue->next();

            return $response;
        });

        return new LlmClient($this->createStub(Database::class), $recording, $provider, self::KEY, 'model-x', $base, 'install-1', 'https://game.test/');
    }

    /** @return array<string, string> lower-cased header name => value of the last request */
    private function headers(): array
    {
        $out = [];
        foreach ($this->requests[array_key_last($this->requests)]['options']['headers'] as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $out[strtolower($name)] = $value;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        return json_decode($this->requests[array_key_last($this->requests)]['options']['body'], true);
    }

    public function testOpenAiUsesMaxCompletionTokens(): void
    {
        $client = $this->client('openai', new MockResponse(json_encode(['choices' => [['message' => ['content' => ' hi ']]]])));

        self::assertSame('hi', $client->complete('sys', 'prompt', 77));
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://api.openai.com/v1/chat/completions', $this->requests[0]['url']);
        self::assertSame(77, $this->body()['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $this->body());
        self::assertSame('Bearer ' . self::KEY, $this->headers()['authorization']);
        self::assertSame(
            [['role' => 'system', 'content' => 'sys'], ['role' => 'user', 'content' => 'prompt']],
            $this->body()['messages'],
        );
    }

    public function testClaudeRequestShapeAndResponse(): void
    {
        $client = $this->client('claude', new MockResponse(json_encode(['content' => [['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']]])));

        self::assertSame('ab', $client->complete('sys', 'prompt', 50));
        self::assertSame('https://api.anthropic.com/v1/messages', $this->requests[0]['url']);
        self::assertSame(self::KEY, $this->headers()['x-api-key']);
        self::assertSame('2023-06-01', $this->headers()['anthropic-version']);
        self::assertArrayNotHasKey('authorization', $this->headers());
        self::assertSame(50, $this->body()['max_tokens']);
        self::assertSame('sys', $this->body()['system']);
        self::assertSame([['role' => 'user', 'content' => 'prompt']], $this->body()['messages']);
    }

    public function testOpenCodeGoSendsSessionHeaderAndMaxTokens(): void
    {
        $client = $this->client('opencode_go', new MockResponse(json_encode(['choices' => [['message' => ['content' => 'ok']]]])));

        self::assertSame('ok', $client->complete('s', 'p', 10));
        self::assertSame('https://opencode.ai/zen/go/v1/chat/completions', $this->requests[0]['url']);
        self::assertSame('install-1', $this->headers()['x-opencode-session']);
        self::assertSame(10, $this->body()['max_tokens']);
        self::assertArrayNotHasKey('max_completion_tokens', $this->body());
    }

    public function testOpenRouterSendsRefererAndCustomBaseUrlIsTrimmed(): void
    {
        $client = $this->client('openrouter', new MockResponse(json_encode(['choices' => [['message' => ['content' => 'ok']]]])), 'https://proxy.test/v9/');

        $client->complete('s', 'p');
        self::assertSame('https://proxy.test/v9/chat/completions', $this->requests[0]['url']);
        self::assertSame('https://game.test/', $this->headers()['http-referer']);
        self::assertArrayNotHasKey('x-opencode-session', $this->headers());
    }

    public function testHttpErrorShowsStatusAndProviderMessage(): void
    {
        $client = $this->client('openai', new MockResponse(
            json_encode(['error' => ['message' => 'Incorrect API key provided: ' . self::KEY]]),
            ['http_code' => 401],
        ));

        try {
            $client->complete('s', 'p');
            self::fail('LlmException expected');
        } catch (LlmException $e) {
            self::assertSame('HTTP 401: Incorrect API key provided: ***', $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function testHttpErrorWithHtmlBodyFallsBackToStrippedText(): void
    {
        $client = $this->client('deepseek', new MockResponse('<html><b>Bad gateway</b></html>', ['http_code' => 502]));

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('HTTP 502: Bad gateway');
        $client->complete('s', 'p');
    }

    public function testTransportErrorMasksKey(): void
    {
        $client = $this->client('openai', new MockResponse('', ['error' => 'could not resolve ' . self::KEY]));

        try {
            $client->complete('s', 'p');
            self::fail('LlmException expected');
        } catch (LlmException $e) {
            self::assertStringStartsWith('Request failed: ', $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function testInvalidJsonAndEmptyResponse(): void
    {
        $client = $this->client('openai', [new MockResponse('not json'), new MockResponse(json_encode(['choices' => []]))]);

        try {
            $client->complete('s', 'p');
            self::fail('LlmException expected');
        } catch (LlmException $e) {
            self::assertSame('Invalid JSON response.', $e->getMessage());
        }
        $this->expectExceptionMessage('Empty response.');
        $client->complete('s', 'p');
    }

    public function testConfigurationErrorsDoNotSendRequests(): void
    {
        $noKey = new LlmClient($this->createStub(Database::class), new MockHttpClient(), 'openai', '', 'm', '');
        $badUrl = new LlmClient($this->createStub(Database::class), new MockHttpClient(), 'openai', 'k', 'm', 'ftp://x');

        foreach ([[$noKey, 'No API key set.'], [$badUrl, 'Invalid base URL.']] as [$client, $message]) {
            try {
                $client->complete('s', 'p');
                self::fail('LlmException expected');
            } catch (LlmException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }
}
