import { render, screen } from '@testing-library/react';
import { createElement } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { SCROLL_FADE_MASK, useScrollFade } from './useScrollFade';

/**
 * jsdom reports every layout box as zero, so the geometry a rail would have in
 * a browser is stubbed on the prototype for the element under test.
 */
function stubGeometry(scrollWidth: number, clientWidth: number) {
    vi.spyOn(HTMLElement.prototype, 'scrollWidth', 'get').mockReturnValue(
        scrollWidth,
    );
    vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(
        clientWidth,
    );
}

function Rail({ scrollLeft = 0 }: Readonly<{ scrollLeft?: number }>) {
    const { ref, faded } = useScrollFade<HTMLDivElement>();

    return createElement('div', {
        ref: (el: HTMLDivElement | null) => {
            if (el) {
                el.scrollLeft = scrollLeft;
            }
            ref(el);
        },
        'data-testid': 'rail',
        'data-faded': faded,
    });
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('useScrollFade', () => {
    it('fades a rail whose content runs past its right edge', () => {
        stubGeometry(612, 284);
        render(createElement(Rail));

        expect(screen.getByTestId('rail')).toHaveAttribute(
            'data-faded',
            'true',
        );
    });

    it('does not fade a rail whose content already fits', () => {
        stubGeometry(978, 978);
        render(createElement(Rail));

        expect(screen.getByTestId('rail')).toHaveAttribute(
            'data-faded',
            'false',
        );
    });

    it('drops the fade once the rail is scrolled to its end', () => {
        stubGeometry(612, 284);
        render(createElement(Rail, { scrollLeft: 328 }));

        expect(screen.getByTestId('rail')).toHaveAttribute(
            'data-faded',
            'false',
        );
    });

    it('masks only the trailing edge, leaving the rest opaque', () => {
        expect(SCROLL_FADE_MASK).toContain('to right');
        expect(SCROLL_FADE_MASK).toContain('transparent');
    });
});
