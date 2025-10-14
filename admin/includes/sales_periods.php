<?php

declare(strict_types=1);

/**
 * Normalize a sales period selection (daily/weekly/monthly/annually) into
 * explicit start/end boundaries that can be used in SQL queries.
 *
 * @param string $period Time grouping requested by the UI
 * @param string|null $value Raw value coming from the UI control (may be empty)
 *
 * @return array{
 *     period: string,
 *     value: string,
 *     start: string,
 *     end: string,
 *     range_start: string,
 *     range_end: string,
 *     label: string
 * }
 */
function resolve_sales_period(string $period, ?string $value = null): array
{
    $normalizedPeriod = strtolower(trim($period)) ?: 'daily';
    if ($normalizedPeriod === 'annual') {
        $normalizedPeriod = 'annually';
    }

    $now = new DateTimeImmutable('now');

    switch ($normalizedPeriod) {
        case 'weekly':
            $pattern = '/^(\d{4})-W(\d{2})$/';
            if ($value && preg_match($pattern, $value, $matches)) {
                $isoYear = (int) $matches[1];
                $isoWeek = (int) $matches[2];
            } else {
                $isoYear = (int) $now->format('o');
                $isoWeek = (int) $now->format('W');
            }

            $start = (new DateTimeImmutable())->setISODate($isoYear, $isoWeek)->setTime(0, 0, 0);
            $end = $start->modify('+1 week');
            $label = sprintf(
                'Week %s (%s - %s)',
                str_pad((string) $isoWeek, 2, '0', STR_PAD_LEFT),
                $start->format('M j'),
                $end->modify('-1 day')->format('M j, Y')
            );
            $normalizedValue = sprintf('%04d-W%02d', $isoYear, $isoWeek);
            break;

        case 'monthly':
            $pattern = '/^(\d{4})-(\d{2})$/';
            if ($value && preg_match($pattern, $value, $matches)) {
                $year = (int) $matches[1];
                $month = max(1, min(12, (int) $matches[2]));
            } else {
                $year = (int) $now->format('Y');
                $month = (int) $now->format('n');
            }

            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', sprintf('%04d-%02d-01 00:00:00', $year, $month));
            if (!$start) {
                $start = new DateTimeImmutable('first day of this month');
            }
            $end = $start->modify('+1 month');
            $label = $start->format('F Y');
            $normalizedValue = $start->format('Y-m');
            break;

        case 'annually':
            $pattern = '/^(\d{4})$/';
            if ($value && preg_match($pattern, $value, $matches)) {
                $year = (int) $matches[1];
            } else {
                $year = (int) $now->format('Y');
            }

            $start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', sprintf('%04d-01-01 00:00:00', $year));
            if (!$start) {
                $start = new DateTimeImmutable('first day of January this year');
            }
            $end = $start->modify('+1 year');
            $label = $start->format('Y');
            $normalizedValue = $start->format('Y');
            break;

        case 'daily':
        default:
            $date = null;
            if ($value) {
                $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value . ' 00:00:00');
                if (!$date) {
                    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
                }
            }
            if (!$date) {
                $date = new DateTimeImmutable('today');
            }
            $start = $date->setTime(0, 0, 0);
            $end = $start->modify('+1 day');
            $label = $start->format('F j, Y');
            $normalizedPeriod = 'daily';
            $normalizedValue = $start->format('Y-m-d');
            break;
    }

    $rangeEnd = $end->modify('-1 second');

    return [
        'period' => $normalizedPeriod,
        'value' => $normalizedValue,
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
        'range_start' => $start->format('Y-m-d'),
        'range_end' => $rangeEnd->format('Y-m-d'),
        'label' => $label,
    ];
}
