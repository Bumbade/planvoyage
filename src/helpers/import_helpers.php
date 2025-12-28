<?php

// src/helpers/import_helpers.php
// Helper functions for data parsing, normalization, and enrichment during import.

/**
 * Convert country name or code to ISO 3166-1 alpha-2 code
 * @param mixed $country Country name, full name, or code
 * @return string|null ISO alpha-2 code or null if not recognized
 */
function countryToIsoCode($country)
{
    if (empty($country)) {
        return null;
    }
    $country = trim((string)$country);
    if (strlen($country) === 2) {
        return strtoupper($country);
    } // Already ISO code

    $countryNameToIso = [
        'afghanistan' => 'AF','aland islands' => 'AX','albania' => 'AL','algeria' => 'DZ','american samoa' => 'AS','andorra' => 'AD','angola' => 'AO','anguilla' => 'AI','antarctica' => 'AQ','antigua and barbuda' => 'AG','argentina' => 'AR','armenia' => 'AM','aruba' => 'AW','australia' => 'AU','austria' => 'AT','azerbaijan' => 'AZ',
        'bahamas' => 'BS','bahrain' => 'BH','bangladesh' => 'BD','barbados' => 'BB','belarus' => 'BY','belgium' => 'BE','belize' => 'BZ','benin' => 'BJ','bermuda' => 'BM','bhutan' => 'BT','bolivia' => 'BO','bosnia and herzegovina' => 'BA','botswana' => 'BW','bouvet island' => 'BV','brazil' => 'BR','british virgin islands' => 'VG','brunei' => 'BN','bulgaria' => 'BG','burkina faso' => 'BF','burundi' => 'BI',
        'cambodia' => 'KH','cameroon' => 'CM','canada' => 'CA','cape verde' => 'CV','cayman islands' => 'KY','central african republic' => 'CF','chad' => 'TD','chile' => 'CL','china' => 'CN','christmas island' => 'CX','cocos islands' => 'CC','colombia' => 'CO','comoros' => 'KM','congo' => 'CG','congo democratic republic' => 'CD','cook islands' => 'CK','costa rica' => 'CR','cote d\'ivoire' => 'CI','croatia' => 'HR','cuba' => 'CU','curacao' => 'CW','cyprus' => 'CY','czech republic' => 'CZ','czechia' => 'CZ',
        'denmark' => 'DK','djibouti' => 'DJ','dominica' => 'DM','dominican republic' => 'DO','ecuador' => 'EC','egypt' => 'EG','el salvador' => 'SV','equatorial guinea' => 'GQ','eritrea' => 'ER','estonia' => 'EE','eswatini' => 'SZ','ethiopia' => 'ET','falkland islands' => 'FK','faroe islands' => 'FO','fiji' => 'FJ','finland' => 'FI','france' => 'FR','french guiana' => 'GF','french polynesia' => 'PF','gabon' => 'GA','gambia' => 'GM','georgia' => 'GE','germany' => 'DE','ghana' => 'GH','gibraltar' => 'GI','greece' => 'GR','greenland' => 'GL','grenada' => 'GD','guadeloupe' => 'GP','guam' => 'GU','guatemala' => 'GT','guernsey' => 'GG','guinea' => 'GN','guinea-bissau' => 'GW','guyana' => 'GY',
        'haiti' => 'HT','heard island and mcdonald islands' => 'HM','honduras' => 'HN','hong kong' => 'HK','hungary' => 'HU','iceland' => 'IS','india' => 'IN','indonesia' => 'ID','iran' => 'IR','iraq' => 'IQ','ireland' => 'IE','isle of man' => 'IM','israel' => 'IL','italy' => 'IT','jamaica' => 'JM','japan' => 'JP','jersey' => 'JE','jordan' => 'JO','kazakhstan' => 'KZ','kenya' => 'KE','kiribati' => 'KI','kosovo' => 'XK','kuwait' => 'KW','kyrgyzstan' => 'KG',
        'laos' => 'LA','latvia' => 'LV','lebanon' => 'LB','lesotho' => 'LS','liberia' => 'LR','libya' => 'LY','liechtenstein' => 'LI','lithuania' => 'LT','luxembourg' => 'LU','macau' => 'MO','madagascar' => 'MG','malawi' => 'MW','malaysia' => 'MY','maldives' => 'MV','mali' => 'ML','malta' => 'MT','marshall islands' => 'MH','martinique' => 'MQ','mauritania' => 'MR','mauritius' => 'MU','mayotte' => 'YT','mexico' => 'MX','moldova' => 'MD','monaco' => 'MC','mongolia' => 'MN','montenegro' => 'ME','montserrat' => 'MS','morocco' => 'MA','mozambique' => 'MZ','myanmar' => 'MM',
        'namibia' => 'NA','nauru' => 'NR','nepal' => 'NP','netherlands' => 'NL','new caledonia' => 'NC','new zealand' => 'NZ','nicaragua' => 'NI','niger' => 'NE','nigeria' => 'NG','niue' => 'NU','norfolk island' => 'NF','north macedonia' => 'MK','northern mariana islands' => 'MP','norway' => 'NO','oman' => 'OM','pakistan' => 'PK','palau' => 'PW','palestine' => 'PS','panama' => 'PA','papua new guinea' => 'PG','paraguay' => 'PY','peru' => 'PE','philippines' => 'PH','pitcairn' => 'PN','poland' => 'PL','portugal' => 'PT','puerto rico' => 'PR',
        'qatar' => 'QA','reunion' => 'RE','romania' => 'RO','russia' => 'RU','rwanda' => 'RW','saint barthelemy' => 'BL','saint helena' => 'SH','saint kitts and nevis' => 'KN','saint lucia' => 'LC','saint martin' => 'MF','saint pierre and miquelon' => 'PM','saint vincent and the grenadines' => 'VC','samoa' => 'WS','san marino' => 'SM','sao tome and principe' => 'ST','saudi arabia' => 'SA','senegal' => 'SN','serbia' => 'RS','seychelles' => 'SC','sierra leone' => 'SL','singapore' => 'SG','slovakia' => 'SK','slovenia' => 'SI','solomon islands' => 'SB','somalia' => 'SO','south africa' => 'ZA','south georgia and the south sandwich islands' => 'GS','south korea' => 'KR','south sudan' => 'SS','spain' => 'ES','sri lanka' => 'LK','sudan' => 'SD','suriname' => 'SR','svalbard and jan mayen' => 'SJ','sweden' => 'SE','switzerland' => 'CH','syria' => 'SY',
        'taiwan' => 'TW','tajikistan' => 'TJ','tanzania' => 'TZ','thailand' => 'TH','timor-leste' => 'TL','togo' => 'TG','tokelau' => 'TK','tonga' => 'TO','trinidad and tobago' => 'TT','tunisia' => 'TN','turkey' => 'TR','turkmenistan' => 'TM','turks and caicos islands' => 'TC','tuvalu' => 'TV','uganda' => 'UG','ukraine' => 'UA','united arab emirates' => 'AE','united kingdom' => 'GB','united states' => 'US','united states of america' => 'US','usa' => 'US','uruguay' => 'UY','uzbekistan' => 'UZ','vanuatu' => 'VU','vatican city' => 'VA','venezuela' => 'VE','vietnam' => 'VN','wallis and futuna' => 'WF','western sahara' => 'EH','yemen' => 'YE','zambia' => 'ZM','zimbabwe' => 'ZW'
    ];
    $lk = strtolower($country);
    return isset($countryNameToIso[$lk]) ? $countryNameToIso[$lk] : null;
}

/**
 * Reverse geocode latitude/longitude to get city, state, country
 * Uses a local PostGIS query OR falls back to Nominatim API
 * @param float $lat
 * @param float $lon
 * @param PDO $pg PostGIS connection
 * @return array ['city'=>..., 'state'=>..., 'country'=>...]
 */
function reverse_geocode_location($lat, $lon, $pg)
{
    $result = ['city' => null, 'state' => null, 'country' => null];
    if (!is_numeric($lat) || !is_numeric($lon)) {
        return $result;
    }

    if ($pg instanceof PDO) {
        try {
        // First try: Find admin boundaries via PostGIS
        $stmt = $pg->prepare("
            SELECT admin_level, name, tags::text AS tags 
            FROM planet_osm_polygon 
            WHERE boundary='administrative' 
            AND ST_Contains(ST_Transform(way,4326), ST_SetSRID(ST_MakePoint(:lon,:lat),4326))
            ORDER BY (CASE WHEN admin_level ~ '^[0-9]+$' THEN admin_level::int ELSE 999 END) ASC 
            LIMIT 20
        ");
        $stmt->bindValue(':lon', $lon, PDO::PARAM_STR);
        $stmt->bindValue(':lat', $lat, PDO::PARAM_STR);
        $stmt->execute();
        $boundaries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($boundaries)) {
            // Parse admin levels: 2=country, 4=state/province, 8=city
            foreach ($boundaries as $b) {
                $adminLevel = isset($b['admin_level']) ? trim((string)$b['admin_level']) : '';
                $name = isset($b['name']) ? trim((string)$b['name']) : '';

                if ($adminLevel === '2' && empty($result['country'])) {
                    $result['country'] = $name;
                } elseif ($adminLevel === '4' && empty($result['state'])) {
                    $result['state'] = $name;
                } elseif ($adminLevel === '8' && empty($result['city'])) {
                    $result['city'] = $name;
                }
            }
        }

        // Fallback: If country still missing, try to find from tags
        if (empty($result['country'])) {
            foreach ($boundaries as $b) {
                $adminLevel = isset($b['admin_level']) ? trim((string)$b['admin_level']) : '';
                $tags = isset($b['tags']) ? trim((string)$b['tags']) : '';

                if ($adminLevel === '2' || $adminLevel === '0') {
                    // Check for ISO code
                    $isoCode = null;
                    preg_match_all('/"([^"]+)"\s*=>\s*"([^"]*)"/', $tags, $mt, PREG_SET_ORDER);
                    if (!empty($mt)) {
                        foreach ($mt as $t) {
                            $k = strtolower($t[1]);
                            if (in_array($k, ['iso3166-1:alpha2','iso3166-1','iso_code'], true)) {
                                $isoCode = strtoupper($t[2]);
                                break;
                            }
                        }
                    }
                    if ($isoCode) {
                        $result['country'] = $isoCode;
                    }
                    break;
                }
            }
        }

        return $result;
        } catch (Exception $e) {
            // Silently fail, will use Nominatim below
        }
    }

    // Fallback: Use Nominatim (OpenStreetMap) reverse geocoding API
    try {
        $url = sprintf(
            'https://nominatim.openstreetmap.org/reverse?format=json&lat=%f&lon=%f&zoom=10&addressdetails=1&timeout=5',
            (float)$lat,
            (float)$lon
        );

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header' => "User-Agent: TravelPlanner/1.0\r\n"
            ],
            'https' => [
                'timeout' => 5,
                'header' => "User-Agent: TravelPlanner/1.0\r\n"
            ]
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response) {
            $data = @json_decode($response, true);
            if (is_array($data) && isset($data['address'])) {
                $addr = $data['address'];
                // Extract from Nominatim response
                if (isset($addr['city'])) {
                    $result['city'] = $addr['city'];
                } elseif (isset($addr['town'])) {
                    $result['city'] = $addr['town'];
                } elseif (isset($addr['village'])) {
                    $result['city'] = $addr['village'];
                }

                if (isset($addr['state'])) {
                    $result['state'] = $addr['state'];
                }

                if (isset($addr['country_code'])) {
                    $result['country'] = strtoupper($addr['country_code']); // country_code is usually ISO already
                } elseif (isset($addr['country'])) {
                    $iso = countryToIsoCode($addr['country']);
                    $result['country'] = $iso ?: $addr['country']; // Try ISO, fallback to name
                }
            }
        }
    } catch (Exception $e) {
        // Silently fail
    }

    return $result;
}

/**
 * Parse tags text (hstore-like or JSON) into an associative array
 */
function parse_tags_text($tagsText)
{
    $res = [];
    if (empty($tagsText)) {
        return $res;
    }
    $t = trim((string)$tagsText);
    $dec = @json_decode($t, true);
    if (is_array($dec)) {
        return $dec;
    }
    // quoted hstore pairs
    $matches = [];
    preg_match_all('/"([^\"]+)"\s*=>\s*"([^\"]*)"/', $t, $matches, PREG_SET_ORDER);
    if (!empty($matches)) {
        foreach ($matches as $m) {
            $res[$m[1]] = $m[2];
        }
        return $res;
    }
    // unquoted key => "value"
    $matches = [];
    preg_match_all('/([A-Za-z0-9_:\-@]+)\s*=>\s*"([^\"]*)"/', $t, $matches, PREG_SET_ORDER);
    if (!empty($matches)) {
        foreach ($matches as $m) {
            $res[$m[1]] = $m[2];
        }
        return $res;
    }
    // last resort: comma separated pairs key=value or key:"value"
    $pairs = preg_split('/,(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/', $t);
    foreach ($pairs as $p) {
        if (strpos($p, '=') !== false) {
            list($k, $v) = explode('=', $p, 2);
            $k = trim($k, " \"'\r\n\t");
            $v = trim($v, " \"'\r\n\t");
            if ($k !== '') {
                $res[$k] = $v;
            }
        }
    }
    return $res;
}

/** Normalize website URL to include scheme when missing */
function normalize_website($u)
{
    if (empty($u)) {
        return $u;
    }
    $u = trim((string)$u);
    if ($u === '') {
        return $u;
    }
    if (strpos($u, 'http://') === 0 || strpos($u, 'https://') === 0) {
        return $u;
    }
    // if looks like domain or path, prepend https
    return 'https://' . ltrim($u, '/');
}

/** Normalize social handles into either handle or full URL depending on column expectation */
function normalize_twitter($v)
{
    if (empty($v)) {
        return $v;
    }
    $v = trim((string)$v);
    if ($v === '') {
        return $v;
    }
    // strip at-sign and possible URL prefixes
    $v = preg_replace('#https?://(www\.)?twitter\.com/#i', '', $v);
    $v = ltrim($v, '@');
    // return handle (no @) — UI can render full URL if needed
    return $v;
}

function normalize_facebook($v)
{
    if (empty($v)) {
        return $v;
    }
    $v = trim((string)$v);
    $v = preg_replace('#https?://(www\.)?facebook\.com/#i', '', $v);
    return $v;
}

function normalize_instagram($v)
{
    if (empty($v)) {
        return $v;
    }
    $v = trim((string)$v);
    $v = preg_replace('#https?://(www\.)?instagram\.com/#i', '', $v);
    $v = ltrim($v, '@');
    return $v;
}
