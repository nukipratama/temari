@props([
    /** 4-char sigil code; each char is a stitch op placed at top/right/bottom/left. */
    'pattern' => 'dddd',
    /** Render diameter in pixels. */
    'size' => 96,
    /** Stroke colour. */
    'color' => 'currentColor',
])

@php
    $chars = str_pad(substr($pattern, 0, 4), 4, 'd');
    // 4 cardinal positions around a 100-radius circle, evenly spaced.
    $positions = [
        ['x' => 50, 'y' => 6,  'rotate' => 0],   // top
        ['x' => 94, 'y' => 50, 'rotate' => 90],  // right
        ['x' => 50, 'y' => 94, 'rotate' => 180], // bottom
        ['x' => 6,  'y' => 50, 'rotate' => 270], // left
    ];

    /**
     * Renders one stitch op centered at (x, y), rotated outward from the
     * mascot centre. Each op is a tiny vector glyph — together they form
     * the "stitches" decorative ring around the mascot face.
     */
    $stitch = function (string $op, array $pos) use ($color): string {
        $transform = "translate({$pos['x']} {$pos['y']}) rotate({$pos['rotate']})";

        return match ($op) {
            'o' => "<g transform='{$transform}'><circle r='4' fill='none' stroke='{$color}' stroke-width='1.5' /></g>",
            'r' => "<g transform='{$transform}'><path d='M -4 0 A 4 4 0 0 1 4 0' fill='none' stroke='{$color}' stroke-width='1.5' /></g>",
            'c' => "<g transform='{$transform}'><line x1='-4' y1='0' x2='4' y2='0' stroke='{$color}' stroke-width='1.5'/><line x1='0' y1='-4' x2='0' y2='4' stroke='{$color}' stroke-width='1.5'/></g>",
            't' => "<g transform='{$transform}'><polygon points='0,-5 4,3 -4,3' fill='none' stroke='{$color}' stroke-width='1.5'/></g>",
            's' => "<g transform='{$transform}'><polygon points='0,-5 1,-1 5,-1 2,1 3,5 0,2 -3,5 -2,1 -5,-1 -1,-1' fill='{$color}'/></g>",
            'w' => "<g transform='{$transform}'><path d='M -5 0 Q -3 -3 0 0 T 5 0' fill='none' stroke='{$color}' stroke-width='1.5'/></g>",
            'v' => "<g transform='{$transform}'><polyline points='-4,2 0,-3 4,2' fill='none' stroke='{$color}' stroke-width='1.5'/></g>",
            'p' => "<g transform='{$transform}'><line x1='-4' y1='-4' x2='4' y2='4' stroke='{$color}' stroke-width='1.5'/><line x1='-4' y1='4' x2='4' y2='-4' stroke='{$color}' stroke-width='1.5'/></g>",
            'l' => "<g transform='{$transform}'><line x1='-4' y1='4' x2='4' y2='-4' stroke='{$color}' stroke-width='1.5'/></g>",
            'f' => "<g transform='{$transform}'><rect x='-4' y='-1' width='8' height='2' fill='{$color}'/></g>",
            'h' => "<g transform='{$transform}'><line x1='-5' y1='0' x2='5' y2='0' stroke='{$color}' stroke-width='1.5'/></g>",
            default => "<g transform='{$transform}'><circle r='1.5' fill='{$color}'/></g>",
        };
    };

    $stitches = '';
    foreach ([0, 1, 2, 3] as $i) {
        $stitches .= $stitch($chars[$i], $positions[$i]);
    }
@endphp

<svg viewBox="0 0 100 100"
     width="{{ $size }}"
     height="{{ $size }}"
     aria-hidden="true"
     {{ $attributes }}>
    <circle cx="50" cy="50" r="44" fill="none" stroke="{{ $color }}" stroke-width="1" stroke-dasharray="2 3" opacity="0.4" />
    {!! $stitches !!}
</svg>
