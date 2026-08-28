import { useRef, useState, type FormEvent } from 'react';

import Temari from '@/components/temari/Temari';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Icon } from '@/components/ui/Icon';
import PillButton from '@/components/ui/PillButton';
import SectionLabel from '@/components/ui/SectionLabel';
import {
    MAX_QUESTION_LENGTH,
    MIN_QUESTION_LENGTH,
    useRunQuestions,
    type AskError,
    type RunQuestion,
} from '@/hooks/useRunQuestions';
import { cn } from '@/lib/cn';
import { renderBold } from '@/lib/richText';
import { inputVariants, outlineChipVariants } from '@/lib/variants';

const ERROR_COPY: Readonly<Record<AskError, string>> = {
    rate_limited:
        "You're asking faster than I can think. Give it a minute, then try again.",
    paused: "Generation is paused right now, so I didn't send that one. It would only sit there.",
    invalid: "I couldn't read that one. Try rephrasing it.",
    failed: 'That question never made it to me. Try again.',
};

function normalize(question: string): string {
    return question.trim().toLowerCase().replace(/\?+$/, '');
}

interface AskAboutRunProps {
    activityId: number;
    /**
     * This run has no splits, zones or terrain yet, so the toolbox behind the
     * answer is smaller. Said out loud rather than silently answering thinner.
     */
    summaryOnly?: boolean;
    className?: string;
}

export default function AskAboutRun({
    activityId,
    summaryOnly = false,
    className,
}: Readonly<AskAboutRunProps>) {
    const { questions, suggestions, ask, asking, error, stalled, checkAgain } =
        useRunQuestions(activityId);
    const [draft, setDraft] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);

    const asked = new Set(questions.map((q) => normalize(q.question)));
    const unasked = suggestions.filter((s) => !asked.has(normalize(s)));
    const canSend = draft.trim().length >= MIN_QUESTION_LENGTH && !asking;

    const send = async (text: string) => {
        const accepted = await ask(text);
        if (accepted) {
            setDraft('');
        }
    };

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        void send(draft);
    };

    const reuse = (question: string) => {
        setDraft(question);
        inputRef.current?.focus();
    };

    return (
        <section className={className} data-coachmark="run-ask">
            <SectionLabel>Ask about this run</SectionLabel>
            <Card className="mt-3 px-6 py-6">
                <header className="flex items-start gap-3.5">
                    <Temari pose="observational" size={44} animate={false} />
                    <div className="min-w-0">
                        <p className="font-serif text-quote-md italic leading-snug text-text-2">
                            The numbers are up there. Ask me why.
                        </p>
                        <p className="mt-1.5 font-sans text-xs text-text-3">
                            One run, one question at a time. I can only read
                            this run and your own history.
                        </p>
                    </div>
                </header>

                {summaryOnly && (
                    <p
                        role="status"
                        className="mt-4 rounded-sm border border-border bg-muted px-3.5 py-2.5 font-sans text-sm leading-relaxed text-text-2"
                    >
                        Only the summary has landed for this run, so no splits,
                        zones or terrain yet. I'll answer from what's here.
                    </p>
                )}

                {unasked.length > 0 && (
                    <div className="mt-5">
                        <SectionLabel size="micro" className="mb-2">
                            Starting points
                        </SectionLabel>
                        <div className="flex flex-wrap gap-2">
                            {unasked.map((suggestion) => (
                                <button
                                    key={suggestion}
                                    type="button"
                                    disabled={asking}
                                    onClick={() => void send(suggestion)}
                                    className={cn(
                                        outlineChipVariants({
                                            selected: false,
                                        }),
                                        'text-left disabled:opacity-50',
                                    )}
                                >
                                    {suggestion}
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                <form onSubmit={onSubmit} className="mt-5 flex flex-wrap gap-2">
                    <label htmlFor="run-question" className="sr-only">
                        Your question about this run
                    </label>
                    <input
                        id="run-question"
                        ref={inputRef}
                        type="text"
                        value={draft}
                        maxLength={MAX_QUESTION_LENGTH}
                        disabled={asking}
                        onChange={(event) => setDraft(event.target.value)}
                        placeholder="Ask anything about this run"
                        className={cn(inputVariants(), 'min-w-0 flex-1')}
                    />
                    <Button
                        type="submit"
                        disabled={!canSend}
                        className="disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Icon
                            icon={asking ? 'mdi:loading' : 'mdi:send'}
                            width={15}
                            height={15}
                            className={asking ? 'animate-spin' : undefined}
                            aria-hidden
                        />
                        {asking ? 'Sending…' : 'Ask'}
                    </Button>
                </form>

                {error !== null && (
                    <p
                        role="status"
                        aria-live="polite"
                        className="mt-3 font-sans text-sm text-ember-ink"
                    >
                        {ERROR_COPY[error]}
                    </p>
                )}

                {questions.length > 0 && (
                    <ol className="mt-6 flex flex-col gap-5 border-t border-border pt-5">
                        {questions.map((question) => (
                            <QuestionRow
                                key={question.id}
                                question={question}
                                stalled={stalled}
                                onCheckAgain={checkAgain}
                                onReuse={reuse}
                            />
                        ))}
                    </ol>
                )}
            </Card>
        </section>
    );
}

function QuestionRow({
    question,
    stalled,
    onCheckAgain,
    onReuse,
}: Readonly<{
    question: RunQuestion;
    stalled: boolean;
    onCheckAgain: () => void;
    onReuse: (question: string) => void;
}>) {
    const pending =
        question.status === 'queued' || question.status === 'processing';

    return (
        <li>
            <p className="font-sans text-sm font-semibold text-foreground">
                {question.question}
            </p>
            {pending && (
                <div className="mt-2 flex flex-wrap items-center gap-3">
                    <span
                        role="status"
                        className="inline-flex items-center gap-2 font-sans text-sm text-text-3"
                    >
                        <Icon
                            icon="mdi:loading"
                            width={14}
                            height={14}
                            className={stalled ? undefined : 'animate-spin'}
                            aria-hidden
                        />
                        {stalled
                            ? 'Still working on this one.'
                            : 'Thinking about it.'}
                    </span>
                    {stalled && (
                        <PillButton
                            tone="outline"
                            size="sm"
                            onClick={onCheckAgain}
                        >
                            Check again
                        </PillButton>
                    )}
                </div>
            )}
            {question.status === 'failed' && (
                <div className="mt-2 flex flex-wrap items-center gap-3">
                    <span className="font-sans text-sm text-ember-ink">
                        This one didn't come back.
                    </span>
                    <PillButton
                        tone="outline"
                        size="sm"
                        onClick={() => onReuse(question.question)}
                    >
                        Ask it again
                    </PillButton>
                </div>
            )}
            {question.status === 'done' && question.answer !== null && (
                <p className="mt-2 font-sans text-sm leading-relaxed text-foreground">
                    {renderBold(question.answer)}
                </p>
            )}
        </li>
    );
}
