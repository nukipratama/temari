import { useState } from 'react';

import PillButton from '@/components/ui/PillButton';

export default function UsageSnippet({ code }: Readonly<{ code: string }>) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="flex items-start gap-2">
            <pre className="min-w-0 flex-1 overflow-x-auto rounded-sm bg-secondary p-3 font-mono text-xs leading-relaxed text-foreground">
                <code>{code}</code>
            </pre>
            <PillButton
                tone="outline"
                size="xs"
                className="shrink-0"
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
