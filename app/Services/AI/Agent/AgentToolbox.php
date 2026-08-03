<?php

declare(strict_types=1);

namespace App\Services\AI\Agent;

use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * The set of tools one narration run may call, and the dispatcher for them.
 *
 * Nothing here throws. A model that invents a tool name, sends malformed
 * arguments, or trips a failing read gets an error payload back and another
 * turn to recover — failing the whole block over a recoverable mistake would
 * cost the user their narration and the retry budget.
 */
final readonly class AgentToolbox
{
    /** @param  list<AgentTool>  $tools */
    public function __construct(private array $tools)
    {
    }

    public function isEmpty(): bool
    {
        return $this->tools === [];
    }

    /**
     * The Responses API tool declarations. Function tools are flat there — name
     * and parameters sit on the tool object itself, not under a `function` key.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_map(fn (AgentTool $tool): array => [
            'type' => 'function',
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->parameters(),
            'strict' => true,
        ], $this->tools);
    }

    /**
     * Run one tool call, returning the JSON string to hand back as the call's
     * output.
     */
    public function invoke(string $name, string $argumentsJson): string
    {
        $tool = $this->find($name);
        if ($tool === null) {
            return self::encode(['error' => "unknown tool: {$name}"]);
        }

        try {
            $arguments = $argumentsJson === '' ? [] : json_decode($argumentsJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::encode(['error' => 'arguments were not valid JSON']);
        }

        try {
            return self::encode($tool->handle(is_array($arguments) ? $arguments : []));
        } catch (Throwable $e) {
            Log::warning('narrator.ai.tool_failed', [
                'tool' => $name,
                'error' => $e->getMessage(),
            ]);

            return self::encode(['error' => 'this reading is unavailable']);
        }
    }

    private function find(string $name): ?AgentTool
    {
        foreach ($this->tools as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Serialise a reading, leaving out everything the run has no answer for. A
     * null key is dropped entirely (a reading with nothing to report comes back
     * as `{}`); every tool description says what a MISSING field means, never
     * what a null one does — that wording is the contract, do not strip a null
     * key without updating it. Empty arrays survive: `[]` is itself an answer
     * ("no zones recorded"), unlike a missing key.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function encode(array $payload): string
    {
        // Cast to object so an empty payload encodes as `{}`, not `[]` — the
        // model is being handed an object, not a differently-shaped answer.
        // Payloads are always string-keyed, so this never renumbers anything.
        return (string) json_encode((object) self::withoutNulls($payload), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function withoutNulls(array $payload): array
    {
        $kept = [];
        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $inner = self::withoutNulls($value);
                // Emptied *by* the strip means it held nothing but nulls, so it
                // is absent like they are. One that arrived empty is an answer
                // in its own right and stays.
                if ($inner === [] && $value !== []) {
                    continue;
                }
                $kept[$key] = $inner;

                continue;
            }

            $kept[$key] = $value;
        }

        return $kept;
    }
}
