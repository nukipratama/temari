import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { AthleteRow } from '@/pages/Narration/types';

import RuleBasedPanel from './RuleBasedPanel';

function athlete(overrides: Partial<AthleteRow> = {}): AthleteRow {
    return {
        user_id: 1,
        user_name: 'Nuki',
        is_demo: false,
        deleted: false,
        today: 0,
        last7: 0,
        last30: 0,
        calls: 0,
        ceiling: null,
        ceiling_overridden: false,
        capped: false,
        sparkline: [],
        served: {
            llm: 0,
            rule_based: 0,
            unknown: 0,
            reasons: {
                demo: 0,
                capped: 0,
                return: 0,
                dead_letter: 0,
                content_filter: 0,
                unattributed: 0,
            },
        },
        flags: 0,
        dead_lettered: 0,
        ...overrides,
    };
}

describe('RuleBasedPanel', () => {
    it('falls back to an empty state when every done block was llm', () => {
        render(
            <RuleBasedPanel
                athletes={[
                    athlete({
                        served: {
                            llm: 10,
                            rule_based: 0,
                            unknown: 0,
                            reasons: athlete().served.reasons,
                        },
                    }),
                ]}
            />,
        );

        expect(
            screen.getByText(/Nothing served rule-based/),
        ).toBeInTheDocument();
    });

    it('sums a reason across every athlete and names who contributed', () => {
        render(
            <RuleBasedPanel
                athletes={[
                    athlete({
                        user_id: 1,
                        user_name: 'Nuki',
                        served: {
                            llm: 0,
                            rule_based: 0,
                            unknown: 0,
                            reasons: {
                                demo: 0,
                                capped: 31,
                                return: 14,
                                dead_letter: 6,
                                content_filter: 4,
                                unattributed: 2,
                            },
                        },
                    }),
                    athlete({
                        user_id: 2,
                        user_name: 'Rani',
                        served: {
                            llm: 0,
                            rule_based: 0,
                            unknown: 0,
                            reasons: {
                                demo: 0,
                                capped: 0,
                                return: 6,
                                dead_letter: 0,
                                content_filter: 3,
                                unattributed: 0,
                            },
                        },
                    }),
                ]}
            />,
        );

        expect(screen.getByText('daily ceiling reached')).toBeInTheDocument();
        expect(screen.getByText('31')).toBeInTheDocument();

        expect(screen.getByText('return backfill')).toBeInTheDocument();
        expect(screen.getByText('20')).toBeInTheDocument();
        expect(screen.getByText('Nuki 14 · Rani 6')).toBeInTheDocument();
    });

    it('flags content-filter, dead-letter and unattributed rows', () => {
        render(
            <RuleBasedPanel
                athletes={[
                    athlete({
                        served: {
                            llm: 0,
                            rule_based: 0,
                            unknown: 0,
                            reasons: {
                                demo: 0,
                                capped: 0,
                                return: 0,
                                dead_letter: 6,
                                content_filter: 4,
                                unattributed: 2,
                            },
                        },
                    }),
                ]}
            />,
        );

        expect(screen.getByText('dead-lettered block')).toHaveClass(
            'text-ember-ink',
        );
        expect(screen.getByText('content filter tripped')).toHaveClass(
            'text-ember-ink',
        );
        expect(screen.getByText('no reason recorded')).toHaveClass(
            'text-ember-ink',
        );
    });

    it('does not flag demo or capped rows', () => {
        render(
            <RuleBasedPanel
                athletes={[
                    athlete({
                        served: {
                            llm: 0,
                            rule_based: 0,
                            unknown: 0,
                            reasons: {
                                demo: 5,
                                capped: 3,
                                return: 0,
                                dead_letter: 0,
                                content_filter: 0,
                                unattributed: 0,
                            },
                        },
                    }),
                ]}
            />,
        );

        expect(screen.getByText('demo account')).not.toHaveClass(
            'text-ember-ink',
        );
        expect(screen.getByText('daily ceiling reached')).not.toHaveClass(
            'text-ember-ink',
        );
    });

    it('shows the pre-served_by and llm anchor rows', () => {
        render(
            <RuleBasedPanel
                athletes={[
                    athlete({
                        served: {
                            llm: 797,
                            rule_based: 0,
                            unknown: 54,
                            reasons: {
                                demo: 0,
                                capped: 1,
                                return: 0,
                                dead_letter: 0,
                                content_filter: 0,
                                unattributed: 0,
                            },
                        },
                    }),
                ]}
            />,
        );

        expect(
            screen.getByText('pre-dates served_by, producer not recorded'),
        ).toBeInTheDocument();
        expect(screen.getByText('54')).toBeInTheDocument();
        expect(
            screen.getByText('written by an llm narrator'),
        ).toBeInTheDocument();
        expect(screen.getByText('797')).toBeInTheDocument();
    });
});
