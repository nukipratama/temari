import { useState } from 'react';

import PillButton from '@/components/ui/PillButton';

export default function UsageSnippet({ code }: Readonly<{ code: string }>) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="relative">
            <pre className="overflow-x-auto rounded-sm bg-secondary p-3 pr-20 font-mono text-xs leading-relaxed text-foreground">
                <code>{code}</code>
            </pre>
            <PillButton
                tone="outline"
                size="xs"
                className="absolute top-2 right-2"
                onClick={() => {
                    void navigator.clipboard
                        .writeText(code)
                        .then(() => setCopied(true));
                }}
            >
                {copied ? 'copied' : 'copy'}
            </PillButton>
        </div>
    );
}
