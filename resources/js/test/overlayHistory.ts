import { act } from '@testing-library/react';

/** Lets a pending popstate/Inertia handler run before assertions. */
export async function settle() {
    await act(() => new Promise((resolve) => setTimeout(resolve, 20)));
}

/** Simulates the browser/Android Back gesture. */
export async function back() {
    window.history.back();
    await settle();
}
