import { Download } from 'lucide-react';

import Eyebrow from '@/components/ui/Eyebrow';
import { Icon } from '@/components/ui/Icon';
import { useInstallPrompt } from '@/lib/installPrompt';

/** Offers the browser's own install prompt, and renders nothing while there is none to offer. */
export default function InstallAppSection() {
    const install = useInstallPrompt();

    if (install === null) {
        return null;
    }

    return (
        <section>
            <Eyebrow as="h2" token="small" tone="ink-2">
                App
            </Eyebrow>
            <div className="mt-3 flex items-center justify-between gap-4">
                <p className="text-xs leading-relaxed text-text-2">
                    put temari on your home screen. it opens full screen, like
                    any other app.
                </p>
                <button
                    type="button"
                    onClick={() => void install()}
                    className="pressable focus-ring flex shrink-0 items-center gap-2 rounded-lg border border-border-strong bg-card px-4 py-2.5 font-sans text-xs font-bold text-foreground transition hover:bg-cream-deep/30"
                >
                    <Icon icon={Download} width={16} height={16} aria-hidden />
                    install
                </button>
            </div>
        </section>
    );
}
