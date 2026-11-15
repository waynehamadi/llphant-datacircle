<?php

declare(strict_types=1);

namespace Datacircle\LLPhant\Tests;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A PSR-18 client that answers each request with the next queued response, and keeps every request.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue;

    public function __construct(ResponseInterface|ClientExceptionInterface ...$responses)
    {
        $this->queue = $responses;
    }

    /**
     * @param  array<mixed>  $body
     * @param  array<string, string>  $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->queue) ?? throw new RuntimeException('no response queued for '.$request->getUri());
        if ($response instanceof ClientExceptionInterface) {
            throw $response;
        }

        return $response;
    }

    public function uri(int $index): string
    {
        return (string) $this->requests[$index]->getUri();
    }
}
