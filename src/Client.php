<?php

declare(strict_types=1);

namespace Datacircle\LLPhant;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\Request;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * Calls Datacircle's open API, https://api.datacircle.dev/v1 (its spec: https://api.datacircle.dev/v1/openapi.json).
 * It needs no key. More at https://datacircle.dev
 */
class Client
{
    public const BASE_URL = 'https://api.datacircle.dev/v1';

    /** This package's version, sent in each call's User-Agent. */
    public const VERSION = '0.1.0';

    /** A plan holds about 90 keys (its terms of use, its aggregators...): these choose a plan, the rest is on its page. */
    public const PLAN_KEYS = ['api', 'rank', 'provider', 'plan', 'score', 'perThousand', 'notFoundPerThousand', 'billing', 'pay',
        'currency', 'units', 'unit', 'strengths', 'latencyMs', 'page', 'pricingPage', 'docs'];

    public readonly string $baseUrl;

    protected ClientInterface $httpClient;

    /**
     * @param  string|null  $baseUrl  Where the tools call. BASE_URL when left out.
     * @param  float  $timeout  Seconds a call may take.
     * @param  ClientInterface|null  $httpClient  Any PSR-18 client. Guzzle, which LLPhant uses, when left out.
     */
    public function __construct(?string $baseUrl = null, public readonly float $timeout = 30.0, ?ClientInterface $httpClient = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? self::BASE_URL, '/');
        $this->httpClient = $httpClient ?? new GuzzleClient(['timeout' => $timeout, 'connect_timeout' => min($timeout, 10.0)]);
    }

    /**
     * Calls one path and gives its JSON. A 429 is waited for once, a minute at most.
     *
     * @param  array<string, string|int>  $query
     * @return array<mixed>
     *
     * @throws ApiError an answer of 4xx, with the API's own words
     * @throws RuntimeException any other failure, a network failure too
     */
    public function get(string $path, array $query = []): array
    {
        $uri = $this->baseUrl.$path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $request = new Request('GET', $uri, [
            'Accept' => 'application/json',
            'User-Agent' => 'llphant-datacircle/'.self::VERSION,
        ]);

        for ($try = 0; ; $try++) {
            try {
                $response = $this->httpClient->sendRequest($request);
            } catch (ClientExceptionInterface $e) {
                throw new RuntimeException('datacircle: '.$e->getMessage(), 0, $e);
            }

            $status = $response->getStatusCode();
            if ($status === 429 && $try === 0) {
                $wait = (int) $response->getHeaderLine('Retry-After');
                sleep($wait >= 1 && $wait <= 60 ? $wait : 60);

                continue;
            }
            $json = self::decode((string) $response->getBody());
            if ($status >= 400 && $status < 500) {
                $error = $json['error'] ?? null;

                throw new ApiError($status, is_string($error) && $error !== '' ? $error : 'HTTP '.$status);
            }
            if ($status !== 200) {
                throw new RuntimeException('datacircle: HTTP '.$status.' from '.$uri);
            }
            if ($json === null) {
                throw new RuntimeException('datacircle: the answer is not JSON');
            }

            return $json;
        }
    }

    /**
     * @return array<mixed>|null
     */
    private static function decode(string $body): ?array
    {
        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($json) ? $json : null;
    }

    /**
     * The API's rule for a slug: the name in lowercase letters and digits, the rest a hyphen.
     */
    public static function slugify(mixed $name): string
    {
        $name = trim(is_scalar($name) ? (string) $name : '');
        // é is e: iconv writes it e or 'e, whose mark is dropped.
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : false;
        $ascii = strtolower((string) preg_replace('/[\'"`^~]/', '', $ascii === false ? $name : $ascii));

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function brief(array $item, array $keys): array
    {
        $kept = [];
        foreach ($keys as $key) {
            if (isset($item[$key])) {
                $kept[$key] = $item[$key];
            }
        }

        return $kept;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function plans(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_array'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function briefPlans(mixed $value): array
    {
        return array_map(fn (array $plan): array => self::brief($plan, self::PLAN_KEYS), self::plans($value));
    }

    /**
     * A number a model sent (10, 10.0 or "10"), between low and high; null when it sent none or one below 1.
     */
    public static function number(mixed $value, int $low, int $high): ?int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return max($low, min($high, (int) $value));
    }
}
