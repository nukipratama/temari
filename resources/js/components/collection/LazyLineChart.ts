import { lazyIsland } from '@/lib/lazyIsland';

export const LazyLine = lazyIsland(() => import('./LineChart'));
