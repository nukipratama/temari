import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import AnalysisStatus from './AnalysisStatus';
import type { AnalysisPayload } from '@/types/inertia';

function payload(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: null,
        status: 'pending',
        content: null,
        type: 'briefing_headline',
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: null,
        ...overrides,
    };
}

describe('AnalysisStatus', () => {
    it('renders done content with the reanalyze button by default', () => {
        render(<AnalysisStatus analysis={payload({ status: 'done', content: 'Halo Temari' })} />);
        expect(screen.getByText('Halo Temari')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Analisis ulang/ })).toBeInTheDocument();
    });

    it('hides the reanalyze button when allowReanalyze is false', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'Halo' })}
                allowReanalyze={false}
            />,
        );
        expect(screen.queryByRole('button', { name: /Analisis ulang/ })).not.toBeInTheDocument();
    });

    it('uses renderContent for custom rendering when provided', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'raw' })}
                renderContent={(content) => <span data-testid="custom">[{content}]</span>}
            />,
        );
        expect(screen.getByTestId('custom').textContent).toBe('[raw]');
    });

    it('renders the queued spinner status', () => {
        render(<AnalysisStatus analysis={payload({ status: 'queued' })} />);
        expect(screen.getByRole('status')).toHaveTextContent(/Lagi dipikirin Temari/);
    });

    it('renders the processing spinner status', () => {
        render(<AnalysisStatus analysis={payload({ status: 'processing' })} />);
        expect(screen.getByRole('status')).toHaveTextContent(/Lagi dipikirin Temari/);
    });

    it('renders the failed retry button', () => {
        render(<AnalysisStatus analysis={payload({ status: 'failed' })} />);
        expect(screen.getByRole('button', { name: /Coba lagi/ })).toBeInTheDocument();
    });

    it('renders the empty-state trigger when status is pending with no content', () => {
        render(<AnalysisStatus analysis={payload({ status: 'pending' })} />);
        expect(screen.getByRole('button', { name: /Analisis sekarang/ })).toBeInTheDocument();
    });

    it('respects the sm size class on done content', () => {
        const { container } = render(
            <AnalysisStatus analysis={payload({ status: 'done', content: 'mini' })} size="sm" />,
        );
        const body = container.querySelector('div.text-sm');
        expect(body).not.toBeNull();
    });
});
