import { act } from '@testing-library/react';

/** Lets queued history traversals, and any Back their popstate handlers trigger, run before assertions. */
export async function settle() {
    await act(async () => {
        for (let hop = 0; hop < 8; hop++) {
            await new Promise((resolve) => setTimeout(resolve, 0));
        }
    });
}

/** Simulates the browser/Android Back gesture. */
export async function back() {
    window.history.back();
    await settle();
}
