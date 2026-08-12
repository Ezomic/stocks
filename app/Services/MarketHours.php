<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Position;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Answers whether the venue a position trades on is open right now.
 *
 * The schedule comes from IBKR rather than a calendar in this repo, because half-days and
 * holidays are exactly the days a weekday-and-clock check gets wrong, and getting it wrong
 * means an order sitting unexecuted overnight.
 */
class MarketHours
{
    public function __construct(private readonly IbkrClient $client) {}

    /**
     * True when the market is open, false when it is known to be closed, and null when the
     * schedule could not be determined at all.
     */
    public function isOpen(Position $position, ?CarbonImmutable $at = null): ?bool
    {
        if ($position->allowsFractionalQuantity()) {
            return true;
        }

        $conid = (string) $position->ibkr_con_id;

        if ($conid === '') {
            return null;
        }

        $schedule = $this->schedule($conid);

        if ($schedule === null) {
            return null;
        }

        $now = ($at ?? CarbonImmutable::now())->setTimezone($schedule['timezone']);

        foreach ($schedule['sessions'] as [$opens, $closes]) {
            if ($now->betweenIncluded($opens->setTimezone($schedule['timezone']), $closes->setTimezone($schedule['timezone']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{timezone: string, sessions: array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>}|null
     */
    private function schedule(string $conid): ?array
    {
        /** @var array{timezone: string, sessions: array<int, array<int, string>>}|null $cached */
        $cached = Cache::remember(
            "ibkr.trading_hours.{$conid}",
            CarbonImmutable::now()->addHours(6),
            fn (): ?array => $this->fetchSchedule($conid)
        );

        if ($cached === null) {
            return null;
        }

        $sessions = [];

        foreach ($cached['sessions'] as [$opens, $closes]) {
            $sessions[] = [
                CarbonImmutable::parse($opens),
                CarbonImmutable::parse($closes),
            ];
        }

        return ['timezone' => $cached['timezone'], 'sessions' => $sessions];
    }

    /**
     * Cached as strings so the entry survives serialisation in any cache store.
     *
     * @return array{timezone: string, sessions: array<int, array<int, string>>}|null
     */
    private function fetchSchedule(string $conid): ?array
    {
        try {
            $response = $this->client->contractInfo($conid);
        } catch (\Throwable $e) {
            Log::warning("Could not read trading hours for contract {$conid}: ".$e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $hours = $response->json('trading_hours');
        $timezone = $response->json('time_zone_id');

        if (! is_string($hours) || ! is_string($timezone) || $hours === '') {
            return null;
        }

        // An unrecognised timezone would silently shift every session, so treat it as unknown.
        try {
            CarbonImmutable::now($timezone);
        } catch (\Throwable) {
            Log::warning("IBKR reported an unusable timezone for contract {$conid}: {$timezone}");

            return null;
        }

        // A schedule that parsed and contains no sessions means genuinely closed, which is a
        // different answer from one that could not be read at all. Only an unrecognisable
        // string counts as unknown.
        if (! preg_match('/\d{8}:/', $hours)) {
            Log::warning("IBKR reported trading hours in an unrecognised shape for contract {$conid}: {$hours}");

            return null;
        }

        return ['timezone' => $timezone, 'sessions' => $this->parseSessions($hours, $timezone)];
    }

    /**
     * IBKR returns days separated by semicolons, each either CLOSED or one or more comma
     * separated ranges: 20260810:0400-20260810:2000;20260811:CLOSED
     *
     * @return array<int, array<int, string>>
     */
    private function parseSessions(string $hours, string $timezone): array
    {
        $sessions = [];

        foreach (explode(';', $hours) as $day) {
            $day = trim($day);

            if ($day === '' || str_contains($day, 'CLOSED')) {
                continue;
            }

            [$date, $ranges] = array_pad(explode(':', $day, 2), 2, '');

            if (! preg_match('/^\d{8}$/', $date) || $ranges === '') {
                continue;
            }

            foreach (explode(',', $ranges) as $range) {
                $parsed = $this->parseRange($date, trim($range), $timezone);

                if ($parsed !== null) {
                    $sessions[] = $parsed;
                }
            }
        }

        return $sessions;
    }

    /**
     * @return array<int, string>|null
     */
    private function parseRange(string $date, string $range, string $timezone): ?array
    {
        if (! preg_match('/^(?:(\d{8}):)?(\d{4})-(?:(\d{8}):)?(\d{4})$/', $range, $m)) {
            return null;
        }

        $opensDate = $m[1] !== '' ? $m[1] : $date;
        $closesDate = $m[3] !== '' ? $m[3] : $date;

        try {
            $opens = CarbonImmutable::createFromFormat('YmdHi', $opensDate.$m[2], $timezone);
            $closes = CarbonImmutable::createFromFormat('YmdHi', $closesDate.$m[4], $timezone);
        } catch (\Throwable) {
            return null;
        }

        if ($opens === null || $closes === null) {
            return null;
        }

        // A session that ends before it starts runs past midnight.
        if ($closes->isBefore($opens)) {
            $closes = $closes->addDay();
        }

        return [$opens->toIso8601String(), $closes->toIso8601String()];
    }
}
