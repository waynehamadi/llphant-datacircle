<?php

declare(strict_types=1);

namespace Datacircle\LLPhant\Tests;

use Datacircle\LLPhant\Client;
use Datacircle\LLPhant\DatacircleTools;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\FunctionInfo\FunctionRunner;
use LLPhant\Chat\FunctionInfo\ToolFormatter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ToolsTest extends TestCase
{
    private const PLAN = ['api' => 'Employee email', 'rank' => 1, 'provider' => 'Emelia', 'plan' => 'Pro', 'score' => 76.3,
        'perThousand' => 4.99, 'page' => 'https://datacircle.dev/x', 'aggregators' => ['a'], 'checks' => ['b']];

    private static function tools(FakeHttpClient $http): DatacircleTools
    {
        return new DatacircleTools(new Client(httpClient: $http));
    }

    /**
     * Runs a tool as LLPhant's FunctionRunner does: its JSON arguments, as a model sent them.
     *
     * @return array<mixed>
     */
    private static function runTool(DatacircleTools $tools, string $name, string $jsonArgs): array
    {
        $function = $tools->tool($name)->cloneWithId('call_1');
        $function->jsonArgs = $jsonArgs;

        return json_decode((string) FunctionRunner::run($function), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheToolsAreFourFunctionsOfTheirSchemas(): void
    {
        $functions = (new DatacircleTools)->tools();
        $this->assertSame(['datacircle_list_apis', 'datacircle_best_plans', 'datacircle_search_plans', 'datacircle_provider'],
            array_map(fn (FunctionInfo $function): string => $function->name, $functions));

        $openai = ToolFormatter::formatFunctionsToOpenAITools($functions);
        $this->assertSame(['slug'], $openai[1]['function']['parameters']['required']);
        $this->assertSame('integer', $openai[1]['function']['parameters']['properties']['limit']['type']);
        $this->assertSame(['score', 'provider', 'price'], $openai[2]['function']['parameters']['properties']['sort']['enum']);
        $this->assertSame([], $openai[0]['function']['parameters']['required']);

        $this->expectException(InvalidArgumentException::class);
        (new DatacircleTools)->tool('datacircle_nope');
    }

    public function testListApisFindsByWordsAndLimits(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json([
            ['api' => 'Employee profile', 'slug' => 'linkedin-profile', 'plans' => 10],
            ['api' => 'Employee email', 'slug' => 'email-finder', 'plans' => 5],
            ['api' => 'Email validation', 'slug' => 'email-verification', 'plans' => 3],
        ]));
        $answer = self::runTool(self::tools($http), 'datacircle_list_apis', '{"query": "employee email", "limit": "1"}');

        $this->assertSame(3, $answer['total']);
        $this->assertSame(['email-finder'], array_column($answer['apis'], 'slug'));
        $this->assertSame('https://api.datacircle.dev/v1/apis', $http->uri(0));
        $this->assertSame('llphant-datacircle/'.Client::VERSION, $http->requests[0]->getHeaderLine('User-Agent'));
    }

    public function testBestPlansCallsTheApiAndKeepsAPlansMainKeys(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['api' => 'Employee email', 'slug' => 'email-finder', 'total' => 1, 'plans' => [self::PLAN]]));
        $answer = self::runTool(self::tools($http), 'datacircle_best_plans', '{"slug": "Email Finder", "limit": 3.0}');

        $this->assertSame('https://api.datacircle.dev/v1/apis/email-finder/best?limit=3', $http->uri(0));
        $this->assertSame('Emelia', $answer['plans'][0]['provider']);
        $this->assertArrayNotHasKey('aggregators', $answer['plans'][0]);
    }

    public function testEachWayLLPhantCallsAToolGivesTheSame(): void
    {
        $body = ['api' => 'Employee email', 'slug' => 'email-finder', 'total' => 1, 'plans' => [self::PLAN]];
        $http = new FakeHttpClient(...array_fill(0, 4, FakeHttpClient::json($body)));
        $tools = self::tools($http);
        $function = $tools->tool('datacircle_best_plans')->cloneWithId('call_1');
        $function->jsonArgs = '{"slug": "email-finder", "limit": 2}';

        $answers = [
            $function->callWithArguments(['slug' => 'email-finder', 'limit' => 2]), // AnthropicChat, OllamaChat
            $function->call(), // LmStudioChat
            $function->callAndReturnAsOpenAIMessages()[1]->content, // a loop of generateChatOrReturnFunctionToCall
            $tools->datacircle_best_plans('email-finder', 2), // in order
        ];
        $this->assertCount(1, array_unique($answers));
        $this->assertSame(array_fill(0, 4, 'https://api.datacircle.dev/v1/apis/email-finder/best?limit=2'),
            array_map(fn (int $i): string => $http->uri($i), range(0, 3)));
    }

    public function testAnArgumentOfNoParameterOrOfTheWrongTypeIsLeftOut(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['total' => 1, 'offset' => 0, 'plans' => [self::PLAN]]));
        self::runTool(self::tools($http), 'datacircle_search_plans', '{"query": "emelia", "sort": "cheapest", "limit": "ten", "page": 2, "api": ["x"]}');

        $this->assertSame('https://api.datacircle.dev/v1/plans?query=emelia', $http->uri(0));
    }

    public function testAnUnknownSlugIsAnErrorWithTheApisWords(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['error' => 'no API has the slug bright-data'], 404));
        $answer = self::runTool(self::tools($http), 'datacircle_best_plans', '{"slug": "Bright Data"}');

        $this->assertStringContainsString('no API has the slug bright-data', $answer['error']);
        $this->assertStringContainsString('datacircle_search_plans', $answer['error']);
    }

    public function testAMissingSlugIsAnErrorWithNoCall(): void
    {
        $http = new FakeHttpClient();
        $this->assertStringContainsString('slug', self::runTool(self::tools($http), 'datacircle_best_plans', '{}')['error']);
        $this->assertStringContainsString('slug', self::runTool(self::tools($http), 'datacircle_provider', '{"slug": null}')['error']);
        $this->assertSame([], $http->requests);
    }

    public function testAProvidersNameSentAsTheApiIsSearchedAsItsName(): void
    {
        $http = new FakeHttpClient(
            FakeHttpClient::json(['error' => 'api must be one of: Employee email, SERP'], 400),
            FakeHttpClient::json(['total' => 1, 'offset' => 0, 'plans' => [self::PLAN]]),
        );
        $answer = self::runTool(self::tools($http), 'datacircle_search_plans', '{"api": "Bright Data", "sort": "price", "limit": 2}');

        $this->assertSame('https://api.datacircle.dev/v1/plans?query=Bright%20Data&sort=price&limit=2', $http->uri(1));
        $this->assertStringContainsString('No API is named "Bright Data"', $answer['note']);
    }

    public function testASlugSentAsANameIsSearchedAsItsWords(): void
    {
        $http = new FakeHttpClient(
            FakeHttpClient::json(['total' => 0, 'offset' => 0, 'plans' => []]),
            FakeHttpClient::json(['total' => 1, 'offset' => 0, 'plans' => [self::PLAN]]),
        );
        $answer = self::runTool(self::tools($http), 'datacircle_search_plans', '{"query": "bright-data"}');

        $this->assertSame('https://api.datacircle.dev/v1/plans?query=bright%20data', $http->uri(1));
        $this->assertSame(1, $answer['total']);
    }

    public function testProviderPutsItsBestRankFirst(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['provider' => 'Société Générale', 'slug' => 'societe-generale', 'apis' => [
            ['api' => 'SERP', 'slug' => 'serp', 'rank' => null, 'plans' => [['plan' => 'Free']]],
            ['api' => 'News search', 'slug' => 'news', 'rank' => 4, 'plans' => [['plan' => 'A', 'rank' => 9], ['plan' => 'B', 'rank' => 4]]],
        ]]));
        $answer = self::runTool(self::tools($http), 'datacircle_provider', '{"slug": "Société Générale"}');

        $this->assertSame('https://api.datacircle.dev/v1/providers/societe-generale', $http->uri(0));
        $this->assertSame([2, 1], [$answer['total'], $answer['ranked']]);
        $this->assertSame(['news', 'serp'], array_column($answer['apis'], 'slug'));
        $this->assertSame('B', $answer['apis'][0]['best']['plan']);
    }

    public function testATooManyCallsIsWaitedForOnce(): void
    {
        $http = new FakeHttpClient(
            FakeHttpClient::json(['error' => 'too many calls'], 429, ['Retry-After' => '1']),
            FakeHttpClient::json([['api' => 'SERP', 'slug' => 'serp']]),
        );
        $this->assertSame(1, self::runTool(self::tools($http), 'datacircle_list_apis', '')['total']);
        $this->assertCount(2, $http->requests);
    }

    public function testAServerOrNetworkFailureIsThrown(): void
    {
        try {
            self::tools(new FakeHttpClient(FakeHttpClient::json(['error' => 'down'], 503)))->listApis();
            $this->fail('no exception');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not resolve host');
        self::tools(new FakeHttpClient(new ConnectException('could not resolve host', new Request('GET', 'https://api.datacircle.dev/v1/apis'))))->listApis();
    }

    public function testSlugify(): void
    {
        $this->assertSame('bright-data', Client::slugify(' Bright Data '));
        $this->assertSame('societe-generale', Client::slugify('Société Générale'));
        $this->assertSame('a-leads', Client::slugify('A-Leads'));
        $this->assertSame('', Client::slugify(null));
    }
}
