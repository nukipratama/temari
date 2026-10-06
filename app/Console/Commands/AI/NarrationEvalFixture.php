<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use Closure;

/**
 * `build` runs inside the command's transaction and returns the case to call, or null when the athlete's history cannot support it.
 */
final readonly class NarrationEvalFixture
{
    /**
     * @param  Closure(): ?array{generate: Closure(): string, evidence: array<string, mixed>, direction: array{required: list<string>, forbidden: list<string>}, plain_text: bool}  $build
     */
    public function __construct(
        public string $kind,
        public string $name,
        public Closure $build,
    ) {
    }
}
