import { useEffect, useState } from 'react';

export function useAfterPaint(): boolean {
    const [ready, setReady] = useState(false);

    useEffect(() => {
        let timer: ReturnType<typeof setTimeout> | undefined;
        const frame = requestAnimationFrame(() => {
            timer = setTimeout(() => setReady(true), 0);
        });

        return () => {
            cancelAnimationFrame(frame);
            clearTimeout(timer);
        };
    }, []);

    return ready;
}
