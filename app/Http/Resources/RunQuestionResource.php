<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AI\RunQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin RunQuestion
 */
class RunQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'activity_id' => $this->activity_id,
            'question' => $this->question,
            'answer' => $this->answer,
            'status' => $this->status->value,
            'asked_at' => $this->created_at->toIso8601String(),
        ];
    }
}
