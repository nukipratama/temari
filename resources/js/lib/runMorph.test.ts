import { afterEach, describe, expect, it } from 'vitest';

import { morphRunCard } from './runMorph';

interface FakeTransition {
    transition: ViewTransition;
    swap: () => void;
    failSwap: () => void;
    finish: () => void;
}

function fakeTransition(): FakeTransition {
    let swap!: () => void;
    let failSwap!: () => void;
    let finish!: () => void;
    const updateCallbackDone = new Promise<void>((resolve, reject) => {
        swap = resolve;
        failSwap = () => reject(new Error('swap failed'));
    });
    const finished = new Promise<void>((resolve, reject) => {
        finish = resolve;
        updateCallbackDone.catch(reject);
    });

    return {
        transition: {
            updateCallbackDone,
            finished,
            ready: Promise.resolve(),
            skipTransition: () => undefined,
        } as unknown as ViewTransition,
        swap,
        failSwap,
        finish,
    };
}

function mount(activityId: number): HTMLElement {
    const element = document.createElement('div');
    element.dataset.runMorph = String(activityId);
    document.body.append(element);

    return element;
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('morphRunCard', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('names the tapped row as soon as the transition starts', () => {
        const row = mount(42);
        const { transition } = fakeTransition();

        morphRunCard(42)(transition);

        expect(row.style.getPropertyValue('view-transition-name')).toBe(
            'run-42',
        );
        expect(row.style.getPropertyValue('view-transition-class')).toBe(
            'run-morph',
        );
    });

    it('names the hero the swap rendered, under the same name', async () => {
        const row = mount(42);
        const fake = fakeTransition();
        morphRunCard(42)(fake.transition);

        row.remove();
        const hero = mount(42);
        fake.swap();
        await settle();

        expect(hero.style.getPropertyValue('view-transition-name')).toBe(
            'run-42',
        );
    });

    it('leaves every other run unnamed', () => {
        const other = mount(7);
        mount(42);

        morphRunCard(42)(fakeTransition().transition);

        expect(other.style.getPropertyValue('view-transition-name')).toBe('');
    });

    it('clears both names once the transition finishes', async () => {
        const row = mount(42);
        const fake = fakeTransition();
        morphRunCard(42)(fake.transition);

        row.remove();
        const hero = mount(42);
        fake.swap();
        await settle();
        fake.finish();
        await settle();

        expect(row.getAttribute('style') ?? '').toBe('');
        expect(hero.getAttribute('style') ?? '').toBe('');
    });

    it('clears the row name when the swap fails', async () => {
        const row = mount(42);
        const fake = fakeTransition();
        morphRunCard(42)(fake.transition);

        fake.failSwap();
        await settle();

        expect(row.getAttribute('style') ?? '').toBe('');
    });

    it('does nothing when neither end is on the page', () => {
        expect(() =>
            morphRunCard(42)(fakeTransition().transition),
        ).not.toThrow();
    });
});
