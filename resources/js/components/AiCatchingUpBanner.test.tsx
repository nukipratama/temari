import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import AiCatchingUpBanner from './AiCatchingUpBanner';
import { setMockPage } from '@/test/setup';

const base = { auth: { user: null }, flash: {}, demoLoginEnabled: false } as const;

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
            screen.getByText('Masih diproses di belakang layar. Balik lagi nanti ya, narasinya nyusul otomatis.'),
        ).toBeInTheDocument();
    });
});
