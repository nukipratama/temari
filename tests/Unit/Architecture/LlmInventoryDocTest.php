<?php

declare(strict_types=1);

use App\Services\AI\AnalysisType;
use Illuminate\Support\Facades\File;

/**
 * Keeps docs/architecture/llm-triggers.md honest about what actually calls a
 * model. The note is the map of every LLM surface in the app, and a map that
 * silently omits a new narrator is worse than no map: the last audit found a
 * narrated block that had been billing every ingest for a page nobody rendered.
 *
 * Bidirectional on purpose. Forward, so a surface added without a doc row fails
 * here rather than going unnoticed; backward, so a surface deleted without a doc
 * edit fails too. In the `structure` group, so it runs in the fast DB-free gate.
 */
function llmInventoryDoc(): string
{
    return File::get(base_path('docs/architecture/llm-triggers.md'));
}

/** @return list<string> */
function narratorClassNames(): array
{
    return collect(File::files(app_path('Services/AI/Narrators')))
        ->map(fn ($file): string => $file->getFilenameWithoutExtension())
        ->filter(fn (string $name): bool => str_ends_with($name, 'Narrator'))
        ->values()
        ->all();
}

/** @return list<string> */
function concreteToolClassNames(): array
{
    return collect(File::files(app_path('Services/AI/Agent/Tools')))
        ->reject(fn ($file): bool => str_contains($file->getContents(), 'abstract class'))
        ->map(fn ($file): string => $file->getFilenameWithoutExtension())
        ->values()
        ->all();
}

it('documents every narrator that can reach the model', function (): void {
    $doc = llmInventoryDoc();

    $missing = array_values(array_filter(
        narratorClassNames(),
        fn (string $name): bool => ! str_contains($doc, $name),
    ));

    expect($missing)->toBe([], "Narrators missing from docs/architecture/llm-triggers.md:\n  ".implode("\n  ", $missing));
})->group('structure');

it('documents every concrete agent tool the model can be handed', function (): void {
    $doc = llmInventoryDoc();

    $missing = array_values(array_filter(
        concreteToolClassNames(),
        fn (string $name): bool => ! str_contains($doc, $name),
    ));

    expect($missing)->toBe([], "Agent tools missing from docs/architecture/llm-triggers.md:\n  ".implode("\n  ", $missing));
})->group('structure');

it('documents every AnalysisType case', function (): void {
    $doc = llmInventoryDoc();

    $missing = array_values(array_filter(
        array_column(AnalysisType::cases(), 'value'),
        fn (string $value): bool => ! str_contains($doc, $value),
    ));

    expect($missing)->toBe([], "AnalysisType cases missing from docs/architecture/llm-triggers.md:\n  ".implode("\n  ", $missing));
})->group('structure');

it('names no narrator or agent tool that has since been deleted', function (): void {
    // Everything above the retirement section, which is the one place allowed to
    // name a class precisely because it no longer exists.
    $doc = explode('## Retired surfaces', llmInventoryDoc())[0];

    preg_match_all('/\b([A-Z][A-Za-z0-9]*(?:Narrator|Tool))\b/', $doc, $matches);

    // Resolved against the whole app tree, not just the two AI directories: a
    // narrator interface can legitimately live under Run/Story.
    $live = collect(File::allFiles(app_path()))
        ->map(fn ($file): string => $file->getFilenameWithoutExtension())
        ->unique()
        ->all();

    $stale = array_values(array_unique(array_diff($matches[1], $live)));

    expect($stale)->toBe([], "The note names classes that no longer exist:\n  ".implode("\n  ", $stale));
})->group('structure');

/**
 * Narration surfaces that were retired on purpose. A retired value reappearing
 * in the note means either the note was written against a stale picture or the
 * surface came back without a decision; both are worth a red gate.
 * {@see \App\Models\Scopes\KnownAnalysisTypeScope} keeps their historical rows
 * queryable, which is exactly why nothing else would notice.
 */
it('never resurrects a retired narration surface', function (): void {
    $retired = ['pr_context', 'daily_greeting', 'trend_caption', 'persona_summary', 'briefing_featured_kartu_voice'];
    $doc = llmInventoryDoc();

    $live = array_column(AnalysisType::cases(), 'value');

    foreach ($retired as $value) {
        expect($live)->not->toContain($value);
        // The note may name a retired surface only where it explains the retirement.
        if (str_contains($doc, $value)) {
            expect($doc)->toContain('## Retired surfaces');
        }
    }
})->group('structure');
