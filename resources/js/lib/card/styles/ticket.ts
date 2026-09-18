import { statCells, tracePoints, type PrintFacts } from '@/lib/card/facts';
import {
    CITRUS,
    CREAM,
    CREAM_DEEP,
    EMBER,
    EMBER_INK,
    HORIZON_INK,
    INK,
    INK_2,
    INK_3,
    LINE,
    LINE_STRONG,
    SKY_DEEP,
    SURFACE_ELEV,
    readableInk,
} from '@/lib/card/palette';
import {
    circle,
    doc,
    filledCells,
    fitSize,
    group,
    isClosedLoop,
    line,
    measure,
    num,
    path,
    polylinePath,
    rect,
    text,
    truncate,
    wordmark,
    type Point,
} from '@/lib/card/svg';
import {
    CARD_WIDTH,
    SAFE_TOP,
    cardHeight,
    type CardAspect,
    type Cell,
} from '@/lib/card/types';

/**
 * Style B — TICKET. Printed stock: an ink band across the top, ruled boxes for
 * every figure, a perforated tear and a stub carrying the place, date, weather,
 * wordmark and rarity dots. Mono throughout; Fraunces appears only in the
 * wordmark on the stub.
 *
 * Form follows the run structurally — a race stops being a ticket and becomes a
 * bib — and rarity escalates through print finishing: band ink, a second ruled
 * border, a foil strip, a second perforation row.
 */
export function renderTicket(facts: PrintFacts, aspect: CardAspect): string {
    const story = aspect === 'story';
    const width = CARD_WIDTH;
    const height = cardHeight(aspect);
    const rarity = facts.rarityHex;
    const level = facts.level;

    const tx = story ? 58 : 46;
    const ty = story ? SAFE_TOP + 2 : 46;
    const tw = width - tx * 2;
    const th = story ? 1368 : height - ty * 2;

    let out = rect(0, 0, width, height, { fill: CREAM_DEEP });
    out += `<rect x="${num(tx)}" y="${num(ty)}" width="${num(tw)}" height="${num(th)}" fill="${CREAM}" filter="url(#ticket-shadow)"/>`;
    if (level >= 3) {
        out += rect(tx + 14, ty + 14, tw - 28, th - 28, {
            stroke: LINE,
            strokeWidth: 2,
        });
    }

    const bandHeight = story ? 96 : 84;
    const bandInk = readableInk(rarity);
    out += rect(tx, ty, tw, bandHeight, { fill: rarity });
    if (level >= 4) {
        out += rect(tx, ty + bandHeight, tw, 10, { fill: 'url(#ticket-foil)' });
    }

    const titleSize = story ? 36 : 32;
    const titleFont = { weight: 700, tracking: 5 };
    const serialSize = story ? 28 : 24;
    const titleRoom =
        tw - 60 - measure(facts.serial, serialSize, { tracking: 3 }) - 30;
    out += text(
        truncate(
            facts.form === 'race' ? (facts.raceName ?? '') : facts.kind,
            titleSize,
            titleRoom,
            titleFont,
        ),
        tx + 30,
        ty + bandHeight * 0.65,
        titleSize,
        bandInk,
        titleFont,
    );
    out += text(
        facts.serial,
        tx + tw - 30,
        ty + bandHeight * 0.65,
        serialSize,
        bandInk,
        { anchor: 'end', tracking: 3 },
    );

    const pad = story ? 42 : 36;
    const bx = tx + pad;
    const bw = tw - pad * 2;
    const cy = ty + bandHeight + (story ? 46 : 36);
    const perfY = ty + th - (story ? 250 : 196);

    if (facts.form === 'race') {
        out += bib(facts, tx, ty, tw, bandHeight, bx, bw, cy, perfY, story, rarity);
    } else if (story) {
        out += storyBody(facts, bx, bw, cy, perfY, rarity);
    } else {
        out += feedBody(facts, bx, bw, cy, perfY, rarity);
    }

    out += perforation(tx, perfY, tw);
    if (level >= 5) {
        out += perforation(tx, perfY + 26, tw);
    }
    out += stub(facts, tx, tw, bx, pad, perfY, story, rarity);

    if (facts.form === 'pr') {
        out += overstamp(tx, tw, perfY, story);
    }

    return doc(width, height, group(out), defs(rarity));
}

/** A race turns the chassis into a bib: pin holes and one enormous number. */
function bib(
    facts: PrintFacts,
    tx: number,
    ty: number,
    tw: number,
    bandHeight: number,
    bx: number,
    bw: number,
    cy: number,
    perfY: number,
    story: boolean,
    rarity: string,
): string {
    let svg = '';
    for (const [px, py] of [
        [tx + 46, ty + bandHeight + 40],
        [tx + tw - 46, ty + bandHeight + 40],
        [tx + 46, perfY - 40],
        [tx + tw - 46, perfY - 40],
    ]) {
        svg += circle(px, py, 15, {
            fill: CREAM_DEEP,
            stroke: LINE,
            strokeWidth: 2,
        });
    }

    const bibY = cy + (story ? 300 : 210);
    svg += text(facts.bib, tx + tw / 2, bibY, story ? 340 : 240, INK, {
        weight: 800,
        anchor: 'middle',
    });
    svg += text(
        facts.raceDistance ?? '',
        tx + tw / 2,
        bibY + (story ? 76 : 58),
        story ? 54 : 42,
        rarity,
        { weight: 700, anchor: 'middle', tracking: 14 },
    );

    const items = statCells(facts);
    items[0] = ['FINISH', facts.time];
    const cellHeight = story ? 132 : 116;
    let rowY = bibY + (story ? 130 : 96);
    svg += cells(bx, rowY, bw, cellHeight, items);
    rowY += cellHeight + (story ? 34 : 24);

    if (story) {
        return svg + window(facts, bx, rowY, bw, perfY - rowY - 46, rarity);
    }

    // No splits, no panel: the window is the block that always has something to
    // draw, even if that something is the cancelled hatch.
    return (
        svg +
        (facts.splits.length === 0
            ? window(facts, bx, rowY, bw, perfY - rowY - 30, rarity)
            : chipSplits(facts, bx, rowY, bw, perfY - rowY - 30))
    );
}

function storyBody(
    facts: PrintFacts,
    bx: number,
    bw: number,
    cy: number,
    perfY: number,
    rarity: string,
): string {
    const heroHeight = 300;
    let svg = rect(bx, cy, bw, heroHeight, {
        fill: SURFACE_ELEV,
        stroke: LINE,
        strokeWidth: 2,
    });
    svg += text('DISTANCE', bx + 24, cy + 46, 22, INK_3, { tracking: 5 });
    svg += text(
        facts.km,
        bx + 24,
        cy + heroHeight - 44,
        fitSize(facts.km, 224, bw - 150, { weight: 800 }),
        INK,
        { weight: 800 },
    );
    svg += text('KM', bx + bw - 24, cy + heroHeight - 52, 62, INK_3, {
        weight: 700,
        anchor: 'end',
        tracking: 4,
    });

    if (facts.form === 'pr') {
        svg += rect(bx + bw - 250, cy + 26, 226, 76, { fill: CITRUS, radius: 8 });
        svg += text('NEW BEST', bx + bw - 137, cy + 76, 30, INK, {
            weight: 700,
            anchor: 'middle',
        });
    }
    let y = cy + heroHeight + 30;

    svg += cells(bx, y, bw, 138, statCells(facts));
    y += 168;

    const windowHeight = perfY - y - (facts.badges.length === 0 ? 44 : 110);
    svg += window(
        facts,
        bx,
        y,
        bw,
        windowHeight,
        facts.form === 'pr' ? CITRUS : rarity,
    );
    y += windowHeight + 26;

    let chipX = bx;
    for (const badge of facts.badges) {
        const label = badge.toUpperCase();
        const chip = measure(label, 22, { tracking: 2 }) + 46;
        svg += rect(chipX, y, chip, 58, {
            radius: 6,
            stroke: rarity,
            strokeWidth: 3,
        });
        svg += text(label, chipX + 23, y + 39, 22, facts.rarityInk, {
            tracking: 2,
        });
        chipX += chip + 16;
    }

    return svg;
}

/** Feed: figures in the left column, the route window in the right. */
function feedBody(
    facts: PrintFacts,
    bx: number,
    bw: number,
    cy: number,
    perfY: number,
    rarity: string,
): string {
    const columnWidth = bw * 0.46;
    let svg = text('DISTANCE', bx, cy + 30, 20, INK_3, { tracking: 5 });
    svg += text(
        facts.km,
        bx - 6,
        cy + 168,
        fitSize(facts.km, 168, columnWidth + 24, { weight: 800 }),
        INK,
        { weight: 800 },
    );
    svg += text('KM', bx, cy + 218, 40, INK_3, { weight: 700, tracking: 6 });

    if (facts.form === 'pr') {
        svg += rect(bx, cy + 244, columnWidth - 10, 62, {
            fill: CITRUS,
            radius: 6,
        });
        svg += text(
            'NEW BEST',
            bx + (columnWidth - 10) / 2,
            cy + 286,
            28,
            INK,
            { weight: 700, anchor: 'middle' },
        );
    }

    const rowTop = cy + (facts.form === 'pr' ? 322 : 250);
    const rows: Cell[] = [
        ['TIME', facts.time],
        ['PACE', facts.pace],
    ];
    rows.forEach(([label, value], i) => {
        const y = rowTop + i * 96;
        svg += line(bx, y, bx + columnWidth, y, LINE, { width: 2 });
        svg += text(label, bx, y + 32, 20, INK_3, { tracking: 4 });
        svg += text(value, bx + columnWidth, y + 74, 48, INK, {
            weight: 700,
            anchor: 'end',
        });
    });

    return (
        svg +
        window(
            facts,
            bx + columnWidth + 26,
            cy,
            bw - columnWidth - 26,
            perfY - cy - 40,
            facts.form === 'pr' ? CITRUS : rarity,
        )
    );
}

/**
 * The route printed as an inset window with frame ticks, or cancelled with a
 * diagonal hatch and a band when the run has no trace.
 */
function window(
    facts: PrintFacts,
    x: number,
    y: number,
    width: number,
    height: number,
    accent: string,
): string {
    let svg = rect(x, y, width, height, {
        fill: SURFACE_ELEV,
        stroke: LINE,
        strokeWidth: 2,
    });
    for (let i = 1; i < 10; i++) {
        const tick = x + (width / 10) * i;
        svg += line(tick, y, tick, y + 10, LINE, { width: 2 });
        svg += line(tick, y + height - 10, tick, y + height, LINE, { width: 2 });
    }
    for (let i = 1; i < 6; i++) {
        const tick = y + (height / 6) * i;
        svg += line(x, tick, x + 10, tick, LINE, { width: 2 });
        svg += line(x + width - 10, tick, x + width, tick, LINE, { width: 2 });
    }

    const pad = 44;
    const projected = tracePoints(
        facts.polyline,
        width - pad * 2,
        height - pad * 2,
    );
    if (projected === null) return svg + cancelled(x, y, width, height);

    const points: Point[] = projected.map(([px, py]) => [
        px + x + pad,
        py + y + pad,
    ]);
    svg += path(polylinePath(points, isClosedLoop(points, width, height)), {
        stroke: accent,
        strokeWidth: 9,
    });
    svg += circle(points[0][0], points[0][1], 12, {
        fill: SURFACE_ELEV,
        stroke: INK,
        strokeWidth: 5,
    });
    svg += text('ROUTE', x + 16, y + height - 18, 20, INK_3, { tracking: 4 });
    svg += text(
        truncate(facts.placeShort, 20, width - 130, { tracking: 2 }),
        x + width - 16,
        y + height - 18,
        20,
        INK_3,
        { anchor: 'end', tracking: 2 },
    );

    return svg;
}

function cancelled(
    x: number,
    y: number,
    width: number,
    height: number,
): string {
    let svg = '';
    for (let i = -height; i < width; i += 26) {
        svg += line(x + i, y + height, x + i + height, y, LINE, {
            width: 3,
            opacity: 0.55,
        });
    }

    const label = 'NO SIGNAL · NO ROUTE';
    const size = fitSize(label, 34, width - 120, { weight: 700 });
    svg += rect(x + 20, y + height / 2 - 42, width - 40, 84, {
        fill: SURFACE_ELEV,
        stroke: EMBER,
        strokeWidth: 3,
    });

    return (
        svg +
        text(label, x + width / 2, y + height / 2 + 12, size, EMBER_INK, {
            weight: 700,
            anchor: 'middle',
        })
    );
}

function cells(
    x: number,
    y: number,
    width: number,
    height: number,
    items: Cell[],
): string {
    const filled = filledCells(items);
    if (filled.length === 0) return '';

    let svg = rect(x, y, width, height, { stroke: LINE, strokeWidth: 2 });
    const cellWidth = width / filled.length;

    filled.forEach(([label, value], i) => {
        const cx = x + i * cellWidth;
        if (i > 0) {
            svg += line(cx, y, cx, y + height, LINE, { width: 2 });
        }
        svg += text(label, cx + 22, y + 40, 20, INK_3, { tracking: 4 });
        svg += text(value, cx + 22, y + height - 28, 52, INK, { weight: 700 });
    });

    return svg;
}

function chipSplits(
    facts: PrintFacts,
    x: number,
    y: number,
    width: number,
    height: number,
): string {
    let svg = rect(x, y, width, height, {
        fill: SURFACE_ELEV,
        stroke: LINE,
        strokeWidth: 2,
    });
    svg += text('CHIP SPLITS', x + 20, y + 36, 20, INK_3, { tracking: 4 });

    const cellWidth = (width - 40) / facts.splits.length;
    facts.splits.forEach(([label, value], i) => {
        const sx = x + 20 + i * cellWidth;
        if (i > 0) {
            svg += line(sx - 12, y + 50, sx - 12, y + height - 18, LINE, {
                width: 2,
            });
        }
        svg += text(label, sx, y + 74, 20, INK_3);
        svg += text(value, sx, y + 112, 30, INK, { weight: 700 });
    });

    return svg;
}

function perforation(x: number, y: number, width: number): string {
    return (
        line(x, y, x + width, y, LINE_STRONG, { width: 2, dash: '10 12' }) +
        circle(x, y, 20, { fill: CREAM_DEEP }) +
        circle(x + width, y, 20, { fill: CREAM_DEEP })
    );
}

function stub(
    facts: PrintFacts,
    tx: number,
    tw: number,
    bx: number,
    pad: number,
    perfY: number,
    story: boolean,
    rarity: string,
): string {
    const y = perfY + (story ? 80 : 64);
    const meta = [
        facts.dateLong,
        facts.clock,
        (facts.weather ?? '').toUpperCase(),
    ].filter((part) => part !== '');
    const stubRoom = tw - pad * 2;

    let svg = wordmark(bx, y + (story ? 8 : 4), story ? 54 : 46, HORIZON_INK);
    svg += text(
        truncate(
            facts.place.toUpperCase(),
            story ? 24 : 21,
            stubRoom,
            { tracking: 2 },
        ),
        bx,
        y + (story ? 66 : 54),
        story ? 24 : 21,
        INK_2,
        { tracking: 2 },
    );
    svg += text(
        truncate(meta.join(' · '), story ? 22 : 19, stubRoom, { tracking: 1 }),
        bx,
        y + (story ? 108 : 88),
        story ? 22 : 19,
        INK_3,
        { tracking: 1 },
    );
    svg += text(
        facts.rarityLabel.toUpperCase(),
        tx + tw - pad,
        y + (story ? 8 : 4),
        story ? 26 : 22,
        facts.rarityInk,
        { weight: 700, anchor: 'end', tracking: 4 },
    );

    for (let i = 0; i < facts.bandCount; i++) {
        svg += circle(tx + tw - pad - i * 26, y + (story ? 44 : 36), 8, {
            fill: rarity,
        });
    }

    return svg;
}

/** A rotated PERSONAL RECORD stamp, printed across the paper. */
function overstamp(
    tx: number,
    tw: number,
    perfY: number,
    story: boolean,
): string {
    const inner =
        rect(-226, -60, 452, 120, {
            radius: 10,
            stroke: EMBER,
            strokeWidth: 7,
            strokeOpacity: 0.8,
        }) +
        text('PERSONAL RECORD', 0, 20, 50, EMBER, {
            weight: 800,
            anchor: 'middle',
            tracking: 4,
            opacity: 0.8,
        });

    return group(inner, {
        transform: `translate(${num(tx + tw * 0.52)},${num(perfY - (story ? 320 : 150))}) rotate(-8) scale(${story ? '0.95' : '0.58'})`,
    });
}

function defs(rarity: string): string {
    return (
        '<filter id="ticket-shadow" x="-20%" y="-20%" width="140%" height="140%">' +
        `<feDropShadow dx="0" dy="14" stdDeviation="16" flood-color="${SKY_DEEP}" flood-opacity="0.18"/>` +
        '</filter>' +
        '<linearGradient id="ticket-foil" x1="0" y1="0" x2="1" y2="0">' +
        `<stop offset="0" stop-color="${rarity}" stop-opacity="0.15"/>` +
        `<stop offset="0.5" stop-color="${rarity}" stop-opacity="0.9"/>` +
        `<stop offset="1" stop-color="${rarity}" stop-opacity="0.15"/>` +
        '</linearGradient>'
    );
}
