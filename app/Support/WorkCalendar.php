<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Phase 4 — Computes business-hours duration between two timestamps.
 *
 * Reads its working schedule from config/work_calendar.php every call,
 * so changing the config takes effect on subsequent computations
 * without requiring a code reload. (Existing audit rows store snapshot
 * durations and don't recompute — see Layer 4-1 README.)
 *
 * Algorithm:
 *   - Walk the time range one day at a time.
 *   - For each day, intersect the day's [work_start, work_end) window
 *     with the [start, end) range, skipping days that aren't in
 *     work_days or that are listed in holidays.
 *   - Sum the seconds of overlap.
 *
 * Edge cases handled:
 *   - end <= start  → returns 0
 *   - Both timestamps fall inside one work day  → straight subtract
 *   - Range spans weekend(s) or holidays  → those intervals contribute 0
 *   - Range starts before / ends after work hours  → clipped to window
 */
class WorkCalendar
{
    /**
     * Compute business-hours duration between $start and $end in seconds.
     *
     * @param DateTimeInterface|string|null $start
     * @param DateTimeInterface|string|null $end
     */
    public static function businessSecondsBetween($start, $end): int
    {
        if (! $start || ! $end) {
            return 0;
        }

        $cfg = self::config();

        $startDt = self::toCarbon($start, $cfg['timezone']);
        $endDt   = self::toCarbon($end,   $cfg['timezone']);

        if ($endDt->lessThanOrEqualTo($startDt)) {
            return 0;
        }

        // Cap iteration to a sane upper bound. Anything that takes more
        // than ~3 years in a single stage is an outlier we don't need
        // to compute precisely.
        $hardLimit = 365 * 3;
        $iterations = 0;

        $totalSeconds = 0;
        // Walk day-by-day, midnight-aligned in the work timezone.
        $cursor = $startDt->copy()->startOfDay();
        $endDay = $endDt->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($endDay) && $iterations < $hardLimit) {
            $iterations++;

            if (self::isWorkDay($cursor, $cfg)) {
                $dayStart = $cursor->copy()->setTimeFromTimeString($cfg['work_start']);
                $dayEnd   = $cursor->copy()->setTimeFromTimeString($cfg['work_end']);

                // Intersect [dayStart, dayEnd) with [startDt, endDt).
                $intervalStart = $startDt->greaterThan($dayStart) ? $startDt : $dayStart;
                $intervalEnd   = $endDt->lessThan($dayEnd)        ? $endDt   : $dayEnd;

                if ($intervalEnd->greaterThan($intervalStart)) {
                    // abs() guards against Carbon 3's signed diffInSeconds
                    // behavior — pre-3.0 it returned absolute values,
                    // 3.0+ returns signed (which would give us a
                    // negative total here).
                    $totalSeconds += abs($intervalEnd->diffInSeconds($intervalStart));
                }
            }

            $cursor->addDay();
        }

        return $totalSeconds;
    }

    // ------------------------------------------------------------------

    /**
     * @return array{timezone:string,work_days:array<int,int>,work_start:string,work_end:string,holidays:array<int,string>}
     */
    protected static function config(): array
    {
        $cfg = config('work_calendar', []);

        return [
            'timezone'   => $cfg['timezone']   ?? 'Asia/Manila',
            'work_days'  => $cfg['work_days']  ?? [1, 2, 3, 4, 5, 6],
            'work_start' => $cfg['work_start'] ?? '08:00',
            'work_end'   => $cfg['work_end']   ?? '18:00',
            'holidays'   => $cfg['holidays']   ?? [],
        ];
    }

    protected static function toCarbon($value, string $tz): CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->setTimezone($tz);
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->setTimezone($tz);
        }

        return Carbon::parse((string) $value)->setTimezone($tz);
    }

    /**
     * Day-of-week match + not on the holiday list.
     *
     * @param array{work_days:array<int,int>,holidays:array<int,string>} $cfg
     */
    protected static function isWorkDay(CarbonInterface $day, array $cfg): bool
    {
        if (! in_array($day->dayOfWeek, $cfg['work_days'], true)) {
            return false;
        }

        $iso = $day->format('Y-m-d');
        if (in_array($iso, $cfg['holidays'], true)) {
            return false;
        }

        return true;
    }
}
