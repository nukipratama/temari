<?php

declare(strict_types=1);

namespace App\Models\AI;

use App\Services\AI\ServedBy;
use Database\Factories\AI\AnalysisVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * A superseded narration: what an {@see Analysis} row held just before a
 * re-narration overwrote it.
 *
 * @property int $id
 * @property int $analysis_id
 * @property string $content
 * @property string|null $fingerprint
 * @property ServedBy|null $served_by
 * @property Carbon|null $generated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable(['analysis_id', 'content', 'fingerprint', 'served_by', 'generated_at'])]
class AnalysisVersion extends Model
{
    /** @use HasFactory<AnalysisVersionFactory> */
    use HasFactory;

    #[Override]
    protected $table = 'analysis_versions';

    /** @return BelongsTo<Analysis, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(Analysis::class);
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'analysis_id' => 'integer',
            'served_by' => ServedBy::class,
            'generated_at' => 'datetime',
        ];
    }
}
