import { Head, Link } from '@inertiajs/react';
import { Fragment, type ReactNode } from 'react';

import Eyebrow from '@/components/ui/Eyebrow';
import PageContainer from '@/components/ui/PageContainer';
import { bareLayout } from '@/layouts/BareShell';

interface Section {
    id?: string;
    heading: string;
    paragraphs: string[];
}

interface DocumentProps {
    slug: string;
    title: string;
    updated: string;
    intro: string;
    summary: string[];
    sections: Section[];
}

const DOCUMENTS: ReadonlyArray<{ slug: string; href: string; label: string }> =
    [
        { slug: 'terms', href: '/terms', label: 'terms of use' },
        { slug: 'privacy', href: '/privacy', label: 'privacy policy' },
        {
            slug: 'training-disclaimer',
            href: '/training-disclaimer',
            label: 'training disclaimer',
        },
    ];

const URL_SPLIT = /(https?:\/\/\S+)/g;
const IS_URL = /^https?:\/\/\S+$/;

/** Turns bare URLs in the copy into links without pulling in a markdown parser. */
function linkify(text: string): ReactNode {
    let offset = 0;

    return text.split(URL_SPLIT).map((part) => {
        const key = `${offset}:${part}`;
        offset += part.length;

        return IS_URL.test(part) ? (
            <a
                key={key}
                href={part}
                rel="noreferrer noopener"
                target="_blank"
                className="underline decoration-horizon-deep underline-offset-2 hover:text-foreground"
            >
                {part}
            </a>
        ) : (
            <Fragment key={key}>{part}</Fragment>
        );
    });
}

export default function LegalDocument({
    slug,
    title,
    updated,
    intro,
    summary,
    sections,
}: Readonly<DocumentProps>) {
    return (
        <>
            <Head title={title} />
            <PageContainer>
                <div className="mx-auto max-w-[46rem] py-10">
                    <Link
                        href="/login"
                        className="font-mono text-xs font-semibold uppercase tracking-wider text-text-3 hover:text-foreground"
                    >
                        Temari
                    </Link>

                    <h1 className="mt-4 font-serif text-display-lg text-foreground">
                        {title}
                    </h1>
                    <p className="mt-2 font-mono text-xs font-semibold uppercase tracking-wider text-text-3">
                        Last updated {updated}
                    </p>
                    <p className="mt-4 max-w-[44ch] font-sans text-sm leading-relaxed text-text-2">
                        {linkify(intro)}
                    </p>

                    <section
                        aria-label="the short version"
                        className="mt-6 rounded-2xl border border-border bg-card px-4 py-3.5 shadow-e1"
                    >
                        <Eyebrow
                            token="small"
                            as="h2"
                            className="text-foreground"
                        >
                            the short version
                        </Eyebrow>
                        <ul className="mt-2 flex list-disc flex-col gap-1.5 pl-4.5 font-sans text-sm leading-relaxed text-foreground marker:text-text-3">
                            {summary.map((line) => (
                                <li key={line} className="max-w-[44ch]">
                                    {line}
                                </li>
                            ))}
                        </ul>
                    </section>

                    <div className="mt-10 flex flex-col divide-y divide-dashed divide-border [&>*:not(:last-child)]:pb-10 [&>*:not(:first-child)]:pt-10">
                        {sections.map((section) => (
                            <section
                                key={section.heading}
                                id={section.id}
                                className="scroll-mt-6"
                            >
                                <Eyebrow
                                    token="small"
                                    as="h2"
                                    className="text-foreground"
                                >
                                    {section.heading}
                                </Eyebrow>
                                {section.paragraphs.map((paragraph) => (
                                    <p
                                        key={paragraph}
                                        className="mt-3 max-w-[44ch] font-sans text-sm leading-relaxed text-foreground"
                                    >
                                        {linkify(paragraph)}
                                    </p>
                                ))}
                            </section>
                        ))}
                    </div>

                    <nav
                        aria-label="Other documents"
                        className="mt-12 border-t border-dashed border-border pt-6"
                    >
                        <ul className="flex flex-wrap gap-x-6 gap-y-2">
                            {DOCUMENTS.filter(
                                (document) => document.slug !== slug,
                            ).map((document) => (
                                <li key={document.slug}>
                                    <Link
                                        href={document.href}
                                        className="font-sans text-sm text-text-2 underline underline-offset-2 hover:text-foreground"
                                    >
                                        {document.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </nav>
                </div>
            </PageContainer>
        </>
    );
}

LegalDocument.layout = bareLayout;
