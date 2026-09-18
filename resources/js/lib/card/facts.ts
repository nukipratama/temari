import type { Point } from '@/lib/card/svg';
import type {
    CardFactsPayload,
    CardOptions,
    Cell,
    RunForm,
} from '@/lib/card/types';
import type { Rarity } from '@/types/inertia';

import { RARITY_INK_HEX } from '@/lib/card/palette';
import { projectPolyline } from '@/lib/route';
import { RARITY_HEX, RARITY_ORDER, RARITY_SYMBOL } from '@/lib/runcard';

/**
 * The run's facts as one print reads them: the server payload with the
 * athlete's chip toggles applied, plus the few values every style derives from
 * it. One resolution for all three styles, so "form follows the run" and the
 * numbers themselves cannot drift between a broadsheet, a ticket and a survey
 * plate.
 */
export interface PrintFacts {
    form: RunForm;
    rarity: Rarity;
    rarityHex: string;
    rarityInk: string;
    raritySymbol: string;
    rarityLabel: string;
    /** 1 at common through 5 at legendary, the ladder every style escalates on. */
    level: number;
    /** Thread-band accent density, matching `Rarity::bandCount()`. */
    bandCount: number;
    kind: string;
    km: string;
    distanceKm: number;
    time: string;
    pace: string;
    heartRate: string | null;
    elevation: string | null;
    place: string;
    placeShort: string;
    weather: string | null;
    dateLong: string;
    dateShort: string;
    clock: string;
    badges: string[];
    serial: string;
    /** The bib slot's number: the print's own serial, since no bib is recorded. */
    bib: string;
    polyline: string | null;
    raceName: string | null;
    raceDistance: string | null;
    splits: Cell[];
    paceProfile: number[];
}

const RARITY_LABELS: Record<Rarity, string> = {
    common: 'Common',
    uncommon: 'Uncommon',
    rare: 'Rare',
    epic: 'Epic',
    legendary: 'Legendary',
};

/**
 * Whether a run has the fact behind a chip at all. A chip for a fact the run
 * lacks is hidden rather than disabled, so this decides what the popup draws.
 */
export function hasFact(
    facts: CardFactsPayload,
    key: keyof CardOptions,
): boolean {
    switch (key) {
        case 'hr':
            return facts.heart_rate !== null;
        case 'elevation':
            return facts.elevation !== null;
        case 'weather':
            return facts.weather !== null;
        case 'badges':
            return facts.badges.length > 0;
    }
}

export function printFacts(
    payload: CardFactsPayload,
    options: CardOptions,
): PrintFacts {
    const rank = RARITY_ORDER.indexOf(payload.rarity);

    return {
        form: payload.form,
        rarity: payload.rarity,
        rarityHex: RARITY_HEX[payload.rarity],
        rarityInk: RARITY_INK_HEX[payload.rarity],
        raritySymbol: RARITY_SYMBOL[payload.rarity],
        rarityLabel: RARITY_LABELS[payload.rarity],
        level: rank + 1,
        bandCount: rank + 1,
        kind: payload.kind,
        km: payload.km,
        distanceKm: payload.distance_km,
        time: payload.time,
        pace: payload.pace,
        heartRate: options.hr ? payload.heart_rate : null,
        elevation: options.elevation ? payload.elevation : null,
        place: payload.place,
        placeShort: payload.place_short,
        weather: options.weather ? payload.weather : null,
        dateLong: payload.date_long,
        dateShort: payload.date_short,
        clock: payload.clock,
        badges: options.badges ? payload.badges : [],
        serial: payload.serial,
        bib: payload.serial.slice(-4),
        polyline: payload.polyline,
        raceName: payload.race_name,
        raceDistance: payload.race_distance,
        splits: payload.splits,
        paceProfile: payload.pace_profile,
    };
}

/**
 * TIME, PACE and one flex cell, in the order the broadsheet and the ticket
 * rule them. A long run always spends the flex cell on elevation; every other
 * form prefers heart rate and falls back to elevation, then to the start clock,
 * so the row is never short a cell.
 */
export function statCells(facts: PrintFacts): Cell[] {
    let flex: Cell;
    if (facts.form === 'long' && facts.elevation !== null) {
        flex = ['ELEV', `${facts.elevation} m`];
    } else if (facts.heartRate !== null) {
        flex = ['AVG HR', facts.heartRate];
    } else if (facts.elevation !== null) {
        flex = ['ELEV', `${facts.elevation} m`];
    } else {
        flex = ['START', facts.clock];
    }

    return [['TIME', facts.time], ['PACE', `${facts.pace}/km`], flex];
}

/**
 * The run's trace fitted to a box, or null when there is nothing drawable — a
 * polyline that decodes to fewer than two points is as route-less as no
 * polyline at all, so the drawing itself decides which branch a style runs.
 */
export function tracePoints(
    polyline: string | null,
    width: number,
    height: number,
): Point[] | null {
    // The server projector decoded every point; the cap here is high enough to
    // keep a 40 km trace whole rather than to smooth it.
    return projectPolyline(polyline, width, height, 0, 4000)?.points ?? null;
}
