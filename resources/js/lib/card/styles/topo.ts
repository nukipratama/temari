import { tracePoints, type PrintFacts } from '@/lib/card/facts';
import {
    CITRUS,
    CREAM,
    CREAM_DEEP,
    HORIZON_INK,
    INK,
    INK_2,
    INK_3,
    LEAF,
    LEAF_INK,
    LINE,
    LINE_STRONG,
    SURFACE_ELEV,
} from '@/lib/card/palette';
import {
    along,
    circle,
    closedLoop,
    doc,
    filledCells,
    group,
    isClosedLoop,
    joinWithin,
    line,
    measure,
    num,
    path,
    polylinePath,
    rect,
    serialSeed,
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
 * Style C — TOPO PLATE. A surveyed map sheet: a ticked collar, contour rings
 * under the trace, a scale bar and a north arrow, and a ruled title block. The
 * route is the subject rather than decoration, and the type is cartographic
 * throughout — mono at every size, uppercase, tracked out.
 *
 * Rarity escalates through the survey itself: seven contour rings at common
 * rising to fifteen at legendary, a hypsometric tint, the trace promoted to the
 * rarity colour and then thickened with a centre line, and a round survey stamp.
 */
export function renderTopoPlate(facts: PrintFacts, aspect: CardAspect): string {
    const story = aspect === 'story';
    const width = CARD_WIDTH;
    const height = cardHeight(aspect);
    const level = facts.level;
    const rarity = facts.rarityHex;
    const rarityInk = facts.rarityInk;

    const px = story ? 54 : 44;
    const py = story ? SAFE_TOP : 44;
    const pw = width - px * 2;
    const ph = story ? 1378 : height - py * 2;

    let out = rect(0, 0, width, height, { fill: CREAM_DEEP });
    out += rect(px, py, pw, ph, { fill: CREAM });
    out += collar(px, py, pw, ph);

    const headerSize = 24;
    const headerFont = { weight: 600, tracking: 4 };
    const rarityWord = `${facts.raritySymbol} ${facts.rarityLabel.toUpperCase()}`;
    const plateRoom =
        pw - 68 - measure(rarityWord, headerSize, headerFont) - 30;
    out += text(
        truncate(
            `PLATE ${facts.bib} · ${facts.placeShort}`,
            headerSize,
            plateRoom,
            headerFont,
        ),
        px + 34,
        py + 62,
        headerSize,
        INK,
        headerFont,
    );
    out += text(rarityWord, px + pw - 34, py + 62, headerSize, rarityInk, {
        ...headerFont,
        anchor: 'end',
    });
    out += line(px + 34, py + 80, px + pw - 34, py + 80, INK, { width: 2 });

    const fx = px + 34;
    const fy = py + 96;
    const fw = pw - 68;
    const titleHeight = story
        ? facts.form === 'race'
            ? 420
            : facts.form === 'long'
              ? 400
              : 330
        : 300;
    const fh = ph - (fy - py) - titleHeight - 34;

    out += `<clipPath id="plate-field"><rect x="${num(fx)}" y="${num(fy)}" width="${num(fw)}" height="${num(fh)}"/></clipPath>`;
    out += rect(fx, fy, fw, fh, {
        fill: SURFACE_ELEV,
        stroke: LINE,
        strokeWidth: 2,
    });

    const field =
        survey(facts, fx, fy, fw, fh, story, level, rarity) ??
        unsurveyed(facts, fx, fy, fw, fh, story);
    out += group(field, { clip: 'url(#plate-field)' });

    out += scaleBar(fx + 28, fy + fh - 44, facts.form === 'long' ? 4 : 2);
    out += northArrow(fx + fw - 48, fy + 62);

    if (level >= 4) {
        out += surveyStamp(facts, fx + fw - 150, fy + fh - 150, rarityInk);
    }

    out += titleBlock(facts, fx, fy + fh + 26, fw, story);
    out += legend(facts, fx, fw, py + ph - (story ? 40 : 34), story, rarityInk);

    return doc(width, height, group(out));
}

function collar(x: number, y: number, width: number, height: number): string {
    let svg = rect(x, y, width, height, { stroke: INK, strokeWidth: 5 });
    svg += rect(x + 16, y + 16, width - 32, height - 32, {
        stroke: INK,
        strokeWidth: 2,
    });

    for (let i = x + 60; i < x + width - 20; i += 60) {
        svg += line(i, y + 1, i, y + 16, INK, { width: 2 });
        svg += line(i, y + height - 16, i, y + height - 1, INK, { width: 2 });
    }
    for (let i = y + 60; i < y + height - 20; i += 60) {
        svg += line(x + 1, i, x + 16, i, INK, { width: 2 });
        svg += line(x + width - 16, i, x + width - 1, i, INK, { width: 2 });
    }

    return svg;
}

function survey(
    facts: PrintFacts,
    fx: number,
    fy: number,
    fw: number,
    fh: number,
    story: boolean,
    level: number,
    rarity: string,
): string | null {
    const pad = story ? 70 : 56;
    const projected = tracePoints(facts.polyline, fw - pad * 2, fh - pad * 2);
    if (projected === null) return null;

    const cx = fx + fw / 2;
    const cy = fy + fh / 2;
    const seed = serialSeed(facts.serial);
    const rings = 5 + level * 2;
    const rx = fw * 0.62;
    const ry = fh * 0.62;

    let svg = '';
    for (let i = rings; i >= 1; i--) {
        const t = i / rings;
        svg += path(closedLoop(cx, cy, rx * t, ry * t, seed + i * 5), {
            stroke: LEAF_INK,
            strokeWidth: i % 3 === 0 ? 3.5 : 2,
            strokeOpacity: i % 3 === 0 ? 0.42 : 0.26,
        });
    }
    if (level >= 3) {
        svg += path(closedLoop(cx, cy, rx * 0.5, ry * 0.5, seed + 11, 0.14), {
            fill: rarity,
            opacity: 0.07,
        });
    }

    const points: Point[] = projected.map(([x, y]) => [
        x + fx + pad,
        y + fy + pad,
    ]);
    const d = polylinePath(points, isClosedLoop(points, fw, fh));

    const traceColour =
        facts.form === 'pr' ? CITRUS : level >= 3 ? rarity : INK;
    svg += path(d, { stroke: CREAM, strokeWidth: 22 });
    svg += path(d, { stroke: traceColour, strokeWidth: level >= 4 ? 13 : 10 });
    if (level >= 4) {
        svg += path(d, {
            stroke: CREAM,
            strokeWidth: 3,
            strokeOpacity: 0.7,
            dash: '2 22',
        });
    }

    const start = points[0];
    svg += circle(start[0], start[1], 16, {
        fill: CREAM,
        stroke: INK,
        strokeWidth: 5,
    });
    svg += fieldLabel('START', start[0], start[1] + 8, fx, fw);

    if (facts.form === 'race') {
        const finish = points[points.length - 1];
        for (let i = 0; i < 8; i++) {
            svg += rect(
                finish[0] - 18 + (i % 2) * 18,
                finish[1] - 36 + Math.trunc(i / 2) * 18,
                18,
                18,
                {
                    fill: (i + Math.trunc(i / 2)) % 2 === 1 ? INK : CREAM,
                    stroke: INK,
                    strokeWidth: 1.5,
                },
            );
        }
        svg += fieldLabel('FINISH', finish[0], finish[1] + 64, fx, fw);
    }

    const ticks = facts.form === 'long' ? 3 : facts.form === 'race' ? 4 : 0;
    along(points, ticks).forEach(([mx, my], i) => {
        const km = Math.round((facts.distanceKm / (ticks + 1)) * (i + 1));
        svg += circle(mx, my, 15, {
            fill: CREAM,
            stroke: traceColour,
            strokeWidth: 4,
        });
        svg += text(String(km), mx, my + 7, 18, INK, {
            weight: 700,
            anchor: 'middle',
        });
    });

    return svg;
}

/** A label set away from the field edge it is nearest, so it never runs off. */
function fieldLabel(
    value: string,
    x: number,
    y: number,
    fx: number,
    fw: number,
): string {
    const leftward = x > fx + fw * 0.6;

    return text(value, x + (leftward ? -34 : 34), y, 20, INK_2, {
        anchor: leftward ? 'end' : 'start',
        tracking: 3,
    });
}

/** Nothing to survey: a blank grid under a diagonal hatch. */
function unsurveyed(
    facts: PrintFacts,
    fx: number,
    fy: number,
    fw: number,
    fh: number,
    story: boolean,
): string {
    let svg = '';
    for (let i = fx; i < fx + fw; i += 40) {
        svg += line(i, fy, i, fy + fh, LINE, { width: 1, opacity: 0.7 });
    }
    for (let i = fy; i < fy + fh; i += 40) {
        svg += line(fx, i, fx + fw, i, LINE, { width: 1, opacity: 0.7 });
    }
    for (let i = -fh; i < fw; i += 54) {
        svg += line(fx + i, fy + fh, fx + i + fh, fy, LINE_STRONG, {
            width: 3,
            opacity: 0.5,
        });
    }

    const cx = fx + fw / 2;
    const cy = fy + fh / 2;
    svg += text('UNSURVEYED', cx, cy - 18, story ? 68 : 54, INK_2, {
        weight: 700,
        anchor: 'middle',
        tracking: 12,
    });
    svg += line(fx + 60, cy + 60, fx + fw - 60, cy + 60, INK, {
        width: 12,
        cap: 'butt',
    });

    return (
        svg +
        text(
            `NO TRACE · ${facts.km} KM`,
            cx,
            cy + 110,
            story ? 26 : 22,
            INK_3,
            {
                anchor: 'middle',
                tracking: 5,
            },
        )
    );
}

function scaleBar(x: number, y: number, km: number): string {
    const segment = 54;
    let svg = '';
    for (let i = 0; i < 4; i++) {
        svg += rect(x + i * segment, y, segment, 12, {
            fill: i % 2 === 1 ? CREAM : INK,
            stroke: INK,
            strokeWidth: 2,
        });
    }

    return (
        svg +
        text('0', x, y + 34, 19, INK_2, { anchor: 'middle' }) +
        text(`${km} KM`, x + segment * 4, y + 34, 19, INK_2, {
            anchor: 'middle',
        })
    );
}

function northArrow(x: number, y: number): string {
    const d =
        `M${num(x)},${num(y - 34)} L${num(x + 15)},${num(y + 16)} ` +
        `L${num(x)},${num(y + 4)} L${num(x - 15)},${num(y + 16)} Z`;

    return (
        path(d, { fill: INK }) +
        text('N', x, y + 44, 22, INK, { weight: 700, anchor: 'middle' })
    );
}

function surveyStamp(
    facts: PrintFacts,
    x: number,
    y: number,
    rarityInk: string,
): string {
    const isPr = facts.form === 'pr';

    // Backed in paper: the stamp lands in a fixed corner of the field, and a
    // real trace can run straight under it.
    return (
        circle(x, y, 92, { fill: CREAM, opacity: 0.92 }) +
        circle(x, y, 92, {
            stroke: rarityInk,
            strokeWidth: 5,
            strokeOpacity: 0.85,
        }) +
        circle(x, y, 78, {
            stroke: rarityInk,
            strokeWidth: 2,
            strokeOpacity: 0.85,
        }) +
        text(isPr ? 'NEW BEST' : 'CERTIFIED', x, y - 10, 22, rarityInk, {
            weight: 700,
            anchor: 'middle',
            tracking: 3,
            opacity: 0.9,
        }) +
        text(
            isPr ? `${facts.km} KM` : facts.rarityLabel.toUpperCase(),
            x,
            y + 30,
            24,
            rarityInk,
            { weight: 700, anchor: 'middle', opacity: 0.9 },
        )
    );
}

function titleBlock(
    facts: PrintFacts,
    x: number,
    top: number,
    width: number,
    story: boolean,
): string {
    let svg = titleRow(
        x,
        top,
        width,
        [
            ['DISTANCE', `${facts.km} KM`],
            [facts.form === 'race' ? 'FINISH' : 'TIME', facts.time],
            ['PACE', `${facts.pace}/KM`],
        ],
        true,
    );
    let y = top + (story ? 128 : 118);

    const second: Cell[] = [
        ['DATE', facts.dateShort],
        ['START', facts.clock],
    ];
    if (facts.heartRate !== null) second.push(['AVG HR', facts.heartRate]);
    if (facts.elevation !== null) {
        second.push(['ELEV', `${facts.elevation} M`]);
    }
    const secondRow = titleRow(x, y, width, second, false);
    svg += secondRow;
    // A row that drew nothing leaves no gap behind it: what follows moves up.
    y += secondRow === '' ? 0 : story ? 96 : 90;

    if (!story) return svg;

    if (facts.form === 'race' && facts.splits.length > 0) {
        svg += line(x, y, x + width, y, INK, { width: 2 });
        svg += text('SPLITS', x + 18, y + 30, 19, INK_3, { tracking: 4 });
        const cellWidth = (width - 36) / facts.splits.length;
        facts.splits.forEach(([label, value], i) => {
            const sx = x + 18 + i * cellWidth;
            svg += text(label, sx, y + 62, 22, INK_3);
            svg += text(value, sx, y + 92, 30, INK, { weight: 700 });
        });
    }

    if (facts.form === 'long' && facts.paceProfile.length > 0) {
        svg += paceProfile(facts, x, y, width);
    }

    return svg;
}

/**
 * The run's own shape, km by km — the survey's record of effort rather than an
 * invented terrain profile, since no elevation series is stored.
 */
function paceProfile(
    facts: PrintFacts,
    x: number,
    y: number,
    width: number,
): string {
    let svg = line(x, y, x + width, y, INK, { width: 2 });
    svg += text('PACE PROFILE', x + 18, y + 28, 19, INK_3, { tracking: 4 });

    const gx = x + 18;
    const gy = y + 96;
    const gw = width - 36;
    const steps = facts.paceProfile.length;
    let d = `M${num(gx)},${num(gy)}`;
    facts.paceProfile.forEach((value, i) => {
        const px = gx + (steps < 2 ? 0 : (i / (steps - 1)) * gw);
        d += ` L${num(px)},${num(gy - 12 - value * 42)}`;
    });
    d += ` L${num(gx + gw)},${num(gy)} Z`;

    return (
        svg +
        path(d, { fill: LEAF, stroke: LEAF_INK, strokeWidth: 2, opacity: 0.22 })
    );
}

function titleRow(
    x: number,
    y: number,
    width: number,
    items: Cell[],
    big: boolean,
): string {
    const filled = filledCells(items);
    if (filled.length === 0) return '';

    let svg = line(x, y, x + width, y, INK, { width: 2 });
    const cellWidth = width / filled.length;

    filled.forEach(([label, value], i) => {
        const cx = x + i * cellWidth;
        if (i > 0) {
            svg += line(cx, y, cx, y + (big ? 118 : 86), LINE, { width: 2 });
        }
        svg += text(label, cx + 18, y + 30, 19, INK_3, { tracking: 4 });
        svg += text(value, cx + 18, y + (big ? 100 : 70), big ? 56 : 30, INK, {
            weight: big ? 700 : 500,
        });
    });

    return svg;
}

function legend(
    facts: PrintFacts,
    x: number,
    width: number,
    y: number,
    story: boolean,
    rarityInk: string,
): string {
    const size = story ? 22 : 19;
    const textX = x + (story ? 200 : 180);
    const column = width * 0.45;

    let svg = wordmark(x, y, story ? 48 : 42, HORIZON_INK);
    svg += text(
        truncate(facts.placeShort, size, column, { tracking: 2 }),
        textX,
        y - 22,
        size,
        INK_2,
        { tracking: 2 },
    );
    svg += text(
        truncate((facts.weather ?? '').toUpperCase(), size, column, {
            tracking: 2,
        }),
        textX,
        y + 6,
        size,
        INK_3,
        { tracking: 2 },
    );

    const badges = joinWithin(
        facts.badges.map((badge) => badge.toUpperCase()),
        '  ·  ',
        size,
        column,
        { weight: 600, tracking: 2 },
    );
    if (badges !== '') {
        svg += text(badges, x + width, y - 22, size, rarityInk, {
            weight: 600,
            anchor: 'end',
            tracking: 2,
        });
    }

    return (
        svg +
        text(facts.kind, x + width, y + 6, size, INK_3, {
            anchor: 'end',
            tracking: 4,
        })
    );
}
