<?php

namespace App\Services;

class GeoIpService
{
    /**
     * @return array{country:?string,region:?string,city:?string,timezone:?string,isp:?string,lat:?float,lon:?float}
     */
    public static function lookup(?string $ip): array
    {
        $empty = [
            'country' => null,
            'region' => null,
            'city' => null,
            'timezone' => null,
            'isp' => null,
            'lat' => null,
            'lon' => null,
        ];

        if (! config('geoip.enabled', true)) {
            return $empty;
        }

        if (! is_string($ip) || $ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
            return $empty;
        }

        $cityPath = (string) config('geoip.city_db_path');
        $asnPath = (string) config('geoip.asn_db_path');
        $result = $empty;

        try {
            $country = self::mmdbLookupValue($cityPath, $ip, ['country', 'names', 'en']);
            $region = self::mmdbLookupValue($cityPath, $ip, ['subdivisions', '0', 'names', 'en']);
            $city = self::mmdbLookupValue($cityPath, $ip, ['city', 'names', 'en']);
            $timezone = self::mmdbLookupValue($cityPath, $ip, ['location', 'time_zone']);
            $lat = self::mmdbLookupValue($cityPath, $ip, ['location', 'latitude']);
            $lon = self::mmdbLookupValue($cityPath, $ip, ['location', 'longitude']);

            $result['country'] = $country;
            $result['region'] = $region;
            $result['city'] = $city;
            $result['timezone'] = $timezone;
            $result['lat'] = is_numeric($lat) ? (float) $lat : null;
            $result['lon'] = is_numeric($lon) ? (float) $lon : null;
        } catch (\Throwable) {
            // best effort only
        }

        try {
            $org = self::mmdbLookupValue($asnPath, $ip, ['autonomous_system_organization']);
            $result['isp'] = $org;
        } catch (\Throwable) {
            // best effort only
        }

        return $result;
    }

    /**
     * @param  array<int,string>  $path
     */
    private static function mmdbLookupValue(string $dbPath, string $ip, array $path): ?string
    {
        if ($dbPath === '' || ! is_file($dbPath)) {
            return null;
        }

        $cmd = ['mmdblookup', '--file', $dbPath, '--ip', $ip];
        foreach ($path as $segment) {
            $cmd[] = $segment;
        }

        $escaped = array_map('escapeshellarg', $cmd);
        $line = implode(' ', $escaped).' 2>/dev/null';
        $output = shell_exec($line);
        if (! is_string($output) || trim($output) === '') {
            return null;
        }

        if (preg_match('/"([^"]+)"/m', $output, $m) === 1) {
            return trim($m[1]);
        }
        if (preg_match('/<double>\s+([\-0-9.]+)/m', $output, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }
}
