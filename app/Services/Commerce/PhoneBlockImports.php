<?php

namespace App\Services\Commerce;

use App\Models\Store;
use App\Models\StorePhoneBlock;
use App\Models\StorePhoneBlockImport;
use App\Models\User;
use App\Support\XlsxArchiveGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bulk blocked-phone imports. The upload is streamed to a private (non-public) path and read back with
 * streaming readers, so memory stays flat for 20 MiB files. It is processed in the background in
 * chunks: each chunk normalizes numbers with the store's phone country, skips duplicates and existing
 * entries, inserts new blocks with their audit events, and records progress in the same transaction,
 * so an interrupted import resumes exactly where it stopped.
 */
class PhoneBlockImports
{
    public const MAX_ROWS = 500000;

    public const MAX_KILOBYTES = 20480;

    private const CHUNK = 500;

    /** Rows one scheduler tick processes per import before yielding to other stores. */
    public const ROWS_PER_TICK = 50000;

    private const MAX_EXPANDED_BYTES = 256 * 1024 * 1024;

    public function __construct(private readonly int $maxRows = self::MAX_ROWS, private readonly int $rowsPerTick = self::ROWS_PER_TICK) {}

    private const SAMPLES = 50;

    private const HEADERS = ['phone', 'phones', 'mobile', 'number', 'phone number', 'phone_number', 'telephone', 'tel', 'رقم', 'الرقم', 'رقم الهاتف', 'الهاتف', 'هاتف', 'موبايل', 'الموبايل', 'جوال', 'الجوال'];

    private const NOTE_HEADERS = ['note', 'notes', 'reason', 'ملاحظة', 'ملاحظات', 'السبب'];

    public function request(Store $store, User $actor, UploadedFile $file, string $scope, string $key): StorePhoneBlockImport
    {
        $format = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
        if ($format === 'xlsx' && ($problem = XlsxArchiveGuard::problem($file->getRealPath(), self::MAX_EXPANDED_BYTES))) {
            throw ValidationException::withMessages(['file' => $problem]);
        }

        return DB::transaction(function () use ($store, $actor, $file, $scope, $key, $format) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $existing = StorePhoneBlockImport::query()->where('store_id', $store->id)->where('request_key', $key)->first();
            if ($existing) {
                abort_unless($existing->scope === $scope, 409, 'This request key was already used for a different list.');

                return $existing;
            }
            abort_if(StorePhoneBlockImport::query()->where('store_id', $store->id)->where('scope', $scope)->whereIn('status', ['queued', 'processing'])->exists(),
                409, 'Another import of this list is still running. Wait for it to finish before uploading a new file.');
            $id = (string) Str::uuid();
            // Streamed copy: the file is never loaded into memory as one string.
            $path = Storage::disk('local')->putFileAs('store-phone-imports', $file, $id.'.'.$format, 'private');
            if ($path === false) {
                abort(500, 'The file could not be stored. Try again.');
            }
            DB::afterRollBack(fn () => Storage::disk('local')->delete($path));

            return StorePhoneBlockImport::create(['id' => $id, 'store_id' => $store->id, 'user_id' => $actor->id, 'request_key' => $key, 'scope' => $scope,
                'status' => 'queued', 'file_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'file_path' => $path, 'format' => $format]);
        });
    }

    public function present(StorePhoneBlockImport $row): array
    {
        return ['id' => $row->id, 'status' => $row->status, 'scope' => $row->scope, 'file_name' => $row->file_name, 'total_rows' => $row->total_rows,
            'processed_rows' => $row->processed_rows, 'imported' => $row->imported, 'duplicates' => $row->duplicates, 'invalid' => $row->invalid,
            'skipped_inactive' => $row->skipped_inactive, 'invalid_samples' => $row->invalid_samples ?? [], 'error_code' => $row->error_code,
            'created_at' => $row->created_at, 'completed_at' => $row->completed_at];
    }

    /**
     * Scheduler entry point: one import per store per tick (round-robin, oldest waiting store first),
     * each bounded to ROWS_PER_TICK rows, so a large import never starves other stores.
     */
    public function process(int $limit = 3): int
    {
        $ids = StorePhoneBlockImport::query()->where(fn ($q) => $q->where('status', 'queued')
            ->orWhere(fn ($q) => $q->where('status', 'processing')->where('lease_until', '<=', now())))
            ->orderBy('updated_at')->orderBy('processed_rows')->orderBy('created_at')->limit(500)->get(['id', 'store_id'])
            ->unique('store_id')->take(max(1, min(10, $limit)))->pluck('id');
        $processed = 0;
        foreach ($ids as $id) {
            $processed += (int) $this->run((string) $id, $this->rowsPerTick);
        }

        return $processed;
    }

    /** Processes up to $budget rows; an unfinished import is queued again for the next tick. */
    public function run(string $id, int $budget = PHP_INT_MAX): bool
    {
        $claim = DB::transaction(function () use ($id) {
            $row = StorePhoneBlockImport::query()->whereKey($id)->lockForUpdate()->first();
            if (! $row || ! in_array($row->status, ['queued', 'processing'], true) || ($row->status === 'processing' && $row->lease_until?->isFuture())) {
                return null;
            }
            if ($row->attempts >= 5) {
                $this->deleteFile($row);
                $row->update(['status' => 'failed', 'error_code' => 'attempt_limit', 'file_path' => null, 'lease_token' => null, 'lease_until' => null]);

                return null;
            }
            $row->update(['status' => 'processing', 'lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinutes(5), 'attempts' => $row->attempts + 1]);

            return $row;
        });
        if (! $claim) {
            return false;
        }
        try {
            $store = Store::query()->find($claim->store_id);
            $actor = $claim->user_id ? User::query()->find($claim->user_id) : null;
            if (! $store || ! $actor || ! ((int) $store->owner_user_id === (int) $actor->id || $actor->can('store.orders.manage') || $actor->can('stores-edit'))) {
                return $this->finish($claim, ['status' => 'failed', 'error_code' => 'access_revoked']);
            }
            $path = Storage::disk('local')->path((string) $claim->file_path);
            if (! is_file($path) || ($claim->format === 'xlsx' && XlsxArchiveGuard::problem($path, self::MAX_EXPANDED_BYTES))) {
                return $this->finish($claim, ['status' => 'failed', 'error_code' => 'processing_failed']);
            }
            $country = app(OrderLimits::class)->configured($store)['phone_country'];
            $columns = $this->columns($path, $claim->format);
            if ($claim->total_rows === null) {
                // Every physical row counts against the cap, blank ones included; the lease is renewed while counting.
                $raw = $total = 0;
                foreach ($this->records($path, $claim->format, true) as $values) {
                    $raw++;
                    $total += (int) ($values !== null);
                    if ($raw > $this->maxRows + 1) {
                        return $this->finish($claim, ['status' => 'failed', 'error_code' => 'too_many_rows']);
                    }
                    if ($raw % 5000 === 0 && ! $this->renew($claim)) {
                        return false;
                    }
                }
                $total -= $columns['header'] ? 1 : 0;
                $claim->total_rows = $total;
                StorePhoneBlockImport::query()->whereKey($claim->id)->where('lease_token', $claim->lease_token)->update(['total_rows' => $total, 'lease_until' => now()->addMinutes(5)]);
            }
            $skip = $claim->processed_rows + ($columns['header'] ? 1 : 0);
            $chunk = [];
            $index = $done = 0;
            foreach ($this->records($path, $claim->format) as $values) {
                if ($index++ < $skip) {
                    continue;
                }
                if ($done >= $budget) {
                    return $this->release($claim);
                }
                $chunk[] = ['row' => $index, 'phone' => $this->text($values[$columns['phone']] ?? null), 'note' => $columns['note'] === null ? null : $this->text($values[$columns['note']] ?? null)];
                $done++;
                if (count($chunk) >= self::CHUNK) {
                    if (! $this->chunk($claim, $store, $actor, $country, $chunk)) {
                        return false;
                    }
                    $chunk = [];
                }
            }
            if ($chunk !== [] && ! $this->chunk($claim, $store, $actor, $country, $chunk)) {
                return false;
            }

            return $this->finish($claim, ['status' => 'completed', 'completed_at' => now()]);
        } catch (\Throwable $error) {
            report($error);

            return $this->finish($claim, ['status' => 'failed', 'error_code' => 'processing_failed']);
        }
    }

    private function renew(StorePhoneBlockImport $claim): bool
    {
        return StorePhoneBlockImport::query()->whereKey($claim->id)->where('status', 'processing')->where('lease_token', $claim->lease_token)
            ->update(['lease_until' => now()->addMinutes(5)]) === 1;
    }

    /** Hands an unfinished import back to the queue; progress already committed is kept and attempts reset. */
    private function release(StorePhoneBlockImport $claim): bool
    {
        return StorePhoneBlockImport::query()->whereKey($claim->id)->where('status', 'processing')->where('lease_token', $claim->lease_token)
            ->update(['status' => 'queued', 'lease_token' => null, 'lease_until' => null, 'attempts' => 0, 'updated_at' => now()]) === 1;
    }

    /** One chunk under the store lock, which checkout also takes, so a number is never half-blocked. */
    private function chunk(StorePhoneBlockImport $claim, Store $store, User $actor, string $country, array $rows): bool
    {
        return DB::transaction(function () use ($claim, $store, $actor, $country, $rows) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $current = StorePhoneBlockImport::query()->whereKey($claim->id)->where('status', 'processing')->where('lease_token', $claim->lease_token)->lockForUpdate()->first();
            if (! $current) {
                return false;
            }
            $limits = app(OrderLimits::class);
            $valid = [];
            $invalid = $duplicates = 0;
            $samples = $current->invalid_samples ?? [];
            foreach ($rows as $row) {
                $phone = $row['phone'] === '' ? null : $limits->normalizePhone(mb_substr($row['phone'], 0, 50), $country);
                if ($phone === null) {
                    if ($row['phone'] !== '' || $row['note'] !== null) {
                        $invalid++;
                        if (count($samples) < self::SAMPLES) {
                            $samples[] = ['row' => $row['row'], 'value' => mb_substr($row['phone'], 0, 50)];
                        }
                    }

                    continue;
                }
                if (isset($valid[$phone])) {
                    $duplicates++;

                    continue;
                }
                $valid[$phone] = $row;
            }
            $existing = $valid === [] ? collect() : StorePhoneBlock::withoutGlobalScopes()->where('store_id', $store->id)->where('scope', $current->scope)
                ->whereIn('phone_normalized', array_map('strval', array_keys($valid)))->pluck('active', 'phone_normalized');
            $inactive = $existing->filter(fn ($active) => ! $active)->count();
            $duplicates += $existing->count() - $inactive;
            $new = array_diff_key($valid, $existing->all());
            if ($new !== []) {
                $now = now();
                StorePhoneBlock::withoutGlobalScopes()->insert(array_map(fn ($phone, $row) => ['store_id' => $store->id, 'scope' => $current->scope,
                    'phone' => mb_substr($row['phone'], 0, 50), 'phone_normalized' => (string) $phone, 'phone_country' => $country, 'note' => $row['note'],
                    'active' => true, 'version' => 1, 'created_at' => $now, 'updated_at' => $now], array_keys($new), $new));
                $ids = StorePhoneBlock::withoutGlobalScopes()->where('store_id', $store->id)->where('scope', $current->scope)
                    ->whereIn('phone_normalized', array_map('strval', array_keys($new)))->pluck('id', 'phone_normalized');
                DB::table('store_phone_block_events')->insert($ids->map(fn ($id, $phone) => ['store_id' => $store->id, 'store_phone_block_id' => $id,
                    'actor_id' => $actor->id, 'action' => 'blocked', 'note' => $new[$phone]['note'] ?? null, 'version' => 1, 'created_at' => $now, 'updated_at' => $now])->values()->all());
            }
            $current->update(['processed_rows' => $current->processed_rows + count($rows), 'imported' => $current->imported + count($new),
                'duplicates' => $current->duplicates + $duplicates, 'invalid' => $current->invalid + $invalid, 'skipped_inactive' => $current->skipped_inactive + $inactive,
                'invalid_samples' => $samples, 'lease_until' => now()->addMinutes(5)]);
            $claim->processed_rows = $current->processed_rows;

            return true;
        });
    }

    /** @return array{header: bool, phone: int, note: ?int} */
    private function columns(string $path, string $format): array
    {
        foreach ($this->records($path, $format) as $values) {
            $labels = array_map(fn ($value) => mb_strtolower(trim(str_replace("\u{FEFF}", '', $this->text($value)))), $values);
            $phone = array_search(true, array_map(fn ($label) => in_array($label, self::HEADERS, true), $labels), true);
            if ($phone === false) {
                return ['header' => false, 'phone' => 0, 'note' => null];
            }
            $note = array_search(true, array_map(fn ($label) => in_array($label, self::NOTE_HEADERS, true), $labels), true);

            return ['header' => true, 'phone' => (int) $phone, 'note' => $note === false ? null : (int) $note];
        }

        return ['header' => false, 'phone' => 0, 'note' => null];
    }

    /**
     * Streams rows without loading the file into memory. Blank rows are skipped, or yielded as null when
     * $blank is true (for counting). @return \Generator<int, list<mixed>|null>
     */
    private function records(string $path, string $format, bool $blank = false): \Generator
    {
        if ($format === 'xlsx') {
            $reader = new Reader;
            $reader->open($path);
            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    foreach ($sheet->getRowIterator() as $row) {
                        $values = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
                        if (array_filter($values, fn ($value) => $this->text($value) !== '') !== []) {
                            yield $values;
                        } elseif ($blank) {
                            yield null;
                        }
                    }
                    break;
                }
            } finally {
                $reader->close();
            }

            return;
        }
        $stream = fopen($path, 'r');
        try {
            $first = (string) fgets($stream);
            rewind($stream);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
            while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                if (array_filter($values, fn ($value) => $this->text($value) !== '') !== []) {
                    yield $values;
                } elseif ($blank) {
                    yield null;
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private function text(mixed $value): string
    {
        if (is_float($value)) {
            $value = number_format($value, 0, '.', '');
        }
        $text = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', (string) ($value ?? '')));

        return mb_check_encoding($text, 'UTF-8') ? mb_substr($text, 0, 500) : '';
    }

    private function finish(StorePhoneBlockImport $claim, array $values): bool
    {
        return DB::transaction(function () use ($claim, $values) {
            $current = StorePhoneBlockImport::query()->whereKey($claim->id)->where('status', 'processing')->where('lease_token', $claim->lease_token)->lockForUpdate()->first();
            if (! $current) {
                return false;
            }
            $this->deleteFile($current);
            $current->update($values + ['file_path' => null, 'lease_token' => null, 'lease_until' => null]);

            return true;
        });
    }

    private function deleteFile(StorePhoneBlockImport $row): void
    {
        if ($row->file_path) {
            Storage::disk('local')->delete($row->file_path);
        }
    }

    public function export(Store $store, string $scope, string $status): StreamedResponse
    {
        return response()->streamDownload(function () use ($store, $scope, $status) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['phone', 'phone_normalized', 'status', 'note', 'created_at', 'updated_at'], ',', '"', '');
            StorePhoneBlock::withoutGlobalScopes()->where('store_id', $store->id)->where('scope', $scope)
                ->when($status !== 'all', fn ($q) => $q->where('active', $status === 'active'))
                ->lazyById(1000)->each(function (StorePhoneBlock $block) use ($stream) {
                    fputcsv($stream, array_map(StoreOrderExports::cell(...), [$block->phone, $block->phone_normalized, $block->active ? 'blocked' : 'unblocked',
                        $block->note, $block->created_at?->toIso8601String(), $block->updated_at?->toIso8601String()]), ',', '"', '');
                });
            fclose($stream);
        }, 'blocked-phones-'.$scope.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
