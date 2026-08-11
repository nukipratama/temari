import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import AiOutageBanner from './AiOutageBanner';

const base = {
    auth: { user: null },
    flash: {},
    demoLoginEnabled: false,
} as const;

describe('AiOutageBanner', () => {
    it('renders nothing when the pipeline is healthy', () => {
        setMockPage({ ...base, aiPaused: false });
        const { container } = render(<AiOutageBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing when the prop is absent', () => {
        setMockPage({ ...base });
        const { container } = render(<AiOutageBanner />);
        expect(container.firstChild).toBeNull();
    });

    it('shows a soft resting message when paused', () => {
        setMockPage({ ...base, aiPaused: true });
        render(<AiOutageBanner />);
        expect(
            screen.getByText(
                "Temari's resting for a bit. The narration isn't gone, it'll catch up automatically once generation's back.",
            ),
        ).toBeInTheDocument();
    });
});
