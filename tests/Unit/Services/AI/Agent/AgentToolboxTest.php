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

// ── Null stripping ───────────────────────────────────────────────────
//
// A null key told the model nothing it could act on while still costing it a
// name to read. They are dropped, which means every tool description has to say
// what a MISSING field means rather than what a null one does -- that wording is
// the contract this behaviour depends on.

it('leaves out the keys a reading has no answer for', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => [
        'distance_km' => 5.2,
        'avg_hr' => null,
        'avg_cadence_spm' => null,
    ])]);

    expect($toolbox->invoke('get_thing', '{}'))->toBe('{"distance_km":5.2}');
});

it('strips nulls out of nested readings too', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => [
        'relative_effort' => ['trimp' => 80, 'baseline' => null],
    ])]);

    expect($toolbox->invoke('get_thing', '{}'))->toBe('{"relative_effort":{"trimp":80}}');
});

// A nested reading emptied *by* the strip held nothing but nulls, so it is
// absent like they are rather than an empty object the model has to interpret.
it('drops a nested reading that was nothing but nulls', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => [
        'distance_km' => 5.2,
        'relative_effort' => ['trimp' => null, 'baseline' => null],
    ])]);

    expect($toolbox->invoke('get_thing', '{}'))->toBe('{"distance_km":5.2}');
});

// A reading with nothing at all to report comes back empty. That is a real
// answer -- "there is no data here" -- and the persona tells the model to read
// it as one rather than as a broken tool.
it('answers with an empty object when a reading has nothing to report', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => ['training_load' => null])]);

    expect($toolbox->invoke('get_thing', '{}'))->toBe('{}');
});

// An empty array is an answer ("no zones recorded"), unlike a missing key, so it
// has to survive the strip.
it('keeps an empty array, which says something a missing key does not', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => [
        'zone_pct' => [],
        'trimp' => null,
    ])]);

    expect($toolbox->invoke('get_thing', '{}'))->toBe('{"zone_pct":[]}');
});

it('keeps a zero and a false, which are data rather than absence', function (): void {
    $toolbox = new AgentToolbox([fakeAgentTool('get_thing', fn (): array => [
        'omitted_km' => 0,
        'negative_split' => false,
        'gap_pace' => null,
    ])]);

    expect($toolbox->invoke('get_thing', '{}'))->toBe('{"omitted_km":0,"negative_split":false}');
});
