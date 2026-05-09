<?php

declare(strict_types=1);

arch('app namespace stays free of debug helpers')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
