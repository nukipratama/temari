import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import AttentionArea from './AttentionArea';
import { formMock, setMockPage } from '@/test/setup';
import type { DeadLetterGroup } from '@/pages/AiUsage/types';

const deadLetteredGroup: DeadLetterGroup = {
    user_id: 7,
    user_name: 'Charlie',
    count: 2,
    blocks: [
        { type: 'weekly_recap', error: 'Azure down', failed_at: '2026-05-19T10:00:00+00:00' },
        { type: 'pr_context', error: null, failed_at: '2026-05-19T09:00:00+00:00' },
    ],
};

const nyangkutGroup: DeadLetterGroup = {
    user_id: 8,
    user_name: 'Dina',
    count: 1,
    blocks: [{ type: 'briefing_mascot_voice', error: null, failed_at: '2026-05-19T08:00:00+00:00' }],
};

function renderArea(overrides: Partial<Parameters<typeof AttentionArea>[0]> = {}) {
    return render(
        <AttentionArea deadLettered={[]} failedUnderBudget={[]} nyangkut={[]} {...overrides} />,
    );
}

describe('AttentionArea', () => {
    it('renders nothing when all three buckets are empty', () => {
        const { container } = renderArea();

        expect(container).toBeEmptyDOMElement();
    });

    it('renders a per-user dead-letter group with its stuck-block count and type chips', () => {
        renderArea({ deadLettered: [deadLetteredGroup] });

        expect(screen.getByText('Perlu perhatian')).toBeInTheDocument();
        expect(screen.getByText('Charlie')).toBeInTheDocument();
        expect(screen.getByText('2 blok berhenti dicoba otomatis')).toBeInTheDocument();
        expect(screen.getByText('weekly_recap')).toBeInTheDocument();
        expect(screen.getByText('pr_context')).toBeInTheDocument();
    });

    it('lists block types only, never the raw error text', () => {
        renderArea({ deadLettered: [deadLetteredGroup] });

        expect(screen.queryByText('Azure down')).not.toBeInTheDocument();
    });

    it('collapses repeated block types into one "type ×N" chip', () => {
        renderArea({
            deadLettered: [
                {
                    ...deadLetteredGroup,
                    count: 3,
                    blocks: [
                        { type: 'weekly_recap', error: null, failed_at: '2026-05-19T10:00:00+00:00' },
                        { type: 'weekly_recap', error: null, failed_at: '2026-05-19T09:00:00+00:00' },
                        { type: 'pr_context', error: null, failed_at: '2026-05-19T08:00:00+00:00' },
                    ],
                },
            ],
        });

        expect(screen.getByText('weekly_recap').closest('li')?.textContent).toBe('weekly_recap×2');
        expect(screen.getByText('pr_context').closest('li')?.textContent).toBe('pr_context');
    });

    it('posts to the per-user retry route on "Coba lagi semua"', () => {
        renderArea({ deadLettered: [deadLetteredGroup] });

        fireEvent.click(screen.getByRole('button', { name: /Coba lagi semua/ }));

        expect(formMock.post).toHaveBeenCalledWith('/ai-usage/users/7/retry-failed', expect.anything());
    });

    it('disables the retry button and shows "Mengirim…" while the retry is processing', () => {
        formMock.processing = true;
        renderArea({ deadLettered: [deadLetteredGroup] });

        expect(screen.getByRole('button', { name: /Mengirim/ })).toBeDisabled();
        expect(screen.queryByRole('button', { name: /Coba lagi semua/ })).not.toBeInTheDocument();
    });

    it('disables the retry button (but keeps it visible) when AI is globally paused', () => {
        setMockPage({ aiPaused: true });
        renderArea({ deadLettered: [deadLetteredGroup] });

        expect(screen.getByRole('button', { name: /Coba lagi semua/ })).toBeDisabled();
    });

    it('renders the failed-under-budget bucket with a per-user re-arm button', () => {
        renderArea({ failedUnderBudget: [deadLetteredGroup] });

        expect(screen.getByText('Failed, belum menyerah')).toBeInTheDocument();
        expect(screen.getByText('2 blok gagal, masih dicoba otomatis')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Coba lagi semua/ })).toBeInTheDocument();
    });

    it('renders the nyangkut bucket without a per-user button (global recover handles it)', () => {
        renderArea({ nyangkut: [nyangkutGroup] });

        expect(screen.getByText('Nyangkut')).toBeInTheDocument();
        expect(screen.getByText('Dina')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Coba lagi semua/ })).not.toBeInTheDocument();
    });

    it('hides a bucket that has no groups while another bucket is filled', () => {
        renderArea({ nyangkut: [nyangkutGroup] });

        expect(screen.queryByText('Perlu perhatian')).not.toBeInTheDocument();
        expect(screen.queryByText('Failed, belum menyerah')).not.toBeInTheDocument();
    });

    it('shows the global recover bar whenever any bucket is non-empty', () => {
        renderArea({ nyangkut: [nyangkutGroup] });

        expect(screen.getByRole('button', { name: /Pulihkan semua/ })).toBeInTheDocument();
    });

    it('posts to the recover route on "Pulihkan semua"', () => {
        renderArea({ deadLettered: [deadLetteredGroup] });

        fireEvent.click(screen.getByRole('button', { name: /Pulihkan semua/ }));

        expect(formMock.post).toHaveBeenCalledWith('/ai-usage/recover', expect.anything());
    });

    it('shows "Memulihkan…" on the recover bar while the sweep is in flight', () => {
        formMock.processing = true;
        renderArea({ nyangkut: [nyangkutGroup] });

        expect(screen.getByRole('button', { name: /Memulihkan/ })).toBeDisabled();
    });
});
