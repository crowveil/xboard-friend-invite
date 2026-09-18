<?php

namespace Plugin\FriendInvite\Services;

use Carbon\CarbonImmutable;

final class Duration
{
    public const OPTIONS = ['day' => '1 天', 'month' => '1 个月', 'year' => '1 年', 'three_years' => '3 年', 'forever' => '永久'];

    public static function expires(string $key, ?int $start = null): ?int
    {
        $date = CarbonImmutable::createFromTimestamp($start ?? now()->timestamp, config('app.timezone', 'UTC'));
        return match ($key) {
            'day' => $date->timestamp + 86400,
            'month' => $date->addMonthNoOverflow()->timestamp,
            'year' => $date->addYearNoOverflow()->timestamp,
            'three_years' => $date->addYearsNoOverflow(3)->timestamp,
            'forever' => null,
            default => throw new Failure('使用期限无效'),
        };
    }
}
