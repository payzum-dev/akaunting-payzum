<?php

declare(strict_types=1);

namespace Omnipay\Payzum\Tests;

use Payzum\Http\Response;
use Payzum\Http\Transport;

/**
 * In-memory transport: queue responses, capture what the SDK actually sent.
 */
final class FakeTransport implements Transport
{
    /** @var list<Response> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, query: array<string, string|int>, body: ?string}> */
    public array $sent = [];

    public function queue(int $status, string $body, array $headers = []): self
    {
        $this->queue[] = new Response($status, $body, $headers);

        return $this;
    }

    public function send(
        string $method,
        string $url,
        array $headers,
        array $query,
        ?string $body,
        int $timeoutSeconds,
    ): Response {
        $this->sent[] = compact('method', 'url', 'headers', 'query', 'body');

        return array_shift($this->queue)
            ?? new Response(500, '{"code":"TEST_QUEUE_EMPTY","message":"no queued response"}');
    }

    public function lastRequest(): array
    {
        return $this->sent[count($this->sent) - 1];
    }
}
