<?php

namespace App\Http\Controllers\Api\Wavex;

use App\Http\Controllers\Controller;
use App\Models\WavexContactGroup;
use App\Models\WavexContactGroupMember;
use App\Services\Wavex\WawpClient;
use App\Support\Wavex\WavexContactSheetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WavexContactGroupsApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));

        $q = WavexContactGroup::query()
            ->where('user_id', $request->user()->id)
            ->withCount('members')
            ->latest();

        return response()->json($q->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'whatsapp_group_id' => ['nullable', 'string', 'max:256'],
            'import_all_whatsapp_contacts' => ['sometimes', 'boolean'],
            'locale' => ['nullable', 'string', 'max:32'],
            'arabic_numerals' => ['sometimes', 'boolean'],
            'anonymous_prefix' => ['nullable', 'string', 'max:64'],
        ]);

        $importAll = $request->boolean('import_all_whatsapp_contacts');
        $hasGroupId = ! empty($data['whatsapp_group_id']);
        if ($importAll && $hasGroupId) {
            throw ValidationException::withMessages([
                'whatsapp_group_id' => [
                    'Choose either a WhatsApp group or import all contacts, not both.',
                ],
            ]);
        }

        $group = WavexContactGroup::query()->create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        $options = [
            'locale' => $data['locale'] ?? null,
            'arabic_numerals' => $data['arabic_numerals'] ?? null,
            'anonymous_prefix' => $data['anonymous_prefix'] ?? null,
        ];

        $waImport = null;
        if ($importAll) {
            $waImport = $this->importAllWhatsappContacts($group, $options);
        } elseif ($hasGroupId) {
            $waImport = $this->importParticipantsFromWhatsapp(
                $group,
                trim((string) $data['whatsapp_group_id']),
                $options
            );
        }

        $payload = ['data' => $group->fresh()->loadCount('members')];
        if ($waImport !== null) {
            $payload['whatsapp_import'] = $waImport;
        }

        return response()->json($payload, 201);
    }

    public function show(Request $request, WavexContactGroup $wavexContactGroup): JsonResponse
    {
        $this->authorizeGroup($request, $wavexContactGroup);

        return response()->json([
            'data' => $wavexContactGroup->loadCount('members'),
        ]);
    }

    public function update(Request $request, WavexContactGroup $wavexContactGroup): JsonResponse
    {
        $this->authorizeGroup($request, $wavexContactGroup);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $wavexContactGroup->fill($data);
        $wavexContactGroup->save();

        return response()->json(['data' => $wavexContactGroup->fresh()]);
    }

    public function destroy(Request $request, WavexContactGroup $wavexContactGroup): JsonResponse
    {
        $this->authorizeGroup($request, $wavexContactGroup);
        $wavexContactGroup->delete();

        return response()->json(['message' => 'OK']);
    }

    public function membersIndex(Request $request, WavexContactGroup $wavexContactGroup): JsonResponse
    {
        $this->authorizeGroup($request, $wavexContactGroup);

        return response()->json(
            $wavexContactGroup->members()->paginate(500)
        );
    }

    public function membersStore(Request $request, WavexContactGroup $wavexContactGroup): JsonResponse
    {
        $this->authorizeGroup($request, $wavexContactGroup);

        $data = $request->validate([
            'members' => ['required', 'array', 'min:1'],
            'members.*.phone' => ['required', 'string', 'max:32'],
            'members.*.display_name' => ['nullable', 'string', 'max:255'],
        ]);

        $maxOrder = (int) $wavexContactGroup->members()->max('sort_order');

        $added = 0;
        DB::transaction(function () use ($wavexContactGroup, $data, &$maxOrder, &$added) {
            foreach ($data['members'] as $row) {
                $phone = trim($row['phone']);
                if ($phone === '') {
                    continue;
                }
                $exists = WavexContactGroupMember::query()
                    ->where('contact_group_id', $wavexContactGroup->id)
                    ->where('phone', $phone)
                    ->exists();
                if ($exists) {
                    continue;
                }
                WavexContactGroupMember::query()->create([
                    'contact_group_id' => $wavexContactGroup->id,
                    'phone' => $phone,
                    'display_name' => isset($row['display_name']) ? trim((string) $row['display_name']) ?: null : null,
                    'sort_order' => ++$maxOrder,
                ]);
                $added++;
            }
        });

        return response()->json(['message' => 'OK', 'added' => $added]);
    }

    public function membersDestroy(
        Request $request,
        WavexContactGroup $wavexContactGroup,
        WavexContactGroupMember $wavexContactGroupMember
    ): JsonResponse {
        $this->authorizeGroup($request, $wavexContactGroup);
        if ((int) $wavexContactGroupMember->contact_group_id !== (int) $wavexContactGroup->id) {
            abort(404);
        }
        $wavexContactGroupMember->delete();

        return response()->json(['message' => 'OK']);
    }

    public function import(Request $request, WavexContactGroup $wavexContactGroup): JsonResponse
    {
        $this->authorizeGroup($request, $wavexContactGroup);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath();
        if ($path === false) {
            return response()->json(['message' => 'Could not read file.'], 400);
        }

        $ext = $file->getClientOriginalExtension();

        $maxOrder = (int) $wavexContactGroup->members()->max('sort_order');
        $imported = 0;
        $skipped = 0;

        foreach (WavexContactSheetReader::iterateRows($path, $ext) as $row) {
            $phone = $row['phone'];
            $exists = WavexContactGroupMember::query()
                ->where('contact_group_id', $wavexContactGroup->id)
                ->where('phone', $phone)
                ->exists();
            if ($exists) {
                $skipped++;

                continue;
            }
            WavexContactGroupMember::query()->create([
                'contact_group_id' => $wavexContactGroup->id,
                'phone' => $phone,
                'display_name' => $row['display_name'],
                'sort_order' => ++$maxOrder,
            ]);
            $imported++;
        }

        return response()->json([
            'message' => 'OK',
            'imported' => $imported,
            'skipped_duplicates' => $skipped,
        ]);
    }

    public function importWhatsapp(Request $request, WavexContactGroup $wavexContactGroup): JsonResponse
    {
        $this->authorizeGroup($request, $wavexContactGroup);

        $data = $request->validate([
            'whatsapp_group_id' => ['required', 'string', 'max:256'],
            'locale' => ['nullable', 'string', 'max:32'],
            'arabic_numerals' => ['sometimes', 'boolean'],
            'anonymous_prefix' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->importParticipantsFromWhatsapp(
            $wavexContactGroup,
            trim($data['whatsapp_group_id']),
            [
                'locale' => $data['locale'] ?? null,
                'arabic_numerals' => $data['arabic_numerals'] ?? null,
                'anonymous_prefix' => $data['anonymous_prefix'] ?? null,
            ]
        );

        $status = ($result['ok'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }

    public function importWhatsappContacts(Request $request, WavexContactGroup $wavexContactGroup): JsonResponse
    {
        $this->authorizeGroup($request, $wavexContactGroup);

        $data = $request->validate([
            'locale' => ['nullable', 'string', 'max:32'],
            'arabic_numerals' => ['sometimes', 'boolean'],
            'anonymous_prefix' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $this->importAllWhatsappContacts(
            $wavexContactGroup,
            [
                'locale' => $data['locale'] ?? null,
                'arabic_numerals' => $data['arabic_numerals'] ?? null,
                'anonymous_prefix' => $data['anonymous_prefix'] ?? null,
            ]
        );

        $status = ($result['ok'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }

    public function export(Request $request, WavexContactGroup $wavexContactGroup): StreamedResponse
    {
        $this->authorizeGroup($request, $wavexContactGroup);

        $format = strtolower((string) $request->query('format', 'xlsx'));
        if (! in_array($format, ['xlsx', 'csv'], true)) {
            $format = 'xlsx';
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $wavexContactGroup->name) ?: 'contacts';
        $filename = $safeName.'_'.now()->format('Y-m-d').'.'.$format;

        $members = $wavexContactGroup->members()->orderBy('sort_order')->orderBy('id')->get();

        return response()->streamDownload(function () use ($format, $members) {
            if ($format === 'csv') {
                $writer = new CsvWriter;
            } else {
                $writer = new XlsxWriter;
            }
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(['phone', 'display_name']));
            foreach ($members as $m) {
                $writer->addRow(Row::fromValues([
                    $m->phone,
                    $m->display_name ?? '',
                ]));
            }
            $writer->close();
        }, $filename, [
            'Content-Type' => $format === 'csv'
                ? 'text/csv; charset=UTF-8'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $deleted = WavexContactGroup::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $data['ids'])
            ->delete();

        return response()->json(['message' => 'OK', 'deleted' => $deleted]);
    }

    private function authorizeGroup(Request $request, WavexContactGroup $wavexContactGroup): void
    {
        if ((int) $wavexContactGroup->user_id !== (int) $request->user()->id) {
            abort(403);
        }
    }

    /**
     * @param  array{locale?: string|null, arabic_numerals?: bool|null, anonymous_prefix?: string|null}  $options
     * @return array{ok: bool, imported?: int, skipped_duplicates?: int, message?: string}
     */
    private function importParticipantsFromWhatsapp(
        WavexContactGroup $group,
        string $whatsappGroupId,
        array $options
    ): array {
        $client = WawpClient::fromSettings();
        $res = $client->getGroupParticipants($whatsappGroupId);
        if (! ($res['ok'] ?? false)) {
            $msg = 'Could not load WhatsApp group members.';
            $payload = $res['data'] ?? null;
            if (is_array($payload) && isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
                $msg = $payload['message'];
            }

            return ['ok' => false, 'message' => $msg];
        }

        $list = $this->normalizeWawpParticipantsList($res['data']);
        $locale = strtolower(trim((string) ($options['locale'] ?? '')));
        $useAr = str_starts_with($locale, 'ar');
        $arabicNumerals = array_key_exists('arabic_numerals', $options) && $options['arabic_numerals'] !== null
            ? (bool) $options['arabic_numerals']
            : $useAr;
        $prefix = isset($options['anonymous_prefix']) && is_string($options['anonymous_prefix']) && trim($options['anonymous_prefix']) !== ''
            ? trim($options['anonymous_prefix'])
            : ($useAr ? 'عميل' : 'Client');

        $anonymousSeq = 0;
        $imported = 0;
        $skipped = 0;

        DB::transaction(function () use ($group, $list, $prefix, $arabicNumerals, &$anonymousSeq, &$imported, &$skipped) {
            $maxOrder = (int) $group->members()->max('sort_order');

            foreach ($list as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $jid = $this->participantJid($p);
                if ($jid === null || $jid === '') {
                    continue;
                }
                $phone = $this->jidToPhone($jid);
                if ($phone === null || $phone === '') {
                    continue;
                }
                $exists = WavexContactGroupMember::query()
                    ->where('contact_group_id', $group->id)
                    ->where('phone', $phone)
                    ->exists();
                if ($exists) {
                    $skipped++;

                    continue;
                }
                $displayName = $this->participantDisplayName($p);
                if ($displayName === null || $displayName === '') {
                    $anonymousSeq++;
                    $num = $this->formatSequenceNumber($anonymousSeq, $arabicNumerals);
                    $displayName = $prefix.' '.$num;
                }
                WavexContactGroupMember::query()->create([
                    'contact_group_id' => $group->id,
                    'phone' => $phone,
                    'display_name' => $displayName,
                    'sort_order' => ++$maxOrder,
                ]);
                $imported++;
            }
        });

        return [
            'ok' => true,
            'imported' => $imported,
            'skipped_duplicates' => $skipped,
        ];
    }

    /**
     * @param  array{locale?: string|null, arabic_numerals?: bool|null, anonymous_prefix?: string|null}  $options
     * @return array{ok: bool, imported?: int, skipped_duplicates?: int, message?: string}
     */
    private function importAllWhatsappContacts(WavexContactGroup $group, array $options): array
    {
        $client = WawpClient::fromSettings();
        $lidMap = $this->buildWhatsappLidToPhoneMap($client);

        $locale = strtolower(trim((string) ($options['locale'] ?? '')));
        $useAr = str_starts_with($locale, 'ar');
        $arabicNumerals = array_key_exists('arabic_numerals', $options) && $options['arabic_numerals'] !== null
            ? (bool) $options['arabic_numerals']
            : $useAr;
        $prefix = isset($options['anonymous_prefix']) && is_string($options['anonymous_prefix']) && trim($options['anonymous_prefix']) !== ''
            ? trim($options['anonymous_prefix'])
            : ($useAr ? 'عميل' : 'Client');

        $anonymousSeq = 0;
        $imported = 0;
        $skipped = 0;
        $pageSize = 1000;
        $maxPages = 200;
        $failedWawpResponse = null;

        try {
            DB::transaction(function () use (
                $client,
                $group,
                $lidMap,
                $prefix,
                $arabicNumerals,
                $pageSize,
                $maxPages,
                &$anonymousSeq,
                &$imported,
                &$skipped,
                &$failedWawpResponse
            ) {
                $maxOrder = (int) $group->members()->max('sort_order');
                $offset = 0;

                for ($page = 0; $page < $maxPages; $page++) {
                    $res = $client->listAllContacts($pageSize, $offset);
                    if (! ($res['ok'] ?? false)) {
                        $failedWawpResponse = $res;
                        throw new \RuntimeException('wawp_contacts_page_failed');
                    }
                    $list = $this->normalizeWawpParticipantsList($res['data']);
                    if ($list === []) {
                        break;
                    }
                    foreach ($list as $p) {
                        if (! is_array($p)) {
                            continue;
                        }
                        if (array_key_exists('isWAContact', $p) && $p['isWAContact'] === false) {
                            continue;
                        }
                        $jid = $this->participantJid($p);
                        if ($jid === null || $jid === '') {
                            continue;
                        }
                        $phone = $this->resolvePhoneFromWhatsappJid($jid, $lidMap);
                        if ($phone === null || $phone === '') {
                            continue;
                        }
                        $exists = WavexContactGroupMember::query()
                            ->where('contact_group_id', $group->id)
                            ->where('phone', $phone)
                            ->exists();
                        if ($exists) {
                            $skipped++;

                            continue;
                        }
                        $displayName = $this->participantDisplayName($p);
                        if ($displayName === null || $displayName === '') {
                            $anonymousSeq++;
                            $num = $this->formatSequenceNumber($anonymousSeq, $arabicNumerals);
                            $displayName = $prefix.' '.$num;
                        }
                        WavexContactGroupMember::query()->create([
                            'contact_group_id' => $group->id,
                            'phone' => $phone,
                            'display_name' => $displayName,
                            'sort_order' => ++$maxOrder,
                        ]);
                        $imported++;
                    }
                    if (count($list) < $pageSize) {
                        break;
                    }
                    $offset += $pageSize;
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'wawp_contacts_page_failed' && is_array($failedWawpResponse)) {
                $msg = 'Could not load WhatsApp contacts.';
                $payload = $failedWawpResponse['data'] ?? null;
                if (is_array($payload) && isset($payload['message']) && is_string($payload['message']) && $payload['message'] !== '') {
                    $msg = $payload['message'];
                }

                return ['ok' => false, 'message' => $msg];
            }
            throw $e;
        }

        return [
            'ok' => true,
            'imported' => $imported,
            'skipped_duplicates' => $skipped,
        ];
    }

    /**
     * @return array<string, string> lowercase lid jid => raw phone string from Wawp
     */
    private function buildWhatsappLidToPhoneMap(WawpClient $client): array
    {
        $map = [];
        $limit = 500;
        $offset = 0;
        $maxPages = 200;

        for ($i = 0; $i < $maxPages; $i++) {
            $res = $client->listLidMappings($limit, $offset);
            if (! ($res['ok'] ?? false)) {
                break;
            }
            $rows = $this->normalizeWawpLidRows($res['data']);
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $lid = $row['lid'] ?? null;
                $num = $row['number'] ?? $row['phone'] ?? null;
                if (is_string($lid) && is_string($num)) {
                    $lid = trim($lid);
                    $num = trim($num);
                    if ($lid !== '' && $num !== '') {
                        $map[strtolower($lid)] = $num;
                    }
                }
            }
            if (count($rows) < $limit) {
                break;
            }
            $offset += $limit;
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeWawpLidRows(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if ($data !== [] && ! array_is_list($data)) {
            $data = array_values($data);
        }
        $out = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $lidMap
     */
    private function resolvePhoneFromWhatsappJid(string $jid, array $lidMap): ?string
    {
        $jid = trim($jid);
        if ($jid === '') {
            return null;
        }
        $key = strtolower($jid);
        if (str_ends_with($key, '@lid') && isset($lidMap[$key])) {
            $digits = preg_replace('/\D+/', '', $lidMap[$key]);

            return ($digits !== null && $digits !== '') ? $digits : null;
        }

        return $this->jidToPhone($jid);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeWawpParticipantsList(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }
        if (isset($data['contacts']) && is_array($data['contacts'])) {
            $data = $data['contacts'];
        }
        if (isset($data['participants']) && is_array($data['participants'])) {
            $data = $data['participants'];
        }
        if ($data !== [] && ! array_is_list($data)) {
            $data = array_values($data);
        }

        $out = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function participantJid(array $p): ?string
    {
        $raw = $p['id'] ?? $p['jid'] ?? $p['user'] ?? $p['participant'] ?? null;
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            $raw = $raw['_serialized'] ?? $raw['user'] ?? $raw['id'] ?? null;
        }
        $s = is_string($raw) ? trim($raw) : '';

        return $s !== '' ? $s : null;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function participantDisplayName(array $p): ?string
    {
        foreach (['name', 'pushName', 'pushname', 'notify', 'shortName', 'verifiedName'] as $k) {
            $v = $p[$k] ?? null;
            if (is_string($v)) {
                $t = trim($v);

                return $t !== '' ? $t : null;
            }
        }

        return null;
    }

    private function jidToPhone(string $jid): ?string
    {
        $jid = trim($jid);
        if ($jid === '') {
            return null;
        }
        $lower = strtolower($jid);
        if (str_contains($lower, '@g.us')) {
            return null;
        }
        $at = strpos($jid, '@');
        $user = $at > 0 ? substr($jid, 0, $at) : $jid;
        $digits = preg_replace('/\D+/', '', $user);

        return ($digits !== null && $digits !== '') ? $digits : null;
    }

    private function formatSequenceNumber(int $n, bool $arabicIndic): string
    {
        $s = (string) $n;
        if (! $arabicIndic) {
            return $s;
        }
        static $map = [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ];

        return strtr($s, $map);
    }
}
