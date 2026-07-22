<?php

return [
    'enabled' => env('GEOIP_ENABLED', true),
    'city_db_path' => env('GEOIP_CITY_DB_PATH', storage_path('app/geoip/GeoLite2-City.mmdb')),
    'asn_db_path' => env('GEOIP_ASN_DB_PATH', storage_path('app/geoip/GeoLite2-ASN.mmdb')),
];
