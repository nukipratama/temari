<?php

declare(strict_types=1);

use App\Services\AI\Agent\AgentToolbox;
use Illuminate\Support\Facades\Log;

it('declares each tool in the flat Responses-API function shape', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => [])]);

    expect($toolbox->definitions())->toEqual([[
        'type' => 'function',
        'name' => 'get_thing',
        'description' => 'a test read',
        'parameters' => ['type' => 'object', 'properties' => (object) [], 'required' => [], 'additionalProperties' => false],
        'strict' => true,
    ]]);
});

it('reports an empty toolbox so the caller can stay one-shot', function (): void {
    expect(new AgentToolbox([])->isEmpty())->toBeTrue()
        ->and(new AgentToolbox([fakeAgentTool('get_thing', fn (): array => [])])->isEmpty())->toBeFalse();
});

it('runs the named tool and returns its payload as JSON', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => ['value' => 42])]);

    expect($toolbox->invoke('get_thing', '{}'))->toBe('{"value":42}');
});

it('passes decoded arguments through to the tool', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (array $args): array => ['echo' => $args['days'] ?? null])]);

    expect($toolbox->invoke('get_thing', '{"days":7}'))->toBe('{"echo":7}');
});

it('answers an invented tool name with an error instead of failing the block', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => [])]);

    expect($toolbox->invoke('get_nothing', '{}'))->toBe('{"error":"unknown tool: get_nothing"}');
});

it('answers malformed arguments with an error instead of failing the block', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => ['value' => 1])]);

    expect($toolbox->invoke('get_thing', '{not json'))->toBe('{"error":"arguments were not valid JSON"}');
});

it('answers a failing read with an error and logs it', function (): void {
    Log::spy();
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', function (): array {
        throw new RuntimeException('the database went away');
    })]);

    expect($toolbox->invoke('get_thing', '{}'))->toBe('{"error":"this reading is unavailable"}');
    Log::shouldHaveReceived('warning')->once()->with('narrator.ai.tool_failed', Mockery::on(
        fn (array $ctx): bool => $ctx['tool'] === 'get_thing' && $ctx['error'] === 'the database went away',
    ));
});

it('treats a bare empty argument string as no arguments', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (array $args): array => ['count' => count($args)])]);

    expect($toolbox->invoke('get_thing', ''))->toBe('{"count":0}');
});
