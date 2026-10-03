/**
 * Names a History run row and the run page's hero header for the length of one
 * Inertia page transition, so the row morphs into the header.
 */
export function morphRunCard(
    activityId: number,
): (transition: ViewTransition) => void {
    return (transition) => {
        const row = nameMorphTarget(activityId);
        let hero: HTMLElement | null = null;

        transition.updateCallbackDone.then(
            () => {
                hero = nameMorphTarget(activityId);
            },
            () => undefined,
        );

        const clear = () => {
            clearMorphName(row);
            clearMorphName(hero);
        };
        transition.finished.then(clear, clear);
    };
}

function nameMorphTarget(activityId: number): HTMLElement | null {
    const element = document.querySelector<HTMLElement>(
        `[data-run-morph="${activityId}"]`,
    );
    element?.style.setProperty('view-transition-name', `run-${activityId}`);
    element?.style.setProperty('view-transition-class', 'run-morph');

    return element;
}

function clearMorphName(element: HTMLElement | null): void {
    element?.style.removeProperty('view-transition-name');
    element?.style.removeProperty('view-transition-class');
}
