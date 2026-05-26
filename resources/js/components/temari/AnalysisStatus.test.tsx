import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
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
        expect(screen.getByRole('button', { name: /Baca ulang/ })).toBeInTheDocument();
    });

    it('hides the reanalyze button when allowReanalyze is false', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'Halo' })}
                allowReanalyze={false}
            />,
        );
        expect(screen.queryByRole('button', { name: /Baca ulang/ })).not.toBeInTheDocument();
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
        expect(screen.getByRole('button', { name: /Minta Temari bacain/ })).toBeInTheDocument();
    });

    it('shows "Dibuat X lalu" hint when generated_at is present on done content', () => {
        const ts = new Date(Date.now() - 5 * 60 * 1000).toISOString();
        render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'ok', generated_at: ts })}
            />,
        );
        expect(screen.getByText(/Dibuat 5 menit lalu/)).toBeInTheDocument();
    });

    it('appends "(percobaan N)" when attempts > 1 on queued/processing', () => {
        render(<AnalysisStatus analysis={payload({ status: 'processing', attempts: 3 })} />);
        expect(screen.getByRole('status').textContent).toMatch(/percobaan 3/);
    });

    it('disables Analisis ulang and shows countdown when retry_after_seconds > 0', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'x', retry_after_seconds: 125 })}
            />,
        );
        const button = screen.getByRole('button', { name: /Tunggu 2:05/ });
        expect(button).toBeDisabled();
    });

    it('decrements the cooldown countdown each second', async () => {
        vi.useFakeTimers();
        try {
            render(
                <AnalysisStatus
                    analysis={payload({ status: 'done', content: 'x', retry_after_seconds: 4 })}
                />,
            );

            expect(screen.getByRole('button', { name: /Tunggu 0:04/ })).toBeInTheDocument();

            await act(async () => {
                vi.advanceTimersByTime(1000);
            });
            expect(screen.getByRole('button', { name: /Tunggu 0:03/ })).toBeInTheDocument();

            await act(async () => {
                vi.advanceTimersByTime(4000);
            });
            // Countdown reaches 0 → button re-enables to "Baca ulang".
            expect(screen.getByRole('button', { name: /^Baca ulang$/ })).not.toBeDisabled();
        } finally {
            vi.useRealTimers();
        }
    });

    it('renders the rate-limited note after a 429 response', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 429, json: async () => ({}) });
        const original = globalThis.fetch;
        globalThis.fetch = fetchMock as unknown as typeof fetch;
        document.head.innerHTML = '<meta name="csrf-token" content="t" />';

        try {
            render(
                <AnalysisStatus
                    analysis={payload({ status: 'done', content: 'x' })}
                />,
            );

            await act(async () => {
                fireEvent.click(screen.getByRole('button', { name: /Baca ulang/ }));
            });

            await waitFor(() => {
                expect(screen.getByText(/Pelan-pelan, Temari kewalahan/)).toBeInTheDocument();
            });
        } finally {
            globalThis.fetch = original;
            document.head.innerHTML = '';
        }
    });

    it('respects the sm size class on done content', () => {
        const { container } = render(
            <AnalysisStatus analysis={payload({ status: 'done', content: 'mini' })} size="sm" />,
        );
        const body = container.querySelector('div.text-sm');
        expect(body).not.toBeNull();
    });
});
