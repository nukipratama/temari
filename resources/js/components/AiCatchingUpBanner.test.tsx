import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import AiCatchingUpBanner from './AiCatchingUpBanner';

const base = {
    auth: { user: null },
    flash: {},
    demoLoginEnabled: false,
} as const;

describe('AiCatchingUpBanner', () => {
    it('renders nothing when narration is caught up', () => {
        setMockPage({ ...base, aiCatchingUp: false });
        const { container } = render(<AiCatchingUpBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when the prop is absent', () => {
        setMockPage({ ...base });
        const { container } = render(<AiCatchingUpBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('shows a soft catching-up message when narration is still in progress', () => {
        setMockPage({ ...base, aiCatchingUp: true });
        render(<AiCatchingUpBanner />);
        expect(
            screen.getByText(
                "temari's still reading through your runs. check back in a bit, your notes will catch up on their own.",
            ),
        ).toBeInTheDocument();
    });
});

describe('AiCatchingUpBanner column cap', () => {
    it('caps the column only from 900px up', () => {
        setMockPage({ ...base, aiCatchingUp: true });
        const { container } = render(<AiCatchingUpBanner />);
        const box = container.querySelector('[class*="max-w-column"]')!;
        expect(box.classList.contains('max-w-column')).toBe(false);
        expect(box.classList.contains('min-[900px]:max-w-column')).toBe(true);
    });
});
