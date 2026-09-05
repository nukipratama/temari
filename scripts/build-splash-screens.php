<?php

/**
 * One-off generator for the iOS apple-touch-startup-image set.
 * Regenerate after changing the mark or either ground token:
 * ./vendor/bin/sail php scripts/build-splash-screens.php
 */
$out = __DIR__.'/../public/splash';
@mkdir($out, 0o755, true);

/* The mark is rasterised from temari-mark.svg rather than composited from
   icon-512.png: that file carries its own #171f28 plate, which showed as a
   square against either ground. Drawing the strokes straight onto the ground
   leaves no edge to see, and lets each ground take the background it actually
   paints, so first launch does not step colour. Geometry stays sourced from the
   one SVG the in-app TemariMark draws. */
$mark = (string) file_get_contents(__DIR__.'/../resources/brand/logo/temari-mark.svg');

/** @var array<string, array{background: string, base: string}> Ground slug => background matching --color-background, plus the base-stroke colour that reads on it. */
$grounds = [
    'dark' => ['background' => '#0B1017', 'base' => '#F1F5F8'],  // sky-deep, cream strokes
    'light' => ['background' => '#F1F5F8', 'base' => '#171F28'], // cream, sky strokes
];

/** @var array<int, array{int, int}> Portrait device pixel sizes. */
$sizes = [
    [1170, 2532], // iPhone 13 / 13 Pro / 12 / 12 Pro
    [1179, 2556], // iPhone 14 Pro / 15 / 16
    [1290, 2796], // iPhone 14 Pro Max / 15 Plus / 16 Pro Max
    [1284, 2778], // iPhone 12/13 Pro Max / 14 Plus
    [1125, 2436], // iPhone X / XS / 11 Pro / 12 mini / 13 mini
    [828, 1792],  // iPhone XR / 11
    [750, 1334],  // iPhone SE (2nd/3rd gen) / 8
];

foreach ($grounds as $ground => $palette) {
    // The lead stroke is already a literal in the source; only the base stroke
    // has to follow the ground.
    $svg = str_replace('var(--mark-base, #171f28)', $palette['base'], $mark);

    foreach ($sizes as [$w, $h]) {
        $canvas = new Imagick();
        $canvas->newImage($w, $h, new ImagickPixel($palette['background']));
        $canvas->setImageFormat('png');

        // Mark at ~28% of the narrow edge, optically centred (slightly above middle).
        $target = (int) round($w * 0.28);
        $icon = new Imagick();
        $icon->setBackgroundColor(new ImagickPixel('transparent'));
        $icon->readImageBlob(str_replace(
            'width="100" height="100"',
            sprintf('width="%d" height="%d"', $target, $target),
            $svg,
        ));

        $x = (int) round(($w - $target) / 2);
        $y = (int) round(($h - $target) / 2 - $h * 0.04);
        $canvas->compositeImage($icon, Imagick::COMPOSITE_OVER, $x, $y);

        $canvas->stripImage();
        $canvas->writeImage(sprintf('%s/splash-%s-%dx%d.png', $out, $ground, $w, $h));

        $icon->destroy();
        $canvas->destroy();
        echo "wrote splash-{$ground}-{$w}x{$h}.png\n";
    }
}
