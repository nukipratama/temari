import { useRef, useState, type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import Card from '@/components/ui/LegacyCard';
import PillButton from '@/components/ui/PillButton';
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
        "you're asking faster than i can think. give it a minute, then try again.",
    paused: "generation is paused right now, so i didn't send that one. it would only sit there.",
    invalid: "i couldn't read that one. try rephrasing it.",
    failed: 'that question never made it to me. try again.',
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

/**
 * The Q&A panel: the thread so far, then the ask box. The invitation copy and
 * the starting points are cold-start affordances — they retire once the thread
 * has an entry, so what Temari already said sits at the top of the panel.
 */
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
        <section className={className}>
            <Card tone="narration" padding="hero">
                <div className="flex items-center gap-1.5">
                    <Icon
                        icon="mdi:chat-outline"
                        width={12}
                        height={12}
                        aria-hidden
                    />
                    <Eyebrow token="micro" tone="icon-accent" as="span">
                        Ask about this run
                    </Eyebrow>
                </div>
                {questions.length === 0 && (
                    <>
                        <p className="narration mt-2">
                            The numbers are up there. Ask me why.
                        </p>
                        <p className="mt-1.5 font-sans text-xs leading-relaxed text-text-2">
                            One run, one question at a time. I can only read
                            this run and your own history.
                        </p>
                    </>
                )}

                {summaryOnly && (
                    <p
                        role="status"
                        className="mt-4 rounded-sm border border-border bg-muted px-3.5 py-2.5 font-sans text-xs leading-relaxed text-text-2"
                    >
                        Only the summary has landed for this run, so no splits,
                        zones or terrain yet. I'll answer from what's here.
                    </p>
                )}

                {questions.length > 0 && (
                    <ol className="mt-3 list-none">
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

                {questions.length === 0 && unasked.length > 0 && (
                    <>
                        <Eyebrow
                            token="micro"
                            tone="ink-3"
                            className="mb-2 mt-3.5"
                        >
                            Starting points
                        </Eyebrow>
                        <div className="mb-3.5 flex flex-wrap gap-1.5">
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
                    </>
                )}

                <form
                    onSubmit={onSubmit}
                    className="mt-3.5 flex flex-wrap gap-2"
                >
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
                        placeholder="ask anything about this run"
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
                        {asking ? 'sending…' : 'ask'}
                    </Button>
                </form>

                {error !== null && (
                    <p
                        role="status"
                        aria-live="polite"
                        className="mt-3 font-sans text-xs text-ember-ink"
                    >
                        {ERROR_COPY[error]}
                    </p>
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
        <li className="border-b border-border-strong py-3 last:border-b-0">
            <p className="border-l-2 border-horizon-ink pl-2.5 font-sans text-xs leading-relaxed text-text-2">
                {question.question}
            </p>
            {pending && (
                <div className="mt-1 flex flex-wrap items-center gap-3">
                    <span
                        role="status"
                        className="inline-flex items-center gap-1.5 font-sans text-xs text-text-2"
                    >
                        <Icon
                            icon="mdi:loading"
                            width={12}
                            height={12}
                            className={stalled ? undefined : 'animate-spin'}
                            aria-hidden
                        />
                        {stalled
                            ? 'still working on this one.'
                            : 'thinking about it.'}
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
                <div className="mt-1.5 flex flex-wrap items-center gap-2">
                    <span className="font-sans text-xs text-ember-ink">
                        This one didn't come back.
                    </span>
                    <PillButton
                        tone="outline"
                        size="sm"
                        onClick={() => onReuse(question.question)}
                    >
                        ask it again
                    </PillButton>
                </div>
            )}
            {question.status === 'done' && question.answer !== null && (
                <p className="narration mt-2">{renderBold(question.answer)}</p>
            )}
        </li>
    );
}
