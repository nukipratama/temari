import { renderHook } from '@testing-library/react';
import { createElement, type ReactNode } from 'react';
import { describe, expect, it } from 'vitest';

import { setMockPage } from '@/test/setup';

import { SharedPropsOverrideContext, useSharedProps } from './useSharedProps';

describe('useSharedProps', () => {
    it("returns the page's shared props when nothing overrides them", () => {
        setMockPage({ aiPaused: true });

        const { result } = renderHook(() => useSharedProps());

        expect(result.current.aiPaused).toBe(true);
        expect(result.current.today).toBe('2026-06-17');
    });

    it('merges an override over the page props, keeping the rest', () => {
        setMockPage({ aiPaused: false });
        const wrapper = ({ children }: { children: ReactNode }) =>
            createElement(
                SharedPropsOverrideContext,
                { value: { aiPaused: true } },
                children,
            );

        const { result } = renderHook(() => useSharedProps(), { wrapper });

        expect(result.current.aiPaused).toBe(true);
        expect(result.current.today).toBe('2026-06-17');
    });
});
