<?php

namespace App\Services\Commerce;

use App\Models\StoreSheetConnection;
use App\Models\StoreSheetDelivery;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GoogleSheetsProvider
{
    public function credentials(array $input): array
    {
        $email = $input['client_email'] ?? null;
        $pem = $input['private_key'] ?? null;
        $key = is_string($pem) && strlen($pem) <= 16384 ? @openssl_pkey_get_private($pem) : false;
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (($input['type'] ?? null) !== 'service_account' || ! is_string($email)
            || ! preg_match('/^[a-z0-9._-]+@[a-z0-9.-]+\.gserviceaccount\.com$/D', $email)
            || ! $details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw ValidationException::withMessages(['service_account' => 'Upload a valid Google service-account JSON file with an RSA private key.']);
        }

        // No credential-supplied token URI or domain delegation is honored.
        return ['type' => 'service_account', 'client_email' => $email, 'private_key' => $pem];
    }

    public function send(StoreSheetConnection $connection, StoreSheetDelivery $delivery, callable $allowed): array
    {
        try {
            if (! $allowed()) {
                return ['status' => 'cancelled', 'error_code' => 'connection_changed'];
            }
            $credentials = $connection->credentials;
            $assertion = JWT::encode(['iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/spreadsheets',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600], $credentials['private_key'], 'RS256');
            $auth = Http::asForm()->timeout(12)->connectTimeout(5)->withOptions(['allow_redirects' => false])->post('https://oauth2.googleapis.com/token',
                ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]);
            if (! $auth->successful()) {
                return $this->failure($auth->status());
            }
            $token = $auth->json('access_token');
            if (! is_string($token) || $token === '') {
                return ['status' => 'failed', 'error_code' => 'invalid_token_response'];
            }
            $http = Http::withToken($token)->acceptJson()->timeout(12)->connectTimeout(5)->withOptions(['allow_redirects' => false]);
            $root = 'https://sheets.googleapis.com/v4/spreadsheets/'.$connection->spreadsheet_id;
            $metadata = $http->get($root, ['fields' => 'sheets(properties,developerMetadata(metadataKey,metadataValue))']);
            if (! $metadata->successful()) {
                return $this->failure($metadata->status());
            }
            $sheet = collect($metadata->json('sheets', []))->first(fn ($s) => (int) ($s['properties']['sheetId'] ?? -1) === (int) $connection->sheet_id);
            if (! $sheet) {
                if (collect($metadata->json('sheets', []))->contains(fn ($s) => ($s['properties']['title'] ?? '') === $connection->sheet_title)) {
                    return ['status' => 'failed', 'error_code' => 'sheet_identity_conflict'];
                }
                if (! $allowed()) {
                    return ['status' => 'cancelled', 'error_code' => 'connection_changed'];
                }
                $created = $http->post($root.':batchUpdate', ['requests' => [
                    ['addSheet' => ['properties' => ['sheetId' => (int) $connection->sheet_id, 'title' => $connection->sheet_title, 'gridProperties' => ['rowCount' => max(1000, (int) $delivery->row_number), 'columnCount' => count(StoreSheets::HEADERS), 'frozenRowCount' => 1]]]],
                    ['createDeveloperMetadata' => ['developerMetadata' => ['metadataKey' => 'sellchaze_connection', 'metadataValue' => $connection->id, 'location' => ['sheetId' => (int) $connection->sheet_id], 'visibility' => 'DOCUMENT']]],
                    ['updateCells' => ['start' => ['sheetId' => (int) $connection->sheet_id, 'rowIndex' => 0, 'columnIndex' => 0], 'rows' => [['values' => array_map(fn ($title) => ['userEnteredValue' => ['stringValue' => $title]], StoreSheets::HEADERS)]], 'fields' => 'userEnteredValue']],
                ]]);
                if (! $created->successful()) {
                    return $this->failure($created->status());
                }
            } else {
                $owned = collect($sheet['developerMetadata'] ?? [])->contains(fn ($m) => ($m['metadataKey'] ?? '') === 'sellchaze_connection' && ($m['metadataValue'] ?? '') === $connection->id);
                if (! $owned || ($sheet['properties']['title'] ?? '') !== $connection->sheet_title) {
                    return ['status' => 'failed', 'error_code' => 'sheet_identity_conflict'];
                }
                if ((int) ($sheet['properties']['gridProperties']['rowCount'] ?? 0) < (int) $delivery->row_number) {
                    if (! $allowed()) {
                        return ['status' => 'cancelled', 'error_code' => 'connection_changed'];
                    }
                    $grown = $http->post($root.':batchUpdate', ['requests' => [['appendDimension' => ['sheetId' => (int) $connection->sheet_id, 'dimension' => 'ROWS', 'length' => max(1000, (int) $delivery->row_number - (int) $sheet['properties']['gridProperties']['rowCount'])]]]]);
                    if (! $grown->successful()) {
                        return $this->failure($grown->status());
                    }
                }
            }
            $prefix = "'".$connection->sheet_title."'!";
            $header = $http->get($root.'/values/'.rawurlencode($prefix.'A1:S1'));
            if (! $header->successful()) {
                return $this->failure($header->status());
            }
            // Sheets connected before gift cards keep their 17 columns; new sheets have 19.
            $layout = match ($header->json('values.0')) {
                StoreSheets::HEADERS => StoreSheets::HEADERS,
                StoreSheets::LEGACY_HEADERS => StoreSheets::LEGACY_HEADERS,
                default => null,
            };
            if ($layout === null) {
                return ['status' => 'failed', 'error_code' => 'sheet_header_changed'];
            }
            $width = count($layout);
            $last = $width === count(StoreSheets::HEADERS) ? 'S' : 'Q';
            $payload = array_slice(array_pad($delivery->payload['values'], $width, ''), 0, $width);
            $range = $prefix.'A'.$delivery->row_number.':'.$last.$delivery->row_number;
            $existing = $http->get($root.'/values/'.rawurlencode($range));
            if (! $existing->successful()) {
                return $this->failure($existing->status());
            }
            $values = $existing->json('values.0', []);
            if ($values !== []) {
                if ($values === $payload) {
                    return ['status' => 'delivered', 'error_code' => null];
                }

                // Preserve user edits and pre-existing rows; never overwrite a conflict.
                return ['status' => 'failed', 'error_code' => 'sheet_row_conflict'];
            }
            if (! $allowed()) {
                return ['status' => 'cancelled', 'error_code' => 'connection_changed'];
            }
            $written = $http->put($root.'/values/'.rawurlencode($range).'?valueInputOption=RAW', ['range' => $range, 'majorDimension' => 'ROWS', 'values' => [$payload]]);
            if (! $written->successful()) {
                return $this->failure($written->status());
            }
            if ($written->json('updatedRange') !== $range || (int) $written->json('updatedRows') !== 1 || (int) $written->json('updatedColumns') !== $width) {
                return ['status' => 'retry', 'error_code' => 'write_confirmation_uncertain'];
            }

            return ['status' => 'delivered', 'error_code' => null];
        } catch (\Throwable) {
            // Never persist provider bodies, tokens, assertions or private keys.
            return ['status' => 'retry', 'error_code' => 'provider_unavailable'];
        }
    }

    private function failure(int $status): array
    {
        return ['status' => $status === 429 || $status >= 500 || $status === 409 ? 'retry' : 'failed', 'error_code' => 'google_http_'.$status];
    }
}
