<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

class AnalysisResource extends JsonResource
{
    public function __construct(
        ?Analysis $resource,
        private readonly AnalysisType $type,
        private readonly int $subjectId,
        private readonly ?string $discriminator,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return Analysis::toPayload($this->resource, $this->type, $this->type->subjectType(), $this->subjectId, $this->discriminator);
    }
}
