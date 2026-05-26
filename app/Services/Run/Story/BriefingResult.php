<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use Illuminate\Contracts\Support\Arrayable;
use Override;

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
     * @param  AnalysisPayload  $headline
     * @param  AnalysisPayload  $suggestion
     * @param  AnalysisPayload  $mascotVoice
     * @param  AnalysisPayload  $featuredKartuVoice
     */
    public function __construct(
        public string $vibeState,
        public string $vibeLabel,
        public string $vibeEmoji,
        public array $headline,
        public array $suggestion,
        public array $mascotVoice,
        public array $featuredKartuVoice,
        public string $recoveryLabel,
        public string $recoveryTone,
        public ?string $recoveryHoursLabel,
        public ?string $streakLabel,
        public string $sigilPattern,
        public ?string $accessory,
        public string $mood,
    ) {
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'vibeState' => $this->vibeState,
            'vibeLabel' => $this->vibeLabel,
            'vibeEmoji' => $this->vibeEmoji,
            'headline' => $this->headline,
            'suggestion' => $this->suggestion,
            'mascotVoice' => $this->mascotVoice,
            'featuredKartuVoice' => $this->featuredKartuVoice,
            'recoveryLabel' => $this->recoveryLabel,
            'recoveryTone' => $this->recoveryTone,
            'recoveryHoursLabel' => $this->recoveryHoursLabel,
            'streakLabel' => $this->streakLabel,
            'sigilPattern' => $this->sigilPattern,
            'accessory' => $this->accessory,
            'mood' => $this->mood,
        ];
    }
}
