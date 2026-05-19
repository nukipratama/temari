<?php

declare(strict_types=1);

return [
    'auto_dispatch' => filter_var(env('AI_AUTO_DISPATCH', true), FILTER_VALIDATE_BOOLEAN),
    'queue' => (string) env('AI_QUEUE', 'default'),
];
