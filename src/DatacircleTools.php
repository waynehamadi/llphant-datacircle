<?php

declare(strict_types=1);

namespace Datacircle\LLPhant;

use BadMethodCallException;
use InvalidArgumentException;
use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\Parameter;

/**
 * Datacircle's four LLPhant tools: find the best GTM APIs. https://datacircle.dev
 *
 * tools() gives them as FunctionInfo for a chat's setTools() or addTool(). Each one's name is a method of this class
 * (datacircle_best_plans...), which takes a model's arguments as it sends them: by name or in order, numbers as text,
 * an argument of no parameter left out. Each one gives a JSON object; an answer of 4xx is {"error": "..."} with the
 * API's own words, so that the model reads them and can call again; any other failure is thrown.
 *
 * @method string datacircle_list_apis(?string $query = null, ?int $limit = null)
 * @method string datacircle_best_plans(string $slug, ?int $limit = null)
 * @method string datacircle_search_plans(?string $api = null, ?string $query = null, ?string $sort = null, ?int $limit = null, ?int $offset = null)
 * @method string datacircle_provider(string $slug)
 */
class DatacircleTools
{
    public const LIST_APIS = 'datacircle_list_apis';

    public const BEST_PLANS = 'datacircle_best_plans';

    public const SEARCH_PLANS = 'datacircle_search_plans';

    public const PROVIDER = 'datacircle_provider';

    public const SORTS = ['score', 'provider', 'price'];

    /** Each tool's method, and its parameters in order: a string's, or an integer's. */
    private const METHODS = [
        self::LIST_APIS => ['listApis', ['query' => 'string', 'limit' => 'integer']],
        self::BEST_PLANS => ['bestPlans', ['slug' => 'string', 'limit' => 'integer']],
        self::SEARCH_PLANS => ['searchPlans', ['api' => 'string', 'query' => 'string', 'sort' => 'string', 'limit' => 'integer', 'offset' => 'integer']],
        self::PROVIDER => ['provider', ['slug' => 'string']],
    ];

    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client;
    }

    /**
     * datacircle_list_apis, datacircle_best_plans, datacircle_search_plans and datacircle_provider, for setTools().
     *
     * @return list<FunctionInfo>
     */
    public function tools(): array
    {
        return array_map(fn (string $name): FunctionInfo => $this->tool($name), array_keys(self::METHODS));
    }

    /**
     * One tool by its name, for addTool().
     */
    public function tool(string $name): FunctionInfo
    {
        $limit = new Parameter('limit', 'integer', 'How many plans to give, 1 to 50. 10 when left out');

        return match ($name) {
            self::LIST_APIS => new FunctionInfo($name, $this,
                "Lists the GTM APIs on Datacircle's sheet (https://datacircle.dev), with each API's slug, how many plans and providers it has and its best plan. Call it first to find an API's slug.",
                [
                    new Parameter('query', 'string', "Words of the API's name or slug, like email or linkedin profile: the APIs that hold the most of them come first. The sheet's order when left out"),
                    new Parameter('limit', 'integer', 'How many APIs to give, 1 to 200. 20 when left out: the sheet has about 150'),
                ]),
            self::BEST_PLANS => new FunctionInfo($name, $this,
                "Gives an API's plans best first by their score out of 100 on Datacircle's benchmark (https://datacircle.dev), each with its rank, provider, plan, $ per 1,000 calls and its page. perThousand is a plan's price of 1,000 items in dollars, pay the plan's whole price in its currency.",
                [$slug = new Parameter('slug', 'string', "The API's slug, like email-finder or linkedin-profile, as datacircle_list_apis gives it"), $limit],
                [$slug]),
            self::SEARCH_PLANS => new FunctionInfo($name, $this,
                "Searches every plan on Datacircle's sheet (https://datacircle.dev), or one API's, by provider or plan name, sorted by score, provider or price. Gives how many were found and up to limit of them. perThousand is a plan's price of 1,000 items in dollars, pay the plan's whole price in its currency.",
                [
                    new Parameter('api', 'string', "Only this API's plans: its name or slug, like email-finder. A provider's name goes in query, never here. Every API when left out"),
                    new Parameter('query', 'string', "Words that must all appear in the provider's or plan's name, like bright data"),
                    new Parameter('sort', 'string', 'score (best first, the default), provider (A to Z) or price ($ per 1,000, lowest first)', self::SORTS),
                    $limit,
                    new Parameter('offset', 'integer', 'How many found plans to skip first. 0 when left out'),
                ]),
            self::PROVIDER => new FunctionInfo($name, $this,
                "Gives each API one provider sells and its rank on each on Datacircle's benchmark (https://datacircle.dev), with its best plan there, its best rank first. total is how many APIs it sells, ranked on how many of them it has a rank.",
                [$slug = new Parameter('slug', 'string', "The provider's slug, like bright-data, or its name, like Bright Data: the name is made a slug")],
                [$slug]),
            default => throw new InvalidArgumentException('datacircle: no tool is named '.$name.'; the tools are '.implode(', ', array_keys(self::METHODS))),
        };
    }

    /**
     * A tool called by its name, as LLPhant calls it: its arguments by name or in order, a number sent as text read as
     * one, an argument of no parameter or of the wrong type left out.
     *
     * @param  array<int|string, mixed>  $arguments
     */
    public function __call(string $name, array $arguments): string
    {
        if (! isset(self::METHODS[$name])) {
            throw new BadMethodCallException('datacircle: no tool is named '.$name.'; the tools are '.implode(', ', array_keys(self::METHODS)));
        }
        [$method, $parameters] = self::METHODS[$name];

        $values = [];
        $index = 0;
        foreach ($parameters as $parameter => $type) {
            $value = array_key_exists($parameter, $arguments) ? $arguments[$parameter] : ($arguments[$index] ?? null);
            $index++;
            if ($type === 'integer') {
                $values[$parameter] = is_numeric($value) ? (int) round((float) $value) : null;
            } elseif (is_string($value) || is_int($value) || is_float($value)) {
                $values[$parameter] = (string) $value;
            } elseif ($parameter === 'slug') {
                $values[$parameter] = '';
            }
        }

        return $this->{$method}(...$values);
    }

    /**
     * datacircle_list_apis: GET /apis, the APIs whose name or slug holds the most of query's words first.
     */
    public function listApis(?string $query = null, ?int $limit = null): string
    {
        try {
            $apis = Client::plans($this->client->get('/apis'));
        } catch (ApiError $e) {
            return self::error($e->getMessage());
        }

        $words = preg_split('/\s+/', strtolower(str_replace('-', ' ', (string) $query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $found = [];
        foreach ($apis as $index => $api) {
            $name = strtolower((string) ($api['api'] ?? '').' '.str_replace('-', ' ', (string) ($api['slug'] ?? '')));
            $matched = count(array_filter($words, fn (string $word): bool => str_contains($name, $word)));
            if ($matched > 0 || $words === []) {
                $found[] = [$matched, $index, Client::brief($api, ['api', 'slug', 'plans', 'providers', 'best', 'bestPage', 'sheetPage'])];
            }
        }
        // The most words matched first, the sheet's order within each.
        usort($found, fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        return self::json([
            'total' => count($found),
            'apis' => array_column(array_slice($found, 0, Client::number($limit, 1, 200) ?? 20), 2),
        ]);
    }

    /**
     * datacircle_best_plans: GET /apis/{slug}/best, an API's plans best first.
     */
    public function bestPlans(string $slug, ?int $limit = null): string
    {
        $slug = Client::slugify($slug);
        if ($slug === '') {
            return self::error("Give an API's slug, like email-finder: datacircle_list_apis gives every slug");
        }
        $limit = Client::number($limit, 1, 50);

        try {
            $answer = $this->client->get('/apis/'.$slug.'/best', $limit === null ? [] : ['limit' => $limit]);
        } catch (ApiError $e) {
            // A provider's name sent as the API's slug: the API's words, and where a provider's plans are.
            return self::error($e->getMessage().($e->status === 404
                ? '. datacircle_list_apis gives every API\'s slug, and datacircle_search_plans finds a provider\'s plans by its name as query.'
                : ''));
        }
        $answer['plans'] = Client::briefPlans($answer['plans'] ?? []);

        return self::json($answer);
    }

    /**
     * datacircle_search_plans: GET /plans, every plan or an API's, by provider or plan name.
     */
    public function searchPlans(?string $api = null, ?string $query = null, ?string $sort = null, ?int $limit = null, ?int $offset = null): string
    {
        $api = trim((string) $api);
        $query = trim((string) $query);
        $note = '';

        try {
            try {
                $answer = $this->search($api, $query, $sort, $limit, $offset);
            } catch (ApiError $e) {
                if ($e->status !== 400 || ! str_contains($e->getMessage(), 'api must be') || $query !== '') {
                    throw $e;
                }
                // A provider's name sent as the API: the plans whose provider or plan name holds it.
                $note = 'No API is named "'.$api.'": these are the plans whose provider or plan name holds it.';
                [$api, $query] = ['', $api];
                $answer = $this->search($api, $query, $sort, $limit, $offset);
            }
        } catch (ApiError $e) {
            return self::error($e->getMessage());
        }

        if (($answer['total'] ?? null) === 0 && str_contains($query, '-')) {
            // A slug sent as a name, like bright-data, which no name holds: its words, like bright data. A name with a
            // hyphen, like A-Leads, is found as it is first.
            $words = implode(' ', preg_split('/[\s-]+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            try {
                $answer = $this->search($api, $words, $sort, $limit, $offset);
                $note = trim($note.' No provider\'s or plan\'s name holds "'.$query.'": these are the plans whose name holds "'.$words.'".');
            } catch (ApiError) {
            }
        }
        if ($note !== '') {
            $answer['note'] = $note;
        }
        $answer['plans'] = Client::briefPlans($answer['plans'] ?? []);

        return self::json($answer);
    }

    /**
     * datacircle_provider: GET /providers/{slug}, each API a provider sells and its rank there, its best rank first.
     */
    public function provider(string $slug): string
    {
        $slug = Client::slugify($slug);
        if ($slug === '') {
            return self::error("Give a provider's slug or name, like bright-data or Bright Data");
        }

        try {
            $answer = $this->client->get('/providers/'.$slug);
        } catch (ApiError $e) {
            return self::error($e->getMessage());
        }

        $apis = [];
        foreach (Client::plans($answer['apis'] ?? []) as $index => $entry) {
            $plans = Client::plans($entry['plans'] ?? []);
            $kept = Client::brief($entry, ['api', 'slug', 'rank', 'bestPage']);
            $kept['plans'] = count($plans);
            $best = self::bestPlan($plans);
            if ($best !== null) {
                $kept['best'] = Client::brief($best, ['plan', 'score', 'perThousand', 'page']);
            }
            $apis[] = [$index, $kept];
        }
        // Its best rank first, the APIs where it has none last, in the API's order.
        usort($apis, function (array $a, array $b): int {
            $rankA = is_numeric($a[1]['rank'] ?? null) ? $a[1]['rank'] : INF;
            $rankB = is_numeric($b[1]['rank'] ?? null) ? $b[1]['rank'] : INF;

            return [$rankA, $a[0]] <=> [$rankB, $b[0]];
        });
        $apis = array_column($apis, 1);

        unset($answer['apis']);
        // How many it sells and is ranked on before the list, so that a model reads them first.
        $answer['total'] = count($apis);
        $answer['ranked'] = count(array_filter($apis, fn (array $api): bool => is_numeric($api['rank'] ?? null)));
        $answer['apis'] = $apis;

        return self::json($answer);
    }

    /**
     * @return array<mixed>
     */
    private function search(string $api, string $query, ?string $sort, ?int $limit, ?int $offset): array
    {
        $params = array_filter([
            'api' => $api,
            'query' => $query,
            'sort' => in_array($sort, self::SORTS, true) ? $sort : null,
            'limit' => Client::number($limit, 1, 50),
            'offset' => Client::number($offset, 1, PHP_INT_MAX),
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return $this->client->get('/plans', $params);
    }

    /**
     * A provider's best plan on an API: its lowest rank, a plan with no rank having none.
     *
     * @param  list<array<string, mixed>>  $plans
     * @return array<string, mixed>|null
     */
    private static function bestPlan(array $plans): ?array
    {
        $best = null;
        foreach ($plans as $plan) {
            if (is_numeric($plan['rank'] ?? null) && ($best === null || $plan['rank'] < $best['rank'])) {
                $best = $plan;
            }
        }

        return $best ?? $plans[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private static function json(array $result): string
    {
        return json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function error(string $message): string
    {
        return self::json(['error' => $message]);
    }
}
