<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\DataUseStatement;
use App\Support\TrainingDisclaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

$routes = [
    'legal.terms' => 'terms',
    'legal.privacy' => 'privacy',
    'legal.ai-use' => 'ai-use',
    'legal.training-disclaimer' => 'training-disclaimer',
];

it('serves every legal document to a signed-out stranger', function (string $route, string $slug): void {
    $this->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Legal/Document')
            ->where('slug', $slug)
            ->has('title')
            ->has('updated')
            ->has('intro')
            ->has('sections'));
})->with(collect($routes)->map(fn (string $slug, string $route): array => [$route, $slug])->values()->all());

it('serves the legal documents to a signed-in user too', function (string $route): void {
    $this->actingAs(User::factory()->create())->get(route($route))->assertOk();
})->with(array_keys($routes));

it('does not bounce a signed-in user off the legal pages the way it does off login', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('legal.privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Legal/Document'));
});

it('renders the AI data-use wording from the shared statement', function (): void {
    $this->get(route('legal.ai-use'))
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $paragraphs = collect($page->toArray()['props']['sections'])
                ->flatMap(fn (array $section): array => $section['paragraphs']);

            foreach (DataUseStatement::points() as $point) {
                expect($paragraphs)->toContain($point);
            }
        });
});

it('renders the same not-medical-advice wording the plan tab uses', function (): void {
    $this->get(route('legal.training-disclaimer'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('intro', TrainingDisclaimer::TEXT));
});
