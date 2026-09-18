import { statCells, tracePoints, type PrintFacts } from '@/lib/card/facts';
import {
    CITRUS,
    CREAM,
    DISPLAY,
    HORIZON,
    INK_ON_SKY,
    PR_GROUND,
    SKY_DEEP,
    readableInk,
} from '@/lib/card/palette';
import {
    along,
    circle,
    doc,
    filledCells,
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
    fitSize,
    type Point,
} from '@/lib/card/svg';
import {
    CARD_WIDTH,
    SAFE_BOTTOM,
    SAFE_TOP,
    cardHeight,
    type CardAspect,
} from '@/lib/card/types';

/**
 * Style A — BROADSHEET. An editorial poster: a thin masthead rule carrying the
 * wordmark and rarity word, one enormous Fraunces-italic figure set over a
 * ghosted drawing of the route, and a ruled stat baseline in mono.
 *
 * Form follows the run by changing which number is the hero and how much air
 * the route gets; rarity escalates in additive chrome — a rarity word, then an
 * edge bar, a coloured band, a diagonal wedge with a misregistered echo behind
 * the figure, and finally a whole-card wash.
 */
export function renderBroadsheet(
    facts: PrintFacts,
    aspect: CardAspect,
): string {
    const story = aspect === 'story';
    const width = CARD_WIDTH;
    const height = cardHeight(aspect);
    const margin = story ? 84 : 76;
    const top = story ? 300 : 132;
    const bottom = story ? 1600 : 972;
    const safeTop = story ? SAFE_TOP : 0;
    const safeBottom = story ? SAFE_BOTTOM : height;

    const rarity = facts.rarityHex;
    const level = facts.level;
    const isPr = facts.form === 'pr';
    const isRace = facts.form === 'race';
    const isLong = facts.form === 'long';
    const ground = isPr ? PR_GROUND : SKY_DEEP;

    let out = rect(0, 0, width, height, { fill: ground });

    if (level >= 4) {
        out += path(
            `M0,${num(height)} L0,${num(height * 0.42)} L${num(width)},${num(height * 0.14)} L${num(width)},${num(height)} Z`,
            { fill: rarity, opacity: 0.1 },
        );
    }
    if (level >= 2) {
        out += rect(0, safeTop, 10, safeBottom - safeTop, { fill: rarity });
    }
    if (level >= 5) {
        out += rect(0, 0, width, height, { fill: rarity, opacity: 0.06 });
    }

    out += isRace
        ? raceBand(facts, width, margin, safeTop, rarity)
        : masthead(facts, width, margin, top, rarity);

    const routeTop = story ? (isRace ? top + 150 : top + 118) : top + 70;
    const routeHeight = story ? (isLong ? 880 : 700) : 420;
    const routeX = margin + (isLong ? -margin : 40);
    const routeWidth = width - 2 * margin + (isLong ? 2 * margin : -80);

    out +=
        route(
            facts,
            routeX,
            routeTop,
            routeWidth,
            routeHeight,
            rarity,
            ground,
        ) ?? noSignalBelt(width, margin, routeTop, routeHeight, story);

    out += hero(facts, width, margin, story);

    if (isRace && story && facts.splits.length > 0) {
        out += splits(facts, width, margin, routeTop);
    }
    if (isLong) {
        out += text('LONG RUN', 0, 0, 26, CREAM, {
            tracking: 12,
            opacity: 0.35,
            transform: `translate(${num(width - 32)},${story ? '620' : '400'}) rotate(90)`,
        });
    }

    out += statBaseline(facts, width, margin, bottom, story);
    out += footer(facts, width, margin, bottom, story, rarity, isRace);

    return doc(width, height, group(out));
}

function masthead(
    facts: PrintFacts,
    width: number,
    margin: number,
    top: number,
    rarity: string,
): string {
    const metaSize = 23;
    const metaFont = { tracking: 2 };
    // The date is right-anchored at the far margin, so the meta line's budget
    // is whatever the date leaves behind rather than a character count.
    const dateWidth = measure(facts.dateLong, metaSize, metaFont);
    const metaRoom = width - 2 * margin - dateWidth - 40;
    const meta =
        facts.weather === null
            ? truncate(facts.placeShort, metaSize, metaRoom, metaFont)
            : truncate(
                  `${facts.placeShort} · ${facts.weather.toUpperCase()}`,
                  metaSize,
                  metaRoom,
                  metaFont,
              );

    return (
        wordmark(margin, top, 54, HORIZON) +
        text(
            `${facts.raritySymbol}  ${facts.rarityLabel.toUpperCase()}`,
            width - margin,
            top - 6,
            24,
            rarity,
            { weight: 600, anchor: 'end', tracking: 4 },
        ) +
        line(margin, top + 30, width - margin, top + 30, CREAM, {
            width: 2,
            opacity: 0.22,
        }) +
        text(meta, margin, top + 74, metaSize, CREAM, {
            tracking: 2,
            opacity: 0.62,
        }) +
        text(facts.dateLong, width - margin, top + 74, metaSize, CREAM, {
            anchor: 'end',
            tracking: 2,
            opacity: 0.62,
        })
    );
}

/** A race replaces the masthead with a full-width rarity band. */
function raceBand(
    facts: PrintFacts,
    width: number,
    margin: number,
    bandTop: number,
    rarity: string,
): string {
    const ink = readableInk(rarity);
    const nameSize = 34;
    const nameFont = { weight: 700, tracking: 5 };
    const distance = facts.raceDistance ?? '';
    const room =
        width - 2 * margin - measure(distance, nameSize, { weight: 700 }) - 40;

    return (
        rect(0, bandTop, width, 104, { fill: rarity }) +
        text(
            truncate(facts.raceName ?? '', nameSize, room, nameFont),
            margin,
            bandTop + 68,
            nameSize,
            ink,
            nameFont,
        ) +
        text(distance, width - margin, bandTop + 68, nameSize, ink, {
            weight: 700,
            anchor: 'end',
        }) +
        text(`BIB ${facts.bib}`, margin, bandTop + 150, 24, INK_ON_SKY, {
            tracking: 3,
        })
    );
}

function route(
    facts: PrintFacts,
    x: number,
    y: number,
    width: number,
    height: number,
    rarity: string,
    ground: string,
): string | null {
    const projected = tracePoints(facts.polyline, width, height);
    if (projected === null) return null;

    const points: Point[] = projected.map(([px, py]) => [px + x, py + y]);
    const closed = isClosedLoop(points, width, height);
    const heavy = facts.form === 'long';
    const colour =
        facts.form === 'pr' ? CITRUS : facts.form === 'race' ? rarity : CREAM;

    let svg = path(polylinePath(points, closed), {
        stroke: colour,
        strokeWidth: heavy ? 16 : 11,
        strokeOpacity: heavy ? 0.5 : facts.form === 'easy' ? 0.2 : 0.38,
    });

    const first = points[0];
    svg += circle(first[0], first[1], 15, { fill: HORIZON });
    if (!closed) {
        const last = points[points.length - 1];
        svg += circle(last[0], last[1], 15, { fill: rarity });
    }
    if (heavy) {
        for (const [mx, my] of along(points, 3)) {
            svg += circle(mx, my, 9, {
                fill: ground,
                stroke: CREAM,
                strokeWidth: 4,
                strokeOpacity: 0.6,
            });
        }
    }

    return svg;
}

/** No GPS: the map area becomes a typographic belt. */
function noSignalBelt(
    width: number,
    margin: number,
    top: number,
    height: number,
    story: boolean,
): string {
    const rows = story ? 11 : 6;
    const gap = (height * (story ? 1 : 0.78)) / rows;
    let svg = '';

    for (let i = 0; i < rows; i++) {
        const y = top + 40 + i * gap;
        svg += text(
            'NO GPS · NO ROUTE · NO GPS · NO ROUTE · NO GPS',
            margin - 20,
            y,
            30,
            CREAM,
            { tracking: 2, opacity: i % 2 === 0 ? 0.13 : 0.07 },
        );
        svg += line(margin - 20, y + 14, width - margin + 20, y + 14, CREAM, {
            width: 1,
            opacity: 0.08,
        });
    }

    return svg;
}

/**
 * One enormous figure: distance normally, finish time on a race or a PR. The
 * figure is fitted to the room the unit mark leaves it — measured, not stepped
 * down a ladder, since the browser can ask Fraunces how wide it sets.
 */
function hero(
    facts: PrintFacts,
    width: number,
    margin: number,
    story: boolean,
): string {
    const heroIsTime = facts.form === 'race' || facts.form === 'pr';
    const value = heroIsTime ? facts.time : facts.km;
    const base = story
        ? heroIsTime
            ? 268
            : facts.form === 'long'
              ? 320
              : 400
        : heroIsTime
          ? 160
          : 232;
    const unitSize = story ? 62 : 44;
    const unitRoom = heroIsTime
        ? 0
        : measure('KM', unitSize, { weight: 600 }) + 40;
    const heroFont = { family: DISPLAY, weight: 600, italic: true };
    const size = fitSize(value, base, width - 2 * margin - unitRoom, heroFont);
    const baseline = story
        ? facts.form === 'pr'
            ? 1300
            : facts.form === 'long'
              ? 1390
              : 1370
        : 742;

    let svg = text(
        facts.kind,
        margin,
        baseline - base * 0.82,
        story ? 30 : 24,
        facts.form === 'pr' ? CITRUS : HORIZON,
        { weight: 600, tracking: 7 },
    );

    if (facts.level >= 4) {
        svg += text(value, margin + 10, baseline + 8, size, facts.rarityHex, {
            ...heroFont,
            opacity: 0.55,
        });
    }
    svg += text(value, margin, baseline, size, CREAM, heroFont);

    if (!heroIsTime) {
        svg += text('KM', width - margin, baseline, unitSize, CREAM, {
            weight: 600,
            anchor: 'end',
            opacity: 0.55,
        });
    }

    return svg;
}

function splits(
    facts: PrintFacts,
    width: number,
    margin: number,
    top: number,
): string {
    let y = top + 30;
    let svg = text('SPLITS', width - margin, y, 22, INK_ON_SKY, {
        anchor: 'end',
        tracking: 5,
    });

    for (const [label, value] of facts.splits) {
        y += 44;
        svg += text(`${label}  ${value}`, width - margin, y, 30, CREAM, {
            anchor: 'end',
        });
    }

    return svg;
}

function statBaseline(
    facts: PrintFacts,
    width: number,
    margin: number,
    bottom: number,
    story: boolean,
): string {
    const cells = statCells(facts);
    if (facts.form === 'race' || facts.form === 'pr') {
        cells[0] = ['DISTANCE', `${facts.km} km`];
    }

    const filled = filledCells(cells);
    if (filled.length === 0) return '';

    const top = bottom - (story ? 190 : 176);
    let svg = line(margin, top, width - margin, top, CREAM, {
        width: 2,
        opacity: 0.22,
    });
    const cellWidth = (width - 2 * margin) / filled.length;

    filled.forEach(([label, value], i) => {
        const x = margin + i * cellWidth;
        if (i > 0) {
            svg += line(
                x - 24,
                top + 18,
                x - 24,
                top + (story ? 150 : 120),
                CREAM,
                { width: 1, opacity: 0.16 },
            );
        }
        svg += text(label, x, top + (story ? 56 : 46), story ? 22 : 19, CREAM, {
            tracking: 4,
            opacity: 0.55,
        });
        svg += text(
            value,
            x,
            top + (story ? 122 : 104),
            story ? 54 : 42,
            CREAM,
            { weight: 600 },
        );
    });

    return svg;
}

function footer(
    facts: PrintFacts,
    width: number,
    margin: number,
    bottom: number,
    story: boolean,
    rarity: string,
    isRace: boolean,
): string {
    const y = bottom - (story ? 10 : 6);
    const size = story ? 22 : 18;
    let svg = '';

    if (facts.badges.length === 0) {
        svg += text(facts.serial, margin, y, size, CREAM, {
            tracking: 3,
            opacity: 0.35,
        });
    } else {
        let x = margin;
        const height = story ? 54 : 46;
        for (const badge of facts.badges) {
            const label = badge.toUpperCase();
            const chip = measure(label, size, { tracking: 2 }) + 44;
            svg += rect(x, y - (story ? 46 : 38), chip, height, {
                radius: height / 2,
                stroke: rarity,
                strokeWidth: 2,
            });
            svg += text(label, x + 22, y - (story ? 10 : 8), size, rarity, {
                tracking: 2,
            });
            x += chip + 18;
        }
    }

    return (
        svg +
        (isRace
            ? wordmark(width - margin, y, story ? 42 : 34, HORIZON, 'end')
            : text(facts.dateShort, width - margin, y, size, CREAM, {
                  anchor: 'end',
                  tracking: 3,
                  opacity: 0.35,
              }))
    );
}
