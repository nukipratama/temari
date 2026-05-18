<?php

declare(strict_types=1);

return [
    'auto_dispatch' => filter_var(env('AI_AUTO_DISPATCH', true), FILTER_VALIDATE_BOOLEAN),
    'rate_limit_per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 20),
    'queue' => (string) env('AI_QUEUE', 'default'),
];
