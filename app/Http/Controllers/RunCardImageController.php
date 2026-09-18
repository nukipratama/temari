<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\User;
use App\Services\Run\Story\Card\CardAspect;
use App\Services\Run\Story\Card\CardOptions;
use App\Services\Run\Story\Card\CardStyle;
use App\Services\Run\Story\RunCardImageRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Serves the share PNG for one run: `GET /activities/{activity}/card.png` with
 * `style`, `aspect` and the optional-fact toggles as query parameters.
 *
 * The rasteriser is the expensive part, so each tuple is cached — flipping a
 * fact chip back and forth in the share popup costs one render per combination,
 * not one per tap. The card's `updated_at` is part of the key, so a rebuilt
 * card (a rarity climb, a new badge) invalidates every variant of itself.
 */
class RunCardImageController extends Controller
{
    private const int CACHE_TTL_SECONDS = 3600;

    public function __invoke(Request $request, Activity $activity, RunCardImageRenderer $renderer): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('view', $activity), 404);

        $card = $activity->runCard;
        abort_if($card === null, 404, 'This run has no card yet.');

        $style = CardStyle::parse($request->query('style') === null ? null : (string) $request->query('style'));
        $aspect = CardAspect::parse($request->query('aspect') === null ? null : (string) $request->query('aspect'));
        $options = CardOptions::fromArray($request->query());

        $png = Cache::remember(
            sprintf(
                'run-card-png:%d:%d:%s:%s:%s',
                $card->id,
                $card->updated_at?->getTimestamp() ?? 0,
                $style->value,
                $aspect->value,
                $options->cacheKey(),
            ),
            self::CACHE_TTL_SECONDS,
            fn (): string => $renderer->render($card, $style, $aspect, $options),
        );

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => sprintf('inline; filename="temari-%s-%s.png"', $style->value, $aspect->value),
            'Cache-Control' => 'private, max-age='.self::CACHE_TTL_SECONDS,
        ]);
    }
}
