import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import AiOutageBanner from './AiOutageBanner';
import { setMockPage } from '@/test/setup';

const base = { auth: { user: null }, flash: {}, demoLoginEnabled: false } as const;

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
            screen.getByText('Temari lagi istirahat sebentar. Narasinya nggak ilang kok, nyusul otomatis pas dia balik.'),
        ).toBeInTheDocument();
    });
});
