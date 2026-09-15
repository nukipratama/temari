<?php

declare(strict_types=1);

use App\Services\AI\Agent\AgentLoop;
use App\Services\AI\AzureOpenAIClient;
use App\Services\AI\StructuredChatCaller;
use Illuminate\Support\Facades\File;

/**
 * Keeps every outbound model call behind one metered boundary.
 *
 * `StructuredChatCaller` is where the cost ceiling, the config breaker, the
 * exception taxonomy and the `ai_token_usages` row live. A class that builds its
 * own OpenAI client, or reaches the transport directly, would bill Azure with
 * none of that — which no ceiling could then see.
 *
 * `AgentLoop` is the transport half of that boundary, not a second entry point:
 * it is constructed only by `StructuredChatCaller`, which folds every turn it
 * takes into the metering row.
 *
 * In the `structure` group, so it runs in the fast DB-free gate.
 */
const LLM_FACTORY_OWNER = AzureOpenAIClient::class;

const LLM_CLIENT_CALLERS = [
    StructuredChatCaller::class,
    AgentLoop::class,
];

const LLM_TRANSPORT_CALLERS = [
    StructuredChatCaller::class,
];

/**
 * @param  array<string, string>  $sources  fully-qualified class name => file contents
 * @return list<string>
 */
function llmBoundaryViolations(array $sources): array
{
    $violations = [];

    foreach ($sources as $class => $contents) {
        if ($class !== LLM_FACTORY_OWNER && preg_match('/\bOpenAI::/', $contents) === 1) {
            $violations[] = "{$class} builds its own OpenAI client (OpenAI::)";
        }

        if ($class !== LLM_FACTORY_OWNER
            && ! in_array($class, LLM_CLIENT_CALLERS, true)
            && str_contains($contents, 'AzureOpenAIClient')) {
            $violations[] = "{$class} reaches AzureOpenAIClient directly";
        }

        if (! in_array($class, LLM_TRANSPORT_CALLERS, true)
            && $class !== AgentLoop::class
            && preg_match('/\bAgentLoop\b/', $contents) === 1) {
            $violations[] = "{$class} reaches AgentLoop, bypassing StructuredChatCaller's metering";
        }
    }

    sort($violations);

    return $violations;
}

/** @return array<string, string> */
function appSources(): array
{
    $sources = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace('/', '\\', substr($file->getRelativePathname(), 0, -4));
        $sources['App\\'.$relative] = $file->getContents();
    }

    return $sources;
}

it('funnels every model call through StructuredChatCaller', function (): void {
    expect(llmBoundaryViolations(appSources()))->toBe([]);
})->group('structure');

it('fails when a class outside the boundary reaches the model', function (): void {
    $sources = [
        'App\\Services\\Run\\SneakyNarrator' => '<?php $client = OpenAI::factory()->make();',
        'App\\Http\\Controllers\\SneakyController' => '<?php use App\Services\AI\AzureOpenAIClient;',
        'App\\Jobs\\SneakyJob' => '<?php use App\Services\AI\Agent\AgentLoop;',
    ];

    expect(llmBoundaryViolations($sources))->toBe([
        'App\Http\Controllers\SneakyController reaches AzureOpenAIClient directly',
        'App\Jobs\SneakyJob reaches AgentLoop, bypassing StructuredChatCaller\'s metering',
        'App\Services\Run\SneakyNarrator builds its own OpenAI client (OpenAI::)',
    ]);
})->group('structure');
