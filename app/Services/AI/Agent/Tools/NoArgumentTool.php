<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Services\AI\Agent\AgentTool;

/**
 * A tool bound to its subject at construction, so the model has nothing to pass
 * and no way to point it somewhere else.
 */
abstract class NoArgumentTool implements AgentTool
{
    /** @return array<string, mixed> */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
            'required' => [],
            'additionalProperties' => false,
        ];
    }
}
