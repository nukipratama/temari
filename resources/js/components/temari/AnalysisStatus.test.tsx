import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import AnalysisStatus from './AnalysisStatus';
import { setMockPage } from '@/test/setup';
import type { AnalysisPayload } from '@/types/inertia';

const BADGE_TEXT = /dihitung dengan zona lama/;
const OLD_TS = '2026-01-01T00:00:00+00:00';
const NEW_TS = '2026-02-01T00:00:00+00:00';

function payload(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: null,
        status: 'pending',
        content: null,
        type: 'briefing_headline',
        is_zone_dependent: false,
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

    it('renders a skeleton placeholder when queued', () => {
        const { container } = render(<AnalysisStatus analysis={payload({ status: 'queued' })} />);
        expect(screen.getByRole('status')).toBeInTheDocument();
        expect(container.querySelector('.animate-pulse')).not.toBeNull();
    });

    it('renders a skeleton placeholder when processing', () => {
        const { container } = render(<AnalysisStatus analysis={payload({ status: 'processing' })} />);
        expect(screen.getByRole('status')).toBeInTheDocument();
        expect(container.querySelector('.animate-pulse')).not.toBeNull();
    });

    it('renders the failed retry button', () => {
        render(<AnalysisStatus analysis={payload({ status: 'failed' })} />);
        expect(screen.getByRole('button', { name: /Coba lagi/ })).toBeInTheDocument();
    });

    it('renders the empty-state trigger when status is pending with no content', () => {
        render(<AnalysisStatus analysis={payload({ status: 'pending' })} />);
        expect(screen.getByRole('button', { name: /Minta Temari bacain/ })).toBeInTheDocument();
    });

    it('shows the "belum tersedia" note and no trigger when awaitingSchedule (current week)', () => {
        render(<AnalysisStatus analysis={payload({ status: 'pending' })} awaitingSchedule />);
        expect(screen.getByText(/Rekap minggu ini belum tersedia/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Minta Temari bacain/ })).not.toBeInTheDocument();
    });

    it('uses a custom awaitingScheduleLabel when provided (e.g. the current month)', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'pending' })}
                awaitingSchedule
                awaitingScheduleLabel="Rekap bulan ini belum tersedia."
            />,
        );
        expect(screen.getByText(/Rekap bulan ini belum tersedia/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Minta Temari bacain/ })).not.toBeInTheDocument();
    });

    it('suppresses the reanalyze button on done content when awaitingSchedule', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'Halo' })}
                awaitingSchedule
            />,
        );
        expect(screen.getByText('Halo')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Baca ulang/ })).not.toBeInTheDocument();
    });

    it('shows "Dibuat X lalu" hint when generated_at is present on done content', () => {
        vi.useFakeTimers();
        const now = new Date('2026-07-07T12:00:00Z');
        vi.setSystemTime(now);
        const ts = new Date(now.getTime() - 5 * 60 * 1000).toISOString();
        render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'ok', generated_at: ts })}
            />,
        );
        expect(screen.getByText(/Dibuat 5 menit lalu/)).toBeInTheDocument();
        vi.useRealTimers();
    });

    it('shows attempt count when attempts > 1 on queued/processing', () => {
        render(<AnalysisStatus analysis={payload({ status: 'processing', attempts: 3 })} />);
        expect(screen.getByText(/Percobaan 3/)).toBeInTheDocument();
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

    describe('chained behavior', () => {
        it('shows "Baca ulang" on a done block when it is the chain head', () => {
            render(
                <AnalysisStatus
                    analysis={payload({ status: 'done', content: 'recap', type: 'weekly_recap' })}
                    chained
                    isChainHead
                />,
            );
            expect(screen.getByRole('button', { name: /Baca ulang/ })).toBeInTheDocument();
        });

        it('hides "Baca ulang" on a done block that is not the chain head', () => {
            render(
                <AnalysisStatus
                    analysis={payload({ status: 'done', content: 'recap', type: 'weekly_recap' })}
                    chained
                    isChainHead={false}
                />,
            );
            expect(screen.getByText('recap')).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /Baca ulang/ })).not.toBeInTheDocument();
        });

        it('still shows "Coba lagi" on a failed chained block (resumes the chain) even when not head', () => {
            render(
                <AnalysisStatus
                    analysis={payload({ status: 'failed', type: 'weekly_recap' })}
                    chained
                    isChainHead={false}
                />,
            );
            expect(screen.getByRole('button', { name: /Coba lagi/ })).toBeInTheDocument();
        });

        it('still shows the empty-state trigger on a pending chained block even when not head', () => {
            render(
                <AnalysisStatus
                    analysis={payload({ status: 'pending', type: 'weekly_recap' })}
                    chained
                    isChainHead={false}
                />,
            );
            expect(screen.getByRole('button', { name: /Minta Temari bacain/ })).toBeInTheDocument();
        });

        it('standalone (non-chained) done block keeps "Baca ulang" regardless of isChainHead', () => {
            render(
                <AnalysisStatus
                    analysis={payload({ status: 'done', content: 'x' })}
                    isChainHead={false}
                />,
            );
            expect(screen.getByRole('button', { name: /Baca ulang/ })).toBeInTheDocument();
        });
    });

    describe('stale-zones badge', () => {
        it('shows on a zone-dependent block generated before the zones changed', () => {
            setMockPage({ hrZonesChangedAt: NEW_TS });
            render(
                <AnalysisStatus
                    analysis={payload({
                        status: 'done',
                        content: 'zona',
                        is_zone_dependent: true,
                        generated_at: OLD_TS,
                    })}
                />,
            );
            expect(screen.getByText(BADGE_TEXT)).toBeInTheDocument();
        });

        it('hides when the block was generated after the zones changed', () => {
            setMockPage({ hrZonesChangedAt: OLD_TS });
            render(
                <AnalysisStatus
                    analysis={payload({
                        status: 'done',
                        content: 'zona',
                        is_zone_dependent: true,
                        generated_at: NEW_TS,
                    })}
                />,
            );
            expect(screen.queryByText(BADGE_TEXT)).not.toBeInTheDocument();
        });

        it('hides for zone-agnostic analysis types even when stale', () => {
            setMockPage({ hrZonesChangedAt: NEW_TS });
            render(
                <AnalysisStatus
                    analysis={payload({
                        status: 'done',
                        content: 'pidato',
                        is_zone_dependent: false,
                        generated_at: OLD_TS,
                    })}
                />,
            );
            expect(screen.queryByText(BADGE_TEXT)).not.toBeInTheDocument();
        });

        it('hides when hrZonesChangedAt is null', () => {
            setMockPage({ hrZonesChangedAt: null });
            render(
                <AnalysisStatus
                    analysis={payload({
                        status: 'done',
                        content: 'zona',
                        is_zone_dependent: true,
                        generated_at: OLD_TS,
                    })}
                />,
            );
            expect(screen.queryByText(BADGE_TEXT)).not.toBeInTheDocument();
        });

        it('shows for any zone-dependent block regardless of its type', () => {
            setMockPage({ hrZonesChangedAt: NEW_TS });
            render(
                <AnalysisStatus
                    analysis={payload({
                        status: 'done',
                        content: 'x',
                        type: 'weekly_recap',
                        is_zone_dependent: true,
                        generated_at: OLD_TS,
                    })}
                />,
            );
            expect(screen.getByText(BADGE_TEXT)).toBeInTheDocument();
        });
    });
});
