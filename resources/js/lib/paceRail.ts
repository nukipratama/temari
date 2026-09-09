export interface PaceRailLabel {
    /** Where the marker's dot sits on the rail, 0–100. */
    position: number;
    below: boolean;
    /** Room the label needs, as a percentage of the rail's width. */
    width: number;
}

export interface PaceRailPlacement extends PaceRailLabel {
    /** The label's left edge, 0–100. */
    left: number;
}

function spread(side: PaceRailPlacement[]): void {
    for (let i = 1; i < side.length; i++) {
        side[i].left = Math.max(
            side[i].left,
            side[i - 1].left + side[i - 1].width,
        );
    }

    for (let i = side.length - 1; i >= 0; i--) {
        const ceiling =
            i === side.length - 1
                ? 100 - side[i].width
                : side[i + 1].left - side[i].width;
        side[i].left = Math.min(side[i].left, ceiling);
    }

    side[0].left = Math.max(side[0].left, 0);
}

/**
 * Places each label centred on its own dot, then pushes same-side neighbours
 * apart until none overlaps, keeping the run inside the rail. Dots do not move;
 * only labels give way.
 */
export function layoutPaceLabels(
    labels: readonly PaceRailLabel[],
): PaceRailPlacement[] {
    const placed: PaceRailPlacement[] = labels.map((label) => ({
        ...label,
        left: Math.min(
            Math.max(label.position - label.width / 2, 0),
            100 - label.width,
        ),
    }));

    for (const below of [false, true]) {
        const side = placed
            .filter((label) => label.below === below)
            .sort((a, b) => a.position - b.position);

        if (side.length > 0) {
            spread(side);
        }
    }

    return placed;
}
