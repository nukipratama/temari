import { Head, Link } from '@inertiajs/react';
import { Fragment, type ReactNode } from 'react';

import PageContainer from '@/components/ui/PageContainer';
import { bareLayout } from '@/layouts/BareShell';

interface Section {
    heading: string;
    paragraphs: string[];
}

interface DocumentProps {
    slug: string;
    title: string;
    updated: string;
    intro: string;
    sections: Section[];
}

const DOCUMENTS: ReadonlyArray<{ slug: string; href: string; label: string }> =
    [
        { slug: 'terms', href: '/terms', label: 'Terms of use' },
        { slug: 'privacy', href: '/privacy', label: 'Privacy policy' },
        { slug: 'ai-use', href: '/ai-use', label: 'How Temari uses AI' },
        {
            slug: 'training-disclaimer',
            href: '/training-disclaimer',
            label: 'Training disclaimer',
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
                    <p className="mt-4 font-sans text-sm leading-relaxed text-text-2">
                        {linkify(intro)}
                    </p>

                    {sections.map((section) => (
                        <section key={section.heading} className="mt-10">
                            <h2 className="font-serif text-headline-sm text-foreground">
                                {section.heading}
                            </h2>
                            {section.paragraphs.map((paragraph) => (
                                <p
                                    key={paragraph}
                                    className="mt-3 font-sans text-sm leading-relaxed text-foreground"
                                >
                                    {linkify(paragraph)}
                                </p>
                            ))}
                        </section>
                    ))}

                    <nav
                        aria-label="Other documents"
                        className="mt-12 border-t border-border pt-6"
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
