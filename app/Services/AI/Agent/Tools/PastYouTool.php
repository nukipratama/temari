<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Story\PastYouMatcher;

final class PastYouTool extends ActivityTool
{
    public function __construct(
        Activity $activity,
        ActivityDetail $detail,
        private readonly PastYouMatcher $pastYou,
    ) {
        parent::__construct($activity, $detail);
    }

    public function name(): string
    {
        return 'get_past_you';
    }

    public function description(): string
    {
        return 'Lari serupa milik pengguna sendiri di masa lalu, buat dibandingkan dengan sesi ini. '
            .'pace_diff_sec dan time_diff_sec positif = sekarang lebih cepat, negatif = lebih pelan; '
            .'hr_diff_bpm positif = HR lebih tinggi sekarang. Kalau past_you gak muncul, gak ada '
            .'tandingan yang layak, dan kalau begitu jangan mengarang perbandingan masa lalu.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        return ['past_you' => $this->pastYou->findMatchContext($this->activity, $this->detail)];
    }
}
