<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Guards the derived `-ink` tokens against every ground they actually land on.
 *
 * The method S2.9 established was right — target the darkest renderable ground.
 * Its ground list was not: it enumerated the five `--color-surface` values
 * dawn-shift drifts between and missed `--color-cream-deep`, which is what
 * AppShell paints under the whole app and is darker than all five. So nothing
 * here writes grounds down. The values come out of the shipped stylesheet, the
 * backgrounds in play come out of the components, and resources/brand/grounds.json
 * only records what *kind* each one is. A background in use that grounds.json
 * does not classify fails this suite rather than being skipped.
 */
function tokenLuminance(string $hex): float
{
    $n = (int) hexdec(mb_substr($hex, 1));
    $channels = [($n >> 16) & 255, ($n >> 8) & 255, $n & 255];

    $linear = array_map(static function (int $value): float {
        $c = $value / 255;

        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }, $channels);

    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
}

function tokenContrast(string $a, string $b): float
{
    $pair = [tokenLuminance($a), tokenLuminance($b)];
    sort($pair);

    return ($pair[1] + 0.05) / ($pair[0] + 0.05);
}

/**
 * @return array{tokens: array<string, string>, shifts: array<string, string>}
 */
function designTokens(): array
{
    $css = File::get(resource_path('css/app.css'));

    preg_match('/@theme static \{.*?\n\}/s', $css, $theme);
    preg_match_all('/--color-([a-z0-9-]+):\s*(#[0-9a-f]{6});/', $theme[0], $found, PREG_SET_ORDER);

    $tokens = [];
    foreach ($found as [, $name, $value]) {
        $tokens[$name] = $value;
    }

    preg_match_all(
        '/body\[data-time-of-day=\'([a-z]+)\'\]\s*\{\s*--color-surface:\s*(#[0-9a-f]{6});/',
        $css,
        $matches,
        PREG_SET_ORDER,
    );

    $shifts = [];
    foreach ($matches as [, $name, $value]) {
        $shifts[$name] = $value;
    }

    return ['tokens' => $tokens, 'shifts' => $shifts];
}

/**
 * @return array{paper: list<string>, scoped: list<string>, fill: list<string>, keyword: list<string>, tint: array<string, float>}
 */
function groundKinds(): array
{
    return File::json(resource_path('brand/grounds.json'));
}

/** @return list<string> */
function componentSources(): array
{
    $sources = [];
    foreach (File::allFiles(resource_path('js')) as $file) {
        if (in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            $sources[] = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], ' ', $file->getContents()) ?? '';
        }
    }

    return $sources;
}

/**
 * The heaviest `bg-<family>/<alpha>` a component paints under
 * `text-<family>-ink`, which is the real ground that chip prints on.
 *
 * @return array<string, float>
 */
function paintedInkTints(): array
{
    $heaviest = [];

    foreach (componentSources() as $source) {
        preg_match_all('/\'[^\']*\'|"[^"]*"|`[^`]*`/s', $source, $literals);

        foreach ($literals[0] as $literal) {
            preg_match_all(
                '/\bbg-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)\/(?:\[([0-9.]+)\]|([0-9]{1,3}))/',
                $literal,
                $found,
                PREG_SET_ORDER,
            );

            foreach ($found as $match) {
                $name = $match[1];
                if (preg_match('/text-'.preg_quote($name, '/').'-ink\b/', $literal) !== 1) {
                    continue;
                }
                $alpha = $match[2] !== '' ? (float) $match[2] : (float) $match[3] / 100;
                $heaviest[$name] = max($heaviest[$name] ?? 0.0, $alpha);
            }
        }
    }

    return $heaviest;
}

/** `$fill` at `$alpha` over `$ground`, the way the compositor does it. */
function compositeOver(string $fill, float $alpha, string $ground): string
{
    $of = (int) hexdec(mb_substr($fill, 1));
    $og = (int) hexdec(mb_substr($ground, 1));

    $mixed = '';
    foreach ([16, 8, 0] as $shift) {
        $f = ($of >> $shift) & 255;
        $g = ($og >> $shift) & 255;
        $mixed .= mb_str_pad(dechex((int) round($f * $alpha + $g * (1 - $alpha))), 2, '0', STR_PAD_LEFT);
    }

    return '#'.$mixed;
}

/**
 * Every `bg-*` utility the components paint, with any `/alpha` modifier dropped.
 * Comments are stripped first: prose like `bg-mood-{key}` is not a class the
 * browser ever sees, and scanning it would invent backgrounds that do not exist.
 *
 * @return list<string>
 */
function paintedBackgrounds(): array
{
    $names = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }

        $source = preg_replace(
            ['#/\*.*?\*/#s', '#//[^\n]*#'],
            ' ',
            $file->getContents(),
        ) ?? '';

        preg_match_all('/\bbg-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)\b/', $source, $found);
        $names = [...$names, ...$found[1]];
    }

    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

/**
 * The papers any `-ink` can land on: every background grounds.json calls paper,
 * plus each surface dawn-shift drifts to.
 *
 * @param  array<string, string>  $tokens
 * @param  array<string, string>  $shifts
 * @return array<string, string>
 */
function paperGrounds(array $tokens, array $shifts): array
{
    $grounds = [];
    foreach (groundKinds()['paper'] as $name) {
        $grounds[$name] = $tokens[$name];
    }
    foreach ($shifts as $bucket => $value) {
        $grounds["surface · {$bucket}"] = $value;
    }

    return $grounds;
}

it('classifies every background the components paint', function (): void {
    $kinds = groundKinds();
    $known = array_merge($kinds['paper'], $kinds['scoped'], $kinds['fill'], $kinds['keyword']);

    $unclassified = array_values(array_diff(paintedBackgrounds(), $known));

    expect($unclassified)->toBe([], sprintf(
        "These bg-* utilities are painted but unclassified, so nothing scored them:\n  %s\n".
        'Add each to resources/brand/grounds.json as paper (ink lands on it), scoped '.
        "(only its own family's ink does), fill (no ink text) or keyword (not a --color-* token).",
        implode("\n  ", $unclassified),
    ));
})->group('structure');

it('backs every classified colour ground with a declared token', function (): void {
    ['tokens' => $tokens] = designTokens();
    $kinds = groundKinds();

    $missing = [];
    foreach ([...$kinds['paper'], ...$kinds['scoped']] as $name) {
        if (! isset($tokens[$name])) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([], sprintf(
        'grounds.json classifies these as a ground, but no --color-* token declares them: %s',
        implode(', ', $missing),
    ));
})->group('structure');

it('finds more grounds than dawn-shift alone declares', function (): void {
    ['tokens' => $tokens, 'shifts' => $shifts] = designTokens();

    // The S2.9 blind spot was scoring only --color-surface and its drifts. A
    // paper ground outside that set is exactly what went unscored, so the
    // derivation is only doing its job while it reaches past them.
    $beyondDawnShift = array_diff_key(
        paperGrounds($tokens, $shifts),
        array_flip(array_map(fn (string $b): string => "surface · {$b}", array_keys($shifts))),
        ['surface' => ''],
    );

    expect($beyondDawnShift)->not->toBeEmpty();
})->group('structure');

it('records the tint every ink actually prints on', function (): void {
    // grounds.json carries the alphas because the live audit runs in a browser
    // and cannot read the components. This is what keeps that copy honest: a
    // heavier tint, or a new one, goes red until it is recorded.
    expect(groundKinds()['tint'])->toEqual(paintedInkTints());
})->group('structure');

it('keeps every -ink token above AA on every ground it lands on', function (): void {
    ['tokens' => $tokens, 'shifts' => $shifts] = designTokens();
    $papers = paperGrounds($tokens, $shifts);
    ['scoped' => $scoped, 'tint' => $tints] = groundKinds();
    $darkestPaper = collect($papers)->sortBy(fn (string $hex): float => tokenLuminance($hex))->first();

    $inks = array_filter(
        $tokens,
        fn (string $name): bool => str_ends_with($name, '-ink') && $name !== 'ink',
        ARRAY_FILTER_USE_KEY,
    );

    expect($inks)->not->toBeEmpty();

    $under = [];
    foreach ($inks as $name => $hex) {
        $grounds = $papers;

        // A family's own tinted cell is a ground only that family's ink lands
        // on. The pairing is the naming convention, so a new -bg cell is scored
        // the moment grounds.json calls it scoped.
        $family = mb_substr($name, 0, -mb_strlen('-ink'));
        $own = $family.'-bg';
        if (in_array($own, $scoped, true) && isset($tokens[$own])) {
            $grounds[$own] = $tokens[$own];
        }

        // A chip painted bg-<family>/<alpha> prints on the tint, not on the
        // paper under it. Composited over the darkest paper it can sit on.
        if (isset($tints[$family], $tokens[$family])) {
            $alpha = $tints[$family];
            $grounds["{$family}/{$alpha} on paper"] = compositeOver($tokens[$family], $alpha, $darkestPaper);
        }

        foreach ($grounds as $ground => $paper) {
            $ratio = tokenContrast($hex, $paper);
            if ($ratio < 4.5) {
                $under[] = sprintf('--color-%s on %s (%s): %.2f', $name, $ground, $paper, $ratio);
            }
        }
    }

    expect($under)->toBe([], "These -ink tokens are under 4.5:1 as text:\n  ".implode("\n  ", $under));
})->group('structure');

it('keeps the separator above its floor on every ground', function (): void {
    ['tokens' => $tokens, 'shifts' => $shifts] = designTokens();

    foreach (paperGrounds($tokens, $shifts) as $ground => $paper) {
        expect(tokenContrast($tokens['line'], $paper))
            ->toBeGreaterThanOrEqual(1.4, "--color-line is under 1.4:1 on {$ground}.");
    }
})->group('structure');

/** A `token/alpha` spec, or a bare token at full opacity. */
function splitAlpha(string $spec): array
{
    $parts = explode('/', $spec);

    return [$parts[0], isset($parts[1]) ? (float) $parts[1] : 1.0];
}

/** The alpha spelled the way grounds.mjs spells it, so both sides agree on a key. */
function alphaSpec(string $name, float $alpha): string
{
    return $name.'/'.rtrim(rtrim(number_format($alpha, 4, '.', ''), '0'), '.');
}

/**
 * Every `bg-<token>/<alpha>` the components paint, mapped to the files that
 * paint it.
 *
 * paintedBackgrounds() drops the alpha, which is right for classifying a panel
 * and wrong for scoring it: `bg-sky/40` is not sky, it is sky over whatever it
 * is mounted on, and it carries the text sky would take. The mount varies by
 * call site, so the call site is the key.
 *
 * @param  array<string, string>  $tokens
 * @return array<string, list<string>>
 */
function paintedAlphaPanelSites(array $tokens): array
{
    $sites = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            continue;
        }
        $source = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], ' ', $file->getContents()) ?? '';
        $relative = 'resources/js/'.$file->getRelativePathname();

        preg_match_all(
            '/\bbg-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)\/(?:\[([0-9.]+)\]|([0-9]{1,3}))(?![\w\-.])/',
            $source,
            $found,
            PREG_SET_ORDER,
        );
        foreach ($found as $match) {
            if (! isset($tokens[$match[1]])) {
                continue;
            }
            $alpha = ($match[2] ?? '') !== '' ? (float) $match[2] : (float) $match[3] / 100;
            $sites[alphaSpec($match[1], $alpha)][$relative] = true;
        }
    }

    $out = [];
    foreach ($sites as $spec => $files) {
        $out[$spec] = array_keys($files);
        sort($out[$spec]);
    }
    ksort($out);

    return $out;
}

/**
 * Every `text-<token>` painted in the same class string as an alpha panel. One
 * class string is one element, so a pair found here definitely stacks; a panel
 * carrying text from a child element is invisible here and is recorded by hand.
 *
 * @param  array<string, string>  $tokens
 * @return array<string, list<string>>
 */
function paintedPanelText(array $tokens): array
{
    $painted = [];

    foreach (componentSources() as $source) {
        // A quoted literal cannot span a raw newline, but a naive `'[^']*'`
        // does: an apostrophe in JSX text ("Temari's") opens a match that runs
        // to the next one, swallowing whole subtrees and pairing a background
        // in one element with text in another.
        preg_match_all('/\'[^\'\n]*\'|"[^"\n]*"|`[^`]*`/s', $source, $literals);

        foreach ($literals[0] as $literal) {
            // No Tailwind class holds an angle or curly bracket, so this drops
            // what is left of a mis-paired line.
            if (preg_match('/[<>{}]/', $literal) === 1) {
                continue;
            }

            preg_match_all(
                '/(?:^|[\s\'"`])((?:[a-z0-9-]+:)*)bg-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)\/(?:\[([0-9.]+)\]|([0-9]{1,3}))(?![\w\-.])/',
                $literal,
                $panels,
                PREG_SET_ORDER,
            );
            if ($panels === []) {
                continue;
            }

            preg_match_all(
                '/(?:^|[\s\'"`])((?:[a-z0-9-]+:)*)text-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)(?:\/(?:\[([0-9.]+)\]|([0-9]{1,3})))?(?![\w-])/',
                $literal,
                $texts,
                PREG_SET_ORDER,
            );

            $labels = [];
            foreach ($texts as $match) {
                if (! isset($tokens[$match[2]])) {
                    continue;
                }
                $bracket = $match[3] ?? '';
                $plain = $match[4] ?? '';
                $labels[] = [
                    'variant' => $match[1],
                    'spec' => $bracket === '' && $plain === ''
                        ? $match[2]
                        : alphaSpec($match[2], $bracket !== '' ? (float) $bracket : (float) $plain / 100),
                ];
            }

            foreach ($panels as $panel) {
                if (! isset($tokens[$panel[2]])) {
                    continue;
                }
                $alpha = ($panel[3] ?? '') !== '' ? (float) $panel[3] : (float) $panel[4] / 100;
                $key = alphaSpec($panel[2], $alpha);

                // A `hover:bg-*` tint is painted in the hover state, so the text
                // on it is the `hover:text-*` the same element declares, not the
                // base one it replaces.
                $sameState = array_values(array_filter(
                    $labels,
                    fn (array $l): bool => $l['variant'] === $panel[1],
                ));
                $applicable = $sameState !== [] ? $sameState : array_values(array_filter(
                    $labels,
                    fn (array $l): bool => $l['variant'] === '',
                ));

                $painted[$key] = [
                    ...($painted[$key] ?? []),
                    ...array_column($applicable, 'spec'),
                ];
            }
        }
    }

    foreach ($painted as $key => $labels) {
        $labels = array_values(array_unique($labels));
        sort($labels);
        $painted[$key] = $labels;
    }

    return $painted;
}

/**
 * Every registered panel/text pair, at the mount that scores worst. `paper`
 * stands for the whole paper set; any other mount names the solid token the
 * panel sits on.
 *
 * @return array<string, float>
 */
function panelPairRatios(): array
{
    ['tokens' => $tokens, 'shifts' => $shifts] = designTokens();
    $papers = paperGrounds($tokens, $shifts);

    $ratios = [];
    foreach (groundKinds()['panel'] as $spec => $entry) {
        if ($entry['text'] === [] || ! isset($entry['over'])) {
            continue;
        }
        [$panel, $panelAlpha] = splitAlpha($spec);

        $mounts = [];
        foreach (array_unique(array_merge(...array_values($entry['over']))) as $mount) {
            if ($mount === 'paper') {
                $mounts = [...$mounts, ...array_values($papers)];

                continue;
            }
            $mounts[] = $tokens[$mount];
        }

        foreach ($entry['text'] as $text) {
            [$ink, $inkAlpha] = splitAlpha($text);
            $worst = null;
            foreach ($mounts as $mount) {
                $ground = compositeOver($tokens[$panel], $panelAlpha, $mount);
                $ratio = tokenContrast(compositeOver($tokens[$ink], $inkAlpha, $ground), $ground);
                $worst = $worst === null ? $ratio : min($worst, $ratio);
            }
            $ratios["{$spec} + {$text}"] = round($worst, 2);
        }
    }

    return $ratios;
}

it('registers every translucent panel call site', function (): void {
    ['tokens' => $tokens] = designTokens();
    $registry = groundKinds()['panel'];
    $painted = paintedAlphaPanelSites($tokens);

    $unregistered = [];
    foreach ($painted as $spec => $files) {
        if (! isset($registry[$spec])) {
            $unregistered[] = $spec;

            continue;
        }
        if ($registry[$spec]['text'] === []) {
            continue;
        }
        foreach ($files as $file) {
            if (! isset($registry[$spec]['over'][$file])) {
                $unregistered[] = "{$spec} @ {$file}";
            }
        }
    }

    expect($unregistered)->toBe([], sprintf(
        "These translucent panel call sites are unregistered, so nothing scored the text on them:\n  %s\n".
        'Add each to the "panel" block of resources/brand/grounds.json, recording the ground that call '.
        'site is mounted on ("paper", or the solid token it sits on) and the text tokens that land on it.',
        implode("\n  ", $unregistered),
    ));

    $stale = [];
    foreach ($registry as $spec => $entry) {
        if (! isset($painted[$spec])) {
            $stale[] = $spec;

            continue;
        }
        foreach (array_keys($entry['over'] ?? []) as $file) {
            if (! in_array($file, $painted[$spec], true)) {
                $stale[] = "{$spec} @ {$file}";
            }
        }
    }

    expect($stale)->toBe([], 'These registered panel call sites paint nothing any more; drop them from grounds.json.');
})->group('structure');

it('records every panel/text pair painted in one class string', function (): void {
    ['tokens' => $tokens] = designTokens();
    $registry = groundKinds()['panel'];

    $missing = [];
    foreach (paintedPanelText($tokens) as $spec => $labels) {
        foreach ($labels as $label) {
            if (! in_array($label, $registry[$spec]['text'] ?? [], true)) {
                $missing[] = "{$spec} + {$label}";
            }
        }
    }

    expect($missing)->toBe([], sprintf(
        "These panels carry text that grounds.json does not record:\n  %s",
        implode("\n  ", $missing),
    ));
})->group('structure');

/**
 * Every opaque `bg-<token>` painted in the same class string as a `text-<token>`,
 * scored straight: with no alpha the fill *is* the ground, so there is nothing
 * to composite it over.
 *
 * The panel registry only reaches `bg-<token>/<alpha>`, and the sweep above only
 * reaches tokens named `-ink`. An opaque fill carrying a label that is neither
 * was scored by nothing, which is how the rarity flags shipped under AA.
 *
 * @param  array<string, string>  $tokens
 * @return array<string, float>
 */
function paintedOpaqueFillText(array $tokens): array
{
    $ratios = [];

    foreach (componentSources() as $source) {
        preg_match_all('/\'[^\'\n]*\'|"[^"\n]*"|`[^`]*`/s', $source, $literals);

        foreach ($literals[0] as $literal) {
            if (preg_match('/[<>{}]/', $literal) === 1) {
                continue;
            }

            preg_match_all(
                '/(?:^|[\s\'"`])((?:[a-z0-9-]+:)*)bg-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)(?![\w\-.\/])/',
                $literal,
                $fills,
                PREG_SET_ORDER,
            );
            if ($fills === []) {
                continue;
            }

            preg_match_all(
                '/(?:^|[\s\'"`])((?:[a-z0-9-]+:)*)text-([a-z][a-z0-9]*(?:-[a-z0-9]+)*)(?![\w\-.\/])/',
                $literal,
                $labels,
                PREG_SET_ORDER,
            );

            foreach ($fills as $fill) {
                if (! isset($tokens[$fill[2]])) {
                    continue;
                }

                foreach ($labels as $label) {
                    if (! isset($tokens[$label[2]]) || $label[1] !== $fill[1]) {
                        continue;
                    }

                    $ratios['bg-'.$fill[2].' + text-'.$label[2]] = round(
                        tokenContrast($tokens[$label[2]], $tokens[$fill[2]]),
                        2,
                    );
                }
            }
        }
    }

    ksort($ratios);

    return $ratios;
}

it('keeps every label on an opaque fill above AA', function (): void {
    ['tokens' => $tokens] = designTokens();
    $pairs = paintedOpaqueFillText($tokens);

    expect($pairs)->not->toBeEmpty();

    $under = [];
    foreach ($pairs as $pair => $ratio) {
        if ($ratio < 4.5) {
            $under[] = sprintf('%s: %.2f', $pair, $ratio);
        }
    }

    expect($under)->toBe([], sprintf(
        "These labels print on an opaque fill under 4.5:1:\n  %s\n".
        'The fill is the ground here, so an -ink derived against paper scores worse, not better; '.
        'reach for a tone derived against the fill itself.',
        implode("\n  ", $under),
    ));
})->group('structure');

it('keeps every panel/text pair above AA, or pinned in the ledger', function (): void {
    $ratios = panelPairRatios();
    $ledger = groundKinds()['belowAa'];

    $under = array_filter($ratios, fn (float $ratio): bool => $ratio < 4.5);

    expect(array_keys($under))->toEqualCanonicalizing(array_keys($ledger), sprintf(
        "The set of panel/text pairs under 4.5:1 has moved.\n  under now: %s\n  ledger:    %s\n".
        'A new pair means a real contrast failure; a ledger entry that no longer fails means the fix landed '.
        'and the entry should go.',
        implode(', ', array_keys($under)),
        implode(', ', array_keys($ledger)),
    ));

    foreach ($ledger as $pair => $pinned) {
        expect($ratios[$pair])->toBe(
            (float) $pinned,
            "{$pair} measures {$ratios[$pair]}:1, pinned at {$pinned}:1 in grounds.json's belowAa ledger.",
        );
    }
})->group('structure');
