<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\StoreCatalog\CatalogExchangeReader;
use App\Services\StoreCatalog\CatalogSheet;
use App\Services\StoreCatalog\CatalogTransfer;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class StoreCatalogTransferController extends Controller
{
    public function __construct(private readonly CatalogTransfer $transfer) {}

    public function preview(Request $request, Store $store)
    {
        $request->validate(['file' => ['required', 'file', 'extensions:json,xlsx', 'max:8192']]);

        return response()->json(['data' => $this->transfer->preview($request->file('file'), $store, $request->user())]);
    }

    public function import(Request $request, Store $store)
    {
        $request->validate(['file' => ['required', 'file', 'extensions:json,xlsx', 'max:8192'], 'idempotency_key' => ['required', 'uuid']]);

        return response()->json(['meta' => $this->transfer->import($request->file('file'), $request->input('idempotency_key'), $store, $request->user())], 201);
    }

    public function template()
    {
        $path = tempnam(sys_get_temp_dir(), 'catalog-template-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues(CatalogExchangeReader::HEADERS));
            $writer->addRow(Row::fromValues(['sample-bag', 'حقيبة تجريبية — احذف هذا الصف', 300, 250, 'SAMPLE-001', 'حقائب, هدايا', '', '', 10, 1, 1,
                '<p>وصف المنتج</p>', 'وصف مختصر لمحركات البحث', 'المقاس: صغير, كبير', 'اللون(color): أزرق=#0000ff, أسود=#000000', '', '', '', '']));
            $writer->close();

            return response()->download($path, 'sellchaze-products-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
        } catch (\Throwable $error) {
            @unlink($path);
            throw $error;
        }
    }

    /** Flat XLSX/CSV sheet with one row per option, for editing and re-importing. */
    public function sheetExport(Request $request, Store $store, CatalogSheet $sheet)
    {
        $data = $request->validate(['format' => ['required', 'in:xlsx,csv'], 'product_ids' => ['sometimes', 'array', 'list', 'min:1', 'max:'.CatalogSheet::MAX_EXPORT_ROWS],
            'product_ids.*' => ['integer', 'min:1', 'distinct'], 'category_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        [$path, $name] = $sheet->export($data['product_ids'] ?? null, $data['category_id'] ?? null, $data['format']);

        return response()->download($path, $name, ['Content-Type' => $data['format'] === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend();
    }

    /** Dry run of an XLSX/CSV sheet. The route's permission depends on the mode (update or create). */
    public function sheetPreview(Request $request, Store $store, string $mode)
    {
        $request->validate(['file' => ['required', 'file', 'extensions:csv,xlsx', 'max:8192']]);

        return response()->json(['data' => $this->transfer->sheetPreview($request->file('file'), $mode, $store, $request->user())], 200, ['Cache-Control' => 'private, no-store'], JSON_UNESCAPED_UNICODE);
    }

    public function sheetImport(Request $request, Store $store, string $mode)
    {
        $request->validate(['file' => ['required', 'file', 'extensions:csv,xlsx', 'max:8192'], 'idempotency_key' => ['required', 'uuid']]);

        return response()->json(['meta' => $this->transfer->sheetImport($request->file('file'), $mode, $request->input('idempotency_key'), $store, $request->user())], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function export(Request $request, Store $store)
    {
        $data = $request->validate($this->selectionRules());

        return response()->json(['format' => 'sellchaze-catalog-v1', 'currency' => $store->currency,
            'notice' => 'Available stock only. Digital products export as inactive drafts without delivery links or code pools. Link products require a private delivery URL before importing; code products require replenishment before publishing.',
            'products' => $this->transfer->export($data['products'])], 200, ['Cache-Control' => 'private, no-store'], JSON_UNESCAPED_UNICODE);
    }

    public function duplicate(Request $request, Store $store, int $product)
    {
        $data = $request->validate(['version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'idempotency_key' => ['required', 'uuid']]);

        return response()->json(['meta' => $this->transfer->duplicate(['id' => $product, 'version' => $data['version']], $data['idempotency_key'], $store, $request->user())], 201);
    }

    public function delete(Request $request, Store $store)
    {
        $data = $request->validate($this->selectionRules() + ['idempotency_key' => ['required', 'uuid']]);

        return response()->json(['meta' => $this->transfer->delete($data['products'], $data['idempotency_key'], $store, $request->user())]);
    }

    private function selectionRules(): array
    {
        return ['products' => ['required', 'array', 'list', 'min:1', 'max:200'], 'products.*' => ['array:id,version'],
            'products.*.id' => ['required', 'integer', 'min:1', 'distinct'], 'products.*.version' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']];
    }
}
