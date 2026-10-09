<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use Illuminate\Contracts\Support\Arrayable;
use Override;
use App\Enums\Mood;

/**
 * @phpstan-type AnalysisPayload array{
 *     id: int|null,
 *     status: string,
 *     content: string|null,
 *     type: string,
 *     subject_type: string,
 *     subject_id: int,
 *     discriminator: string|null,
 * }
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class BriefingResult implements Arrayable
{
    /**
     * @param  AnalysisPayload  $mascotVoice
     */
    public function __construct(
        public array $mascotVoice,
        /** No briefing has ever been narrated for this athlete, so a pending one says so instead of staying silent. */
        public bool $firstRead,
        public Mood $mood,
    ) {
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'mascotVoice' => $this->mascotVoice,
            'firstRead' => $this->firstRead,
            'mood' => $this->mood->value,
        ];
    }
}
