<?php

namespace App\Services\Commerce;

use App\Models\Store;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Read-only, pinned reporting equivalents. Never changes order or catalogue snapshots. */
class StoreReportingMoney
{
    public readonly string $currency;

    private array $rates;

    private ?array $policy = null;

    private bool $invalid = false;

    public function __construct(Store $store)
    {
        $this->currency = strtoupper($store->currency ?: 'USD');
        $this->rates = preg_match('/^[A-Z]{3}$/D', $this->currency) ? [$this->currency => '1'] : [];
        $raw = $store->theme_settings['reporting_money_policy'] ?? null;
        if ($raw !== null) {
            try {
                $this->policy = self::validatePolicy($raw, $this->currency);
                $this->rates = $this->policy['rates'];
            } catch (InvalidArgumentException) {
                // Bad policy is visible and cannot silently introduce an FX assumption.
                $this->invalid = true;
                $this->rates = [];
            }
        }
    }

    public static function validatePolicy(mixed $policy, string $currency): array
    {
        if (! is_array($policy) || ($policy['reporting_currency'] ?? null) !== $currency
            || ! is_string($policy['version'] ?? null) || ! preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $policy['version'])
            || ! is_string($policy['source_url'] ?? null) || ! filter_var($policy['source_url'], FILTER_VALIDATE_URL)
            || ! is_string($policy['effective_date'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $policy['effective_date'])
            || ! is_array($policy['rates'] ?? null) || count($policy['rates']) > 100) {
            throw new InvalidArgumentException('Invalid reporting money policy.');
        }
        [$year, $month, $day] = array_map('intval', explode('-', $policy['effective_date']));
        if (! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('Invalid reporting rate date.');
        }
        foreach ($policy['rates'] as $code => $rate) {
            if (! preg_match('/^[A-Z]{3}$/D', (string) $code) || ! is_string($rate)
                || ! preg_match('/^\d{1,12}(?:\.\d{1,12})?$/D', $rate) || bccomp($rate, '0', 12) <= 0) {
                throw new InvalidArgumentException('Reporting rates must be positive decimal strings.');
            }
        }
        if (! isset($policy['rates'][$currency]) || bccomp($policy['rates'][$currency], '1', 12) !== 0) {
            throw new InvalidArgumentException('Reporting currency rate must be exactly one.');
        }

        return array_intersect_key($policy, array_flip(['version', 'reporting_currency', 'rates', 'source_url', 'effective_date']));
    }

    /** SQL identifiers/expressions are internal constants. Only validated decimal policy values enter SQL. */
    public function expression(string $amount = 'grand_total', string $currency = 'currency'): string
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        if ($sqlite) {
            // SQLite DECIMAL has float affinity. Keep the rounding boundary in BC Math.
            DB::connection()->getPdo()->sqliteCreateFunction('sellchaze_reporting_money', self::convert(...), 2);
        }
        $cases = [];
        foreach ($this->rates as $code => $rate) {
            $value = $sqlite ? "CAST(sellchaze_reporting_money(($amount), '$rate') AS NUMERIC)" : "ROUND(CAST(($amount) AS DECIMAL(30,12)) * CAST('$rate' AS DECIMAL(24,12)), 2)";
            $cases[] = "WHEN '$code' THEN $value";
        }

        return $cases ? '(CASE '.$currency.' '.implode(' ', $cases).' ELSE NULL END)' : 'NULL';
    }

    public static function convert(mixed $amount, string $rate): ?string
    {
        if ($amount === null) {
            return null;
        }
        $product = bcmul((string) $amount, $rate, 24);

        return bcadd($product, bccomp($product, '0', 24) < 0 ? '-0.005' : '0.005', 2);
    }

    /** An unknown rate makes the whole equivalent unavailable; known buckets are still returned. */
    public function sum(string $amount = 'grand_total', string $currency = 'currency', string $condition = '1=1'): string
    {
        $value = $this->expression($amount, $currency);

        return "CASE WHEN SUM(CASE WHEN ($condition) AND $value IS NULL THEN 1 ELSE 0 END)>0 THEN NULL ELSE COALESCE(SUM(CASE WHEN ($condition) THEN $value ELSE 0 END),0) END";
    }

    public static function decimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // PDO SQLite exposes numeric aggregates as doubles; production MySQL DECIMAL stays a string.
        return is_float($value) ? number_format($value, 2, '.', '') : bcadd((string) $value, '0', 2);
    }

    public static function average(?string $value, int $count): ?string
    {
        return $value === null ? null : ($count ? self::convert(bcdiv($value, (string) $count, 24), '1') : '0.00');
    }

    public function metadata(): array
    {
        return ['reporting_currency' => $this->currency, 'equivalent' => $this->policy !== null,
            'policy' => $this->policy, 'policy_valid' => ! $this->invalid,
            'rounding' => 'HALF_UP_2_PER_RECORD'];
    }

    /** Clone a scoped, ungrouped query. Grouped reports are fetched in one extra query, never per row. */
    public function reports($query, string $amount = 'grand_total', string $currency = 'currency', array $groups = []): array
    {
        $query = clone $query;
        $groupAliases = array_map(fn ($column) => $column instanceof Expression ? $column->getValue(DB::connection()->getQueryGrammar()) : (string) $column, $groups);
        $groupNames = array_map(fn ($column) => str_contains($column, ' as ') ? substr($column, strrpos($column, ' as ') + 4) : $column, $groupAliases);
        $query = $query->reorder()->select($groups)->selectRaw("$currency as original_currency, COUNT(*) as record_count, SUM($amount) as original_total, ".$this->sum($amount, $currency).' as base_total')
            ->groupBy([...$groupNames, $currency]);
        $reports = [];
        foreach ($query->get() as $row) {
            $key = implode('|', array_map(fn ($column) => (string) $row->{str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column}, $groupNames));
            $report = $reports[$key] ?? $this->empty();
            $code = (string) ($row->original_currency ?? 'UNKNOWN');
            $count = (int) $row->record_count;
            $total = self::decimal($row->original_total);
            $report['totals_by_currency'][$code] = ['total' => $total, 'count' => $count, 'average' => self::average($total, $count)];
            $report['count'] += $count;
            $report['base_equivalent'] = $report['base_equivalent'] === null || $row->base_total === null ? null : bcadd($report['base_equivalent'], self::decimal($row->base_total), 2);
            $report['complete'] = $report['base_equivalent'] !== null;
            $report['average_base_equivalent'] = self::average($report['base_equivalent'], $report['count']);
            $reports[$key] = $report;
        }

        return $reports;
    }

    public function report($query, string $amount = 'grand_total', string $currency = 'currency'): array
    {
        return $this->reports($query, $amount, $currency)[''] ?? $this->empty();
    }

    public function empty(): array
    {
        return $this->metadata() + ['totals_by_currency' => [], 'count' => 0, 'base_equivalent' => '0.00', 'average_base_equivalent' => '0.00', 'complete' => true];
    }
}
