import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useAnalysisTrigger } from './useAnalysisTrigger';
import type { AnalysisPayload } from '@/types/inertia';

const fetchMock = vi.fn();

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

beforeEach(() => {
    globalThis.fetch = fetchMock as unknown as typeof fetch;
    fetchMock.mockReset();
    document.head.innerHTML = '<meta name="csrf-token" content="test-token" />';
});

afterEach(() => {
    document.head.innerHTML = '';
});

describe('useAnalysisTrigger', () => {
    it('optimistically flips status to queued and updates from response', async () => {
        fetchMock.mockResolvedValue({
            ok: true,
            json: async () => payload({ status: 'queued', id: 42 }),
        });

        const { result } = renderHook(() => useAnalysisTrigger(payload(), []));
        expect(result.current.status).toBe('pending');

        await act(async () => {
            await result.current.trigger();
        });

        expect(result.current.status).toBe('queued');
        expect(result.current.error).toBeNull();
        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('encodes discriminator into query string', async () => {
        fetchMock.mockResolvedValue({ ok: true, json: async () => payload({ status: 'queued' }) });

        const { result } = renderHook(() =>
            useAnalysisTrigger(payload({ discriminator: '2026-05-19' }), []),
        );
        await act(async () => {
            await result.current.trigger();
        });
        const url = fetchMock.mock.calls[0][0] as string;
        expect(url).toContain('?discriminator=2026-05-19');
    });

    it('sets status=failed and captures error when fetch !ok', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 500, json: async () => ({}) });

        const { result } = renderHook(() => useAnalysisTrigger(payload(), []));
        await act(async () => {
            await result.current.trigger();
        });
        await waitFor(() => expect(result.current.status).toBe('failed'));
        expect(result.current.error).toBeTruthy();
    });

    it('reloads inertia props when reload list is provided', async () => {
        fetchMock.mockResolvedValue({ ok: true, json: async () => payload({ status: 'queued' }) });

        const { result } = renderHook(() => useAnalysisTrigger(payload(), ['briefing']));
        await act(async () => {
            await result.current.trigger();
        });
        expect(result.current.status).toBe('queued');
    });

    it('catches non-Error throwables and records error string', async () => {
        fetchMock.mockRejectedValue('network fail');

        const { result } = renderHook(() => useAnalysisTrigger(payload(), []));
        await act(async () => {
            await result.current.trigger();
        });
        await waitFor(() => expect(result.current.status).toBe('failed'));
        expect(result.current.error).toBe('network fail');
    });

    it('falls back to empty CSRF when meta tag is missing', async () => {
        document.head.innerHTML = '';
        fetchMock.mockResolvedValue({ ok: true, json: async () => payload({ status: 'queued' }) });

        const { result } = renderHook(() => useAnalysisTrigger(payload(), []));
        await act(async () => {
            await result.current.trigger();
        });
        const init = fetchMock.mock.calls[0][1] as RequestInit;
        expect((init.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('');
    });

    it('invokes onUpdate callback with next payload', async () => {
        const onUpdate = vi.fn();
        const next = payload({ status: 'queued', id: 7 });
        fetchMock.mockResolvedValue({ ok: true, json: async () => next });

        const { result } = renderHook(() => useAnalysisTrigger(payload(), [], { onUpdate }));
        await act(async () => {
            await result.current.trigger();
        });
        expect(onUpdate).toHaveBeenCalledWith(next);
    });
});
