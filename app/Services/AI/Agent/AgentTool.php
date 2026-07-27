<?php

declare(strict_types=1);

namespace App\Services\AI\Agent;

/**
 * One read the narrating model may pull for itself instead of receiving it
 * pre-computed in the prompt context.
 *
 * A tool is always constructed already bound to its subject (the activity, the
 * user), never given ids to look up: scoping is a property of construction, so
 * there is no argument a model could pass to reach another user's data.
 */
interface AgentTool
{
    /** Snake-case identifier the model calls. */
    public function name(): string;

    /** What the tool returns, in the terms the model should decide with. */
    public function description(): string;

    /**
     * JSON Schema for the call arguments.
     *
     * @return array<string, mixed>
     */
    public function parameters(): array;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array;
}
