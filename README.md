# Datacircle tools for LLPhant

Find the best GTM APIs. We benchmark all the GTM APIs and we put the results on our website.

https://datacircle.dev

This package gives [LLPhant](https://github.com/LLPhant/LLPhant) four tools that call Datacircle's open API, https://api.datacircle.dev/v1. You need no key and no account.

| Tool | What the tool gives | Call |
| --- | --- | --- |
| `datacircle_list_apis` | Every GTM API on Datacircle, with the API's slug, number of plans, number of providers and best plan | `GET /apis` |
| `datacircle_best_plans` | One API's plans, sorted by score out of 100, best first. Each plan comes with a rank and a price per 1,000 calls | `GET /apis/{slug}/best` |
| `datacircle_search_plans` | Plans that match a provider name or plan name. Search all APIs or one API. Sort the plans by score, provider or price | `GET /plans` |
| `datacircle_provider` | The APIs a provider sells, and the provider's rank on each API | `GET /providers/{slug}` |

## Install

```bash
composer require datacircle/llphant-tools
```

The package needs PHP 8.2 or later and LLPhant 1.0 or later.

## Use the tools

```php
use Datacircle\LLPhant\DatacircleTools;
use LLPhant\Chat\OpenAIChat;

$chat = new OpenAIChat();
$chat->setTools((new DatacircleTools())->tools());

echo $chat->generateText('Who is the best provider for the email-finder API?');
```

`tools()` gives the four tools as LLPhant `FunctionInfo` objects. The chat calls each tool the model asks for and sends the result back to the model. The tools work the same with `AnthropicChat`, `OllamaChat`, `LmStudioChat`, `generateChatOrReturnFunctionToCall()` and `generateStreamOfText()`. A streamed tool call needs LLPhant 1.0.8 or later: older versions drop the arguments the model streams.

You can give a model only some of the tools:

```php
$datacircle = new DatacircleTools();
$chat->addTool($datacircle->tool('datacircle_best_plans'));
$chat->addTool($datacircle->tool('datacircle_provider'));
```

You can also call a tool yourself:

```php
echo $datacircle->bestPlans('email-finder', 3);
echo $datacircle->searchPlans(query: 'bright data', sort: 'price', limit: 5);
```

Each tool gives back a JSON string. Each plan comes with a rank, provider, plan, score, price per 1,000 (`perThousand`, in dollars) and a page on datacircle.dev. That page lists every field. When the API gives no data, the tool returns `{"error": "..."}` with the API's own message. An unknown slug also returns `{"error": "..."}`. The model can then call again. A tool reads numbers sent as text and leaves out an argument it has no parameter for. A network failure throws an exception, and LLPhant passes it up to your code.

`new Client(baseUrl: ..., timeout: ..., httpClient: ...)` sets the API address, the timeout in seconds (30 by default) and any PSR-18 HTTP client (Guzzle by default). Pass the client to `new DatacircleTools($client)`.

## Network

Each tool call makes one HTTPS GET request to https://api.datacircle.dev/v1 and nothing else. The request holds only the tool's own arguments: no key and nothing from your chats or config. The API takes up to 60 calls a minute from one IP address. The tool waits once, for a minute at most, when the API asks the tool to wait. The package stores nothing.

## Links

- Datacircle: https://datacircle.dev
- The API: https://datacircle.dev/api
- The API's spec: https://api.datacircle.dev/v1/openapi.json
- What each key means: https://datacircle.dev/fields

MIT license.
