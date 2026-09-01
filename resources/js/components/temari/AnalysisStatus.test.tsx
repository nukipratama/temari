import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { AnalysisPayload } from '@/types/inertia';

import { setMockPage } from '@/test/setup';

import AnalysisStatus from './AnalysisStatus';

const BADGE_TEXT = /calculated with old zones/;
const OLD_TS = '2026-01-01T00:00:00+00:00';
const NEW_TS = '2026-02-01T00:00:00+00:00';

function payload(overrides: Partial<AnalysisPayload> = {}): AnalysisPayload {
    return {
        id: null,
        status: 'pending',
        content: null,
        type: 'briefing_mascot_voice',
        is_zone_dependent: false,
        subject_type: 'briefing_user_day',
        subject_id: 1,
        discriminator: null,
        ...overrides,
    };
}

describe('AnalysisStatus', () => {
    it('renders done content with the reanalyze button by default', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'Halo Temari' })}
            />,
        );
        expect(screen.getByText('Halo Temari')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /reread/ }),
        ).toBeInTheDocument();
    });

    it('hides the reanalyze button when allowReanalyze is false', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'Halo' })}
                allowReanalyze={false}
            />,
        );
        expect(
            screen.queryByRole('button', { name: /reread/ }),
        ).not.toBeInTheDocument();
    });

    it('uses renderContent for custom rendering when provided', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'raw' })}
                renderContent={(content) => (
                    <span data-testid="custom">[{content}]</span>
                )}
            />,
        );
        expect(screen.getByTestId('custom').textContent).toBe('[raw]');
    });

    it('renders a skeleton placeholder when queued', () => {
        const { container } = render(
            <AnalysisStatus analysis={payload({ status: 'queued' })} />,
        );
        expect(screen.getByRole('status')).toBeInTheDocument();
        expect(
            container.querySelector('.skeleton, .skeleton-on-sky'),
        ).not.toBeNull();
    });

    it('renders a skeleton placeholder when processing', () => {
        const { container } = render(
            <AnalysisStatus analysis={payload({ status: 'processing' })} />,
        );
        expect(screen.getByRole('status')).toBeInTheDocument();
        expect(
            container.querySelector('.skeleton, .skeleton-on-sky'),
        ).not.toBeNull();
    });

    it('flips the queued skeleton to a quiet "check back later" state after polling gives up', async () => {
        vi.useFakeTimers();
        try {
            render(
                <AnalysisStatus
                    analysis={payload({ status: 'queued' })}
                    inertiaReloadProps={['briefing']}
                />,
            );
            // The working skeleton shows while polling is live.
            expect(screen.getByRole('status')).toBeInTheDocument();

            // Poll past the 30-attempt cap; the slot retires and notifies.
            await act(async () => {
                vi.advanceTimersByTime(20 * 60 * 1000);
            });

            expect(
                screen.getByText(/still processing, check back in a bit/),
            ).toBeInTheDocument();
            expect(screen.queryByRole('status')).toBeNull();
        } finally {
            vi.useRealTimers();
        }
    });

    it('renders the failed retry button', () => {
        render(<AnalysisStatus analysis={payload({ status: 'failed' })} />);
        expect(
            screen.getByRole('button', { name: /try again/ }),
        ).toBeInTheDocument();
    });

    it('renders nothing when status is pending with no content', () => {
        const { container } = render(
            <AnalysisStatus analysis={payload({ status: 'pending' })} />,
        );
        expect(container).toBeEmptyDOMElement();
    });

    it('shows the "not available yet" note and no trigger when awaitingSchedule (current week)', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'pending' })}
                awaitingSchedule
            />,
        );
        expect(
            screen.getByText(/this week's recap isn't available yet/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Ask Temari to read it/ }),
        ).not.toBeInTheDocument();
    });

    it('uses a custom awaitingScheduleLabel when provided (e.g. the current month)', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'pending' })}
                awaitingSchedule
                awaitingScheduleLabel="This month's recap isn't available yet."
            />,
        );
        expect(
            screen.getByText(/This month's recap isn't available yet/),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Ask Temari to read it/ }),
        ).not.toBeInTheDocument();
    });

    it('suppresses the reanalyze button on done content when awaitingSchedule', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'Halo' })}
                awaitingSchedule
            />,
        );
        expect(screen.getByText('Halo')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /reread/ }),
        ).not.toBeInTheDocument();
    });

    it('shows "Generated X ago" hint when generated_at is present on done content', () => {
        vi.useFakeTimers();
        const now = new Date('2026-07-07T12:00:00Z');
        vi.setSystemTime(now);
        const ts = new Date(now.getTime() - 5 * 60 * 1000).toISOString();
        render(
            <AnalysisStatus
                analysis={payload({
                    status: 'done',
                    content: 'ok',
                    generated_at: ts,
                })}
            />,
        );
        expect(screen.getByText(/generated 5 min ago/)).toBeInTheDocument();
        vi.useRealTimers();
    });

    it('gives the done-state timestamp and "reread" button the on-sky muted tone instead of text-text-3', () => {
        vi.useFakeTimers();
        const now = new Date('2026-07-07T12:00:00Z');
        vi.setSystemTime(now);
        const ts = new Date(now.getTime() - 5 * 60 * 1000).toISOString();
        render(
            <AnalysisStatus
                analysis={payload({
                    status: 'done',
                    content: 'ok',
                    generated_at: ts,
                })}
                onSky
            />,
        );

        expect(screen.getByText(/generated 5 min ago/)).toHaveClass(
            'text-ink-on-sky',
        );
        expect(screen.getByText(/generated 5 min ago/)).not.toHaveClass(
            'text-text-3',
        );
        const button = screen.getByRole('button', { name: /reread/ });
        expect(button).toHaveClass('text-ink-on-sky');
        expect(button).not.toHaveClass('text-text-3');
        vi.useRealTimers();
    });

    it('shows attempt count when attempts > 1 on queued/processing', () => {
        render(
            <AnalysisStatus
                analysis={payload({ status: 'processing', attempts: 3 })}
            />,
        );
        expect(screen.getByText(/Attempt 3/)).toBeInTheDocument();
    });

    it('disables reread and shows countdown when retry_after_seconds > 0', () => {
        render(
            <AnalysisStatus
                analysis={payload({
                    status: 'done',
                    content: 'x',
                    retry_after_seconds: 125,
                })}
            />,
        );
        const button = screen.getByRole('button', { name: /Wait 2:05/ });
        expect(button).toBeDisabled();
    });

    it('decrements the cooldown countdown each second', async () => {
        vi.useFakeTimers();
        try {
            render(
                <AnalysisStatus
                    analysis={payload({
                        status: 'done',
                        content: 'x',
                        retry_after_seconds: 4,
                    })}
                />,
            );

            expect(
                screen.getByRole('button', { name: /Wait 0:04/ }),
            ).toBeInTheDocument();

            await act(async () => {
                vi.advanceTimersByTime(1000);
            });
            expect(
                screen.getByRole('button', { name: /Wait 0:03/ }),
            ).toBeInTheDocument();

            await act(async () => {
                vi.advanceTimersByTime(4000);
            });
            // Countdown reaches 0 → button re-enables to "reread".
            expect(
                screen.getByRole('button', { name: /^reread$/ }),
            ).not.toBeDisabled();
        } finally {
            vi.useRealTimers();
        }
    });

    it('renders the rate-limited note after a 429 response', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: false,
            status: 429,
            json: async () => ({}),
        });
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
                fireEvent.click(screen.getByRole('button', { name: /reread/ }));
            });

            await waitFor(() => {
                expect(
                    screen.getByText(/Easy there, Temari's overwhelmed/),
                ).toBeInTheDocument();
            });
        } finally {
            globalThis.fetch = original;
            document.head.innerHTML = '';
        }
    });

    it('respects the sm size class on done content', () => {
        const { container } = render(
            <AnalysisStatus
                analysis={payload({ status: 'done', content: 'mini' })}
                size="sm"
            />,
        );
        const body = container.querySelector('div.text-sm');
        expect(body).not.toBeNull();
    });

    describe('when AI is globally paused', () => {
        it('hides the "reread" button on done content but keeps the content', () => {
            setMockPage({ aiPaused: true });
            render(
                <AnalysisStatus
                    analysis={payload({ status: 'done', content: 'Halo' })}
                />,
            );
            expect(screen.getByText('Halo')).toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /reread/ }),
            ).not.toBeInTheDocument();
        });

        it('hides the "try again" button on a failed block', () => {
            setMockPage({ aiPaused: true });
            render(<AnalysisStatus analysis={payload({ status: 'failed' })} />);
            expect(
                screen.queryByRole('button', { name: /try again/ }),
            ).not.toBeInTheDocument();
        });

        it('hides the empty-state trigger on a pending block', () => {
            setMockPage({ aiPaused: true });
            render(
                <AnalysisStatus analysis={payload({ status: 'pending' })} />,
            );
            expect(
                screen.queryByRole('button', { name: /Ask Temari to read it/ }),
            ).not.toBeInTheDocument();
        });
    });

    describe('chained behavior', () => {
        it('shows "reread" on a done block when it is the chain head', () => {
            render(
                <AnalysisStatus
                    analysis={payload({
                        status: 'done',
                        content: 'recap',
                        type: 'weekly_recap',
                    })}
                    chained
                    isChainHead
                />,
            );
            expect(
                screen.getByRole('button', { name: /reread/ }),
            ).toBeInTheDocument();
        });

        it('hides "reread" on a done block that is not the chain head', () => {
            render(
                <AnalysisStatus
                    analysis={payload({
                        status: 'done',
                        content: 'recap',
                        type: 'weekly_recap',
                    })}
                    chained
                    isChainHead={false}
                />,
            );
            expect(screen.getByText('recap')).toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /reread/ }),
            ).not.toBeInTheDocument();
        });

        it('still shows "try again" on a failed chained block (resumes the chain) even when not head', () => {
            render(
                <AnalysisStatus
                    analysis={payload({
                        status: 'failed',
                        type: 'weekly_recap',
                    })}
                    chained
                    isChainHead={false}
                />,
            );
            expect(
                screen.getByRole('button', { name: /try again/ }),
            ).toBeInTheDocument();
        });

        it('renders nothing on a pending chained block even when not head', () => {
            const { container } = render(
                <AnalysisStatus
                    analysis={payload({
                        status: 'pending',
                        type: 'weekly_recap',
                    })}
                    chained
                    isChainHead={false}
                />,
            );
            expect(container).toBeEmptyDOMElement();
        });

        it('standalone (non-chained) done block keeps "reread" regardless of isChainHead', () => {
            render(
                <AnalysisStatus
                    analysis={payload({ status: 'done', content: 'x' })}
                    isChainHead={false}
                />,
            );
            expect(
                screen.getByRole('button', { name: /reread/ }),
            ).toBeInTheDocument();
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
