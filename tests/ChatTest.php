<?php

declare(strict_types=1);

namespace Datacircle\LLPhant\Tests;

use Datacircle\LLPhant\Client;
use Datacircle\LLPhant\DatacircleTools;
use LLPhant\Chat\OpenAIChat;
use LLPhant\OpenAIConfig;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Enums\OverrideStrategy;
use PHPUnit\Framework\TestCase;

/**
 * LLPhant's own tool loop: a scripted model asks for a tool, the chat calls it and sends its result back.
 */
final class ChatTest extends TestCase
{
    private static function answer(array $message, string $finish): CreateResponse
    {
        return CreateResponse::fake([
            'choices' => [['index' => 0, 'message' => $message + ['role' => 'assistant', 'content' => null, 'tool_calls' => []],
                'logprobs' => null, 'finish_reason' => $finish]],
        ], strategy: OverrideStrategy::Replace);
    }

    public function testAChatCallsTheToolAModelAsksForAndSendsItsResultBack(): void
    {
        $model = new ClientFake([
            self::answer(['tool_calls' => [['id' => 'call_1', 'type' => 'function',
                'function' => ['name' => 'datacircle_best_plans', 'arguments' => '{"slug":"email-finder","limit":1}']]]], 'tool_calls'),
            self::answer(['content' => 'Emelia is the best.'], 'stop'),
        ]);
        $http = new FakeHttpClient(FakeHttpClient::json(['api' => 'Employee email', 'slug' => 'email-finder', 'total' => 1,
            'plans' => [['rank' => 1, 'provider' => 'Emelia', 'plan' => 'Pro', 'score' => 76.3]]]));

        $config = new OpenAIConfig();
        $config->client = $model;
        $config->model = 'gpt-test';
        $chat = new OpenAIChat($config);
        $chat->setTools((new DatacircleTools(new Client(httpClient: $http)))->tools());

        $this->assertSame('Emelia is the best.', $chat->generateText('Who is the best for the email-finder API?'));
        $this->assertSame('https://api.datacircle.dev/v1/apis/email-finder/best?limit=1', $http->uri(0));

        $model->assertSent(\OpenAI\Resources\Chat::class, function (string $method, array $parameters): bool {
            $tool = json_decode(json_encode(end($parameters['messages']), JSON_THROW_ON_ERROR), true);

            return count($parameters['tools']) === 4 && ($tool['role'] ?? null) === 'tool'
                && str_contains((string) $tool['content'], '"provider":"Emelia"');
        });
    }
}
