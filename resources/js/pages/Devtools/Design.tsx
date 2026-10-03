import { Head } from '@inertiajs/react';
import { Suspense, useState } from 'react';

import DevtoolsHeader from '@/components/narration/DevtoolsHeader';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useIsDarkGround } from '@/hooks/useIsDarkGround';
import { lazyIsland } from '@/lib/lazyIsland';

const Catalogue = lazyIsland(() => import('@/components/catalogue/Catalogue'));
const TokenSheet = lazyIsland(
    () => import('@/components/catalogue/TokenSheet'),
);

type View = 'components' | 'tokens';

export default function Design() {
    const [view, setView] = useState<View>('components');
    const dark = useIsDarkGround();

    return (
        <>
            <Head title="Design · Devtools" />
            <div className="min-h-screen bg-background text-foreground">
                <DevtoolsHeader title="Design" />
                <div className="mx-auto box-content max-w-page pad-page 2xl:max-w-page-2xl">
                    <ToggleGroup
                        value={view}
                        onValueChange={setView}
                        aria-label="View"
                        className="mb-8"
                    >
                        <ToggleGroupItem value="components">
                            components
                        </ToggleGroupItem>
                        <ToggleGroupItem value="tokens">tokens</ToggleGroupItem>
                    </ToggleGroup>
                    <Suspense
                        fallback={<p className="text-meta">loading {view}…</p>}
                    >
                        {view === 'components' ? (
                            <Catalogue />
                        ) : (
                            <TokenSheet key={dark ? 'dark' : 'light'} />
                        )}
                    </Suspense>
                </div>
            </div>
        </>
    );
}
