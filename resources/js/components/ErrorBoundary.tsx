import { Icon } from '@iconify/react';
import { Component, type ErrorInfo, type ReactNode } from 'react';

import { reportClientError } from '@/lib/clientErrorReporter';

interface Props {
    children: ReactNode;
}

interface State {
    hasError: boolean;
}

/**
 * Catches render errors anywhere below it so a single broken page shows a
 * friendly fallback instead of a blank white screen, and reports the error to
 * the server. Error boundaries must be class components.
 */
export default class ErrorBoundary extends Component<Props, State> {
    state: State = { hasError: false };

    static getDerivedStateFromError(): State {
        return { hasError: true };
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        reportClientError({
            message: error.message || 'React render error',
            stack: error.stack ?? null,
            url: window.location.href,
            componentStack: info.componentStack ?? null,
        });
    }

    render(): ReactNode {
        if (!this.state.hasError) {
            return this.props.children;
        }

        return (
            <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-background px-6 text-center">
                <Icon
                    icon="mdi:emoticon-sad-outline"
                    className="text-5xl text-horizon-ink"
                    aria-hidden
                />
                <div className="flex flex-col gap-1">
                    <h1 className="text-lg font-semibold text-foreground">
                        Oops, something broke.
                    </h1>
                    <p className="text-sm text-text-2">
                        This page is being cranky. Try reloading it.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={() => window.location.reload()}
                    className="focus-ring inline-flex items-center gap-1.5 rounded-full bg-leaf-deep px-4 py-2 text-sm font-semibold text-cream transition hover:opacity-90"
                >
                    <Icon icon="mdi:refresh" aria-hidden />
                    <span>Reload</span>
                </button>
            </div>
        );
    }
}
