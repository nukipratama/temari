<?php

/**
 * One-off generator for the iOS apple-touch-startup-image set.
 * Composites the app icon onto the Daybreak cream background_color so a cold
 * standalone launch shows the brand instead of a white flash.
 * Regenerate after changing public/icon-512.png or the surface token:
 * ./vendor/bin/sail php scripts/build-splash-screens.php
 */
$out = __DIR__.'/../public/splash';
@mkdir($out, 0o755, true);

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

foreach ($sizes as [$w, $h]) {
    // Matches manifest background_color (#F6F1E8, --color-surface).
    $canvas = new Imagick();
    $canvas->newImage($w, $h, new ImagickPixel('#F6F1E8'));
    $canvas->setImageFormat('png');

    // Icon at ~28% of the narrow edge, optically centred (slightly above middle).
    $icon = new Imagick(__DIR__.'/../public/icon-512.png');
    $target = (int) round($w * 0.28);
    $icon->resizeImage($target, $target, Imagick::FILTER_LANCZOS, 1);

    $x = (int) round(($w - $target) / 2);
    $y = (int) round(($h - $target) / 2 - $h * 0.04);
    $canvas->compositeImage($icon, Imagick::COMPOSITE_OVER, $x, $y);

    $canvas->stripImage();
    $canvas->writeImage(sprintf('%s/splash-%dx%d.png', $out, $w, $h));

    $icon->destroy();
    $canvas->destroy();
    echo "wrote splash-{$w}x{$h}.png\n";
}
