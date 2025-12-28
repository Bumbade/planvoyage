<?php

class LocationController
{
    private $pdo;

    public function __construct()
    {
        $this->pdo = null;
        // debug: log construction to help identify which controller instance is used
        try { @file_put_contents(__DIR__ . '/../logs/location_controller_ctor.log', date('c') . " - constructed src/controllers/LocationController.php\n", FILE_APPEND | LOCK_EX); } catch (Exception $e) {}
        if (file_exists(__DIR__ . '/../config/mysql.php')) {
            require_once __DIR__ . '/../config/mysql.php';
            $this->pdo = get_db();
        }
        // Import helpers (reverse geocoding, tag parsing) - optional
        if (file_exists(__DIR__ . '/../helpers/import_helpers.php')) {
            require_once __DIR__ . '/../helpers/import_helpers.php';
        }
    }

    public function index()
    {
        include __DIR__ . '/../views/viewPOIs.php';
    }

    /**
     * Show single POI profile and allow edits when POSTed
     */
    public function show()
    {
        // Delegate to view which handles fetch and update
        include __DIR__ . '/../views/viewPOI.php';
    }

    /**
     * Handle AJAX import of a single OSM feature by osm_id.
     * Expects POST: { osm_id, csrf_token }
     * Returns JSON { ok: true, id: <new id>, backfill: { city,state,country } }
     */
    public function import($prefetched = null, $returnResult = false, $explicitOsm = null)
    {
        // When called programmatically, $returnResult=true causes method to
        // return a structured array instead of echoing JSON directly.
        $internal = ($returnResult === true);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!$internal) {
            header('Content-Type: application/json');
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
                return;
            }
            // CSRF check when session helper available
            $token = $_POST['csrf_token'] ?? null;
            if (function_exists('csrf_check') && !csrf_check($token)) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
                return;
            }
            $osm = isset($_POST['osm_id']) ? trim($_POST['osm_id']) : null;
            if (!$osm || !is_numeric($osm)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'invalid_osm_id']);
                return;
            }
        } else {
            // internal call: accept explicit osm id override or a prefetched element
            $osm = null;
            if ($explicitOsm !== null) {
                $osm = (string)$explicitOsm;
            } elseif (is_array($prefetched) && isset($prefetched['id'])) {
                $osm = (string)$prefetched['id'];
            }
            if ($osm === null) {
                // nothing to do
                return ['ok' => false, 'error' => 'invalid_osm_id'];
            }
        }

        // Use Overpass API to fetch the OSM feature by ID (node/way/relation).
        $osmId = (int)$osm;
        $feature = null;
        $overpass = env('OVERPASS_ENDPOINT', 'https://overpass.openstreetmap.org/api/interpreter');
        $timeout = (int)env('OVERPASS_TIMEOUT', 25);
        try {
            // If caller supplied a prefetched Overpass element, use it to avoid another HTTP call
            $chosen = null;
            if (is_array($prefetched) && !empty($prefetched['id'])) {
                $chosen = $prefetched;
            } else {
                $q = sprintf("[out:json][timeout:%d];(node(%d);way(%d);relation(%d););out center tags;", $timeout, $osmId, $osmId, $osmId);
                // Try primary endpoint and a small set of mirrors as fallback to mitigate DNS/timeouts
                $endpoints = [$overpass, 'https://overpass.kumi.systems/api/interpreter', 'https://lz4.overpass-api.de/api/interpreter'];
                $dec = null; $resp = null;
                foreach ($endpoints as $ep) {
                    try {
                        $ch = curl_init($ep);
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, 'data=' . $q);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, max(5, $timeout));
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                        curl_setopt($ch, CURLOPT_USERAGENT, 'PlanVoyage/1.0 (+https://planvoyage.local)');
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
                        $resp = @curl_exec($ch);
                        $http = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $errno = curl_errno($ch);
                        $err = curl_error($ch);
                        curl_close($ch);

                        // Log attempt for diagnostics
                        try { if (function_exists('import_debug_log')) import_debug_log(['event' => 'overpass_attempt', 'endpoint' => $ep, 'http' => $http ?? null, 'errno' => $errno ?? null, 'error' => $err ?? null]); } catch (Throwable $e) {}

                        if ($resp && $http >= 200 && $http < 300) {
                            $dec = @json_decode($resp, true);
                            if (is_array($dec) && isset($dec['elements']) && count($dec['elements']) > 0) break; // success
                        }
                    } catch (Exception $e) {
                        // try next endpoint
                        try { if (function_exists('import_debug_log')) import_debug_log(['event' => 'overpass_exception', 'endpoint' => $ep, 'msg' => $e->getMessage()]); } catch (Throwable $e) {}
                    }
                }

                if (is_array($dec) && isset($dec['elements']) && count($dec['elements']) > 0) {
                    // choose first element (prefer node if present)
                    foreach ($dec['elements'] as $el) {
                        if (($el['type'] ?? '') === 'node') { $chosen = $el; break; }
                        if ($chosen === null) $chosen = $el;
                    }
                } else {
                    // log raw response when nothing found for debugging
                    try { if (function_exists('import_debug_log')) import_debug_log(['event' => 'import_item_invalid_response', 'osm_id' => $osmId, 'raw' => $resp]); } catch (Throwable $e) {}
                }
            }

            if ($chosen) {
                $tags = $chosen['tags'] ?? [];
                $lat = null; $lon = null;
                if (($chosen['type'] ?? '') === 'node') {
                    $lat = $chosen['lat'] ?? null; $lon = $chosen['lon'] ?? null;
                } elseif (isset($chosen['center'])) {
                    $lat = $chosen['center']['lat'] ?? null; $lon = $chosen['center']['lon'] ?? null;
                }
                $feature = [
                    'osm_id' => $chosen['id'] ?? $osmId,
                    'name' => $tags['name'] ?? null,
                    'tags' => json_encode($tags),
                    'latitude' => $lat !== null ? (float)$lat : null,
                    'longitude' => $lon !== null ? (float)$lon : null
                ];
            }
        } catch (Exception $e) {
            // leave $feature null to trigger not_found response
            $feature = null;
        }

        if (!$feature) {
            if ($internal) return ['ok' => false, 'error' => 'not_found'];
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'not_found']);
            return;
        }

        // parse tags string into array or JSON
        $tagsText = $feature['tags'] ?? null;
        $tagsArr = null;
        if ($tagsText) {
            $dec = @json_decode($tagsText, true);
            if (is_array($dec)) {
                $tagsArr = $dec;
            } else {
                $pairs = preg_split('/,(?=(?:[^\"\"]*\"[^\"\"]*\")*[^\"\"]*$)/', $tagsText);
                $tagsArr = [];
                foreach ($pairs as $p) {
                    if (strpos($p, '=>') !== false) {
                        list($k, $v) = explode('=>', $p, 2);
                        $k = trim(trim($k), '" ');
                        $v = trim(trim($v), '" ');
                        $tagsArr[$k] = $v;
                    }
                }
            }
        }

        $name = $feature['name'] ?? null;
        $lat = isset($feature['latitude']) ? (float)$feature['latitude'] : null;
        $lon = isset($feature['longitude']) ? (float)$feature['longitude'] : null;

        // Extended country validation: prefer addr:country tag, then reverse-geocode.
        // If results conflict or are missing, refetch the OSM feature from multiple Overpass mirrors
        // before aborting the import. This reduces false-positives caused by transient DNS/response issues.
        try {
            $allowedEnv = env('IMPORT_ALLOWED_COUNTRY', 'DE');
            $allowed = strtoupper(trim($allowedEnv));
            $allowedList = array_filter(array_map('trim', array_map('strtoupper', explode(',', $allowed))));

            // Override: allow imports from any country for all users per configuration request.
            // This ensures users can import POIs regardless of server `IMPORT_ALLOWED_COUNTRY` setting.
            // If you want to re-enable country restrictions later, remove or comment out the next line.
            $allowedList = [];

            $tagCountry = is_array($tagsArr) ? ($tagsArr['addr:country'] ?? ($tagsArr['country'] ?? null)) : null;
            $tagCountryIso = null;
            if (!empty($tagCountry)) {
                if (function_exists('countryToIsoCode')) {
                    $tagCountryIso = countryToIsoCode($tagCountry) ?: strtoupper($tagCountry);
                } else {
                    $tagCountryIso = strtoupper($tagCountry);
                }
            }

            $rgCountry = null;
            if (is_numeric($lat) && is_numeric($lon) && function_exists('reverse_geocode_location')) {
                $rg = reverse_geocode_location($lat, $lon, null);
                if (is_array($rg)) $rgCountry = isset($rg['country']) ? strtoupper($rg['country']) : null;
            }

            $confirmed = false;
            // Helper to check allowed
            $isAllowed = function($c) use ($allowedList) {
                if (empty($c)) return false;
                return in_array(strtoupper($c), $allowedList, true);
            };

            // Quick accept if any available source matches allowed list
            if (!empty($tagCountryIso) && $isAllowed($tagCountryIso)) $confirmed = true;
            if (!$confirmed && !empty($rgCountry) && $isAllowed($rgCountry)) $confirmed = true;

            // If not yet confirmed and we have conflicting or missing data, try refetching from Overpass mirrors
            if (!$confirmed && count($allowedList) > 0) {
                $endpoints = [$overpass, 'https://overpass.kumi.systems/api/interpreter', 'https://lz4.overpass-api.de/api/interpreter'];
                foreach ($endpoints as $ep) {
                    try {
                        // Re-query this endpoint for the same feature
                        $ch2 = curl_init($ep);
                        curl_setopt($ch2, CURLOPT_POST, true);
                        curl_setopt($ch2, CURLOPT_POSTFIELDS, 'data=' . $q);
                        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch2, CURLOPT_TIMEOUT, max(5, $timeout));
                        curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 5);
                        curl_setopt($ch2, CURLOPT_USERAGENT, 'PlanVoyage/1.0 (+https://planvoyage.local)');
                        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
                        $out = @curl_exec($ch2);
                        $http2 = @curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                        $err2 = curl_error($ch2);
                        curl_close($ch2);

                        try { if (function_exists('import_debug_log')) import_debug_log(['event' => 'overpass_refetch_attempt', 'endpoint' => $ep, 'http' => $http2 ?? null, 'error' => $err2 ?? null, 'osm_id' => $osmId]); } catch (Throwable $e) {}

                        if ($out && $http2 >= 200 && $http2 < 300) {
                            $dj = @json_decode($out, true);
                            if (is_array($dj) && !empty($dj['elements'])) {
                                $chose = null;
                                foreach ($dj['elements'] as $el) {
                                    if (($el['type'] ?? '') === 'node') { $chose = $el; break; }
                                    if ($chose === null) $chose = $el;
                                }
                                if ($chose) {
                                    $t = $chose['tags'] ?? [];
                                    $candidateTagCountry = $t['addr:country'] ?? ($t['country'] ?? null);
                                    $candidateIso = null;
                                    if (!empty($candidateTagCountry) && function_exists('countryToIsoCode')) {
                                        $candidateIso = countryToIsoCode($candidateTagCountry) ?: strtoupper($candidateTagCountry);
                                    } elseif (!empty($candidateTagCountry)) {
                                        $candidateIso = strtoupper($candidateTagCountry);
                                    }
                                    $candidateLat = null; $candidateLon = null;
                                    if (($chose['type'] ?? '') === 'node') {
                                        $candidateLat = $chose['lat'] ?? null; $candidateLon = $chose['lon'] ?? null;
                                    } elseif (isset($chose['center'])) {
                                        $candidateLat = $chose['center']['lat'] ?? null; $candidateLon = $chose['center']['lon'] ?? null;
                                    }
                                    $candidateRg = null;
                                    if (is_numeric($candidateLat) && is_numeric($candidateLon) && function_exists('reverse_geocode_location')) {
                                        $r2 = reverse_geocode_location((float)$candidateLat, (float)$candidateLon, null);
                                        if (is_array($r2)) $candidateRg = isset($r2['country']) ? strtoupper($r2['country']) : null;
                                    }
                                    // accept if either tag-country or reverse-geocode from this mirror matches allowed list
                                    if ($isAllowed($candidateIso) || $isAllowed($candidateRg)) {
                                        // adopt this candidate as authoritative
                                        $confirmed = true;
                                        // update feature coords/tags for subsequent processing
                                        $feature['latitude'] = is_numeric($candidateLat) ? (float)$candidateLat : $feature['latitude'];
                                        $feature['longitude'] = is_numeric($candidateLon) ? (float)$candidateLon : $feature['longitude'];
                                        $feature['tags'] = is_array($t) ? json_encode($t) : ($t ?? $feature['tags']);
                                        // mark to break outer endpoints loop and break inner loop
                                        $should_break_endpoints = true;
                                        break; // exit current elements loop
                                    }
                                }
                            }
                        }
                        if (!empty($should_break_endpoints)) {
                            // break out of the outer endpoints loop
                            break;
                        }
                    } catch (Throwable $e) {
                        try { if (function_exists('import_debug_log')) import_debug_log(['event' => 'overpass_refetch_error', 'endpoint' => $ep, 'osm_id' => $osmId, 'msg' => $e->getMessage()]); } catch (Throwable $e) {}
                        continue;
                    }
                }
            }

            if (count($allowedList) > 0 && !$confirmed) {
                try { if (function_exists('import_debug_log')) import_debug_log(['event' => 'country_mismatch_final', 'osm_id' => $osmId, 'tag_country' => $tagCountry, 'tag_country_iso' => $tagCountryIso, 'rg_country' => $rgCountry, 'allowed' => $allowedList, 'remote' => $_SERVER['REMOTE_ADDR'] ?? null, 'session_user' => $_SESSION['user_id'] ?? null]); } catch (Throwable $e) {}
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'country_mismatch', 'country' => $tagCountryIso ?? $rgCountry ?? null]);
                return;
            }
        } catch (Throwable $e) {
            // ignore validation errors and continue import
        }

        // choose a sensible type, prefer amenity/tourism/shop tags
        $type = 'poi';
        if (is_array($tagsArr)) {
            if (!empty($tagsArr['amenity'])) {
                $type = ucfirst($tagsArr['amenity']);
            } elseif (!empty($tagsArr['tourism'])) {
                $type = ucfirst($tagsArr['tourism']);
            } elseif (!empty($tagsArr['shop'])) {
                $type = ucfirst($tagsArr['shop']);
            }
        }

        // Fallback inference when tags are not explicit. Improve heuristics to avoid
        // misclassifying 'Food Bank' or similar social facilities as commercial banks.
        $inferredLogo = null;
        try {
            $lname = is_string($name) ? strtolower($name) : '';
            $hasSocialFacilityKey = false;
            if (is_array($tagsArr)) {
                foreach ($tagsArr as $k => $v) {
                    if (stripos($k, 'social_facility') === 0) {
                        $hasSocialFacilityKey = true;
                        break;
                    }
                }
            }

            // If name explicitly mentions food/blood/community bank OR tags indicate social facility,
            // treat as a social/food bank rather than a commercial bank.
            if ($type === 'poi') {
                if (preg_match('/\b(food\s*bank|foodbank)\b/i', $name) || $hasSocialFacilityKey) {
                    // Prefer a clear label when name includes 'Food Bank'
                    if (preg_match('/\b(food\s*bank|foodbank)\b/i', $name)) {
                        $type = 'Food Bank';
                    } else {
                        $type = 'Social Facility';
                    }
                    // Do not assign commercial bank logo for social facilities
                } elseif (preg_match('/\b(bank|atm)\b/i', $name)) {
                    // Commercial bank / ATM inference
                    $type = 'Bank';
                    $candidate = __DIR__ . '/../assets/icons/bank.png';
                    if (file_exists($candidate)) {
                        $inferredLogo = 'bank.png';
                    }
                }
                // If the inferred type or tags indicate a fuel/gas station, set gas icon
                try {
                    $amenityTag = is_array($tagsArr) && !empty($tagsArr['amenity']) ? strtolower($tagsArr['amenity']) : null;
                    $shopTag = is_array($tagsArr) && !empty($tagsArr['shop']) ? strtolower($tagsArr['shop']) : null;
                    if (strtolower($type) === 'fuel' || $amenityTag === 'fuel' || $shopTag === 'fuel') {
                        $candidateGas = __DIR__ . '/../assets/icons/gas_station.png';
                        if (file_exists($candidateGas)) {
                            $inferredLogo = 'gas_station.png';
                        }
                    }
                    // If shop tag indicates a supermarket, prefer supermarket icon
                    if ($shopTag === 'supermarket' || $amenityTag === 'supermarket') {
                        $candidateSuper = __DIR__ . '/../assets/icons/supermarket.png';
                        if (file_exists($candidateSuper)) {
                            $inferredLogo = 'supermarket.png';
                        }
                    }
                    // parking inference
                    if ($amenityTag === 'parking' || $shopTag === 'parking' || stripos($lname, 'parking') !== false) {
                        $candidatePark1 = __DIR__ . '/../assets/icons/Parking.png';
                        $candidatePark2 = __DIR__ . '/../assets/icons/parking.png';
                        if (file_exists($candidatePark1)) $inferredLogo = 'Parking.png';
                        elseif (file_exists($candidatePark2)) $inferredLogo = 'parking.png';
                    }
                } catch (Exception $e) {}
            }
        } catch (Exception $e) { /* ignore inference errors */
        }

            // Ensure common category-based logos are applied even when type was
            // determined earlier (not only inside the 'poi' fallback block).
            try {
                if ($inferredLogo === null) {
                    $amen = is_array($tagsArr) && !empty($tagsArr['amenity']) ? strtolower($tagsArr['amenity']) : null;
                    $typel = is_string($type) ? strtolower($type) : null;
                    if ($amen === 'fuel' || $typel === 'fuel' || $typel === 'gas stations' || $typel === 'gas_stations') {
                        $candidateGas = __DIR__ . '/../assets/icons/gas_station.png';
                        if (file_exists($candidateGas)) $inferredLogo = 'gas_station.png';
                    }
                    // supermarket inference
                    if ($inferredLogo === null) {
                        if ($amen === 'supermarket' || $typel === 'supermarket' || $typel === 'supermarkets' || $typel === 'shop') {
                            $candidateSuper = __DIR__ . '/../assets/icons/supermarket.png';
                            if (file_exists($candidateSuper)) $inferredLogo = 'supermarket.png';
                        }
                    }
                    // parking fallback
                    if ($inferredLogo === null) {
                        if ($amen === 'parking' || $typel === 'parking' || stripos($name, 'parking') !== false) {
                            $candidatePark1 = __DIR__ . '/../assets/icons/Parking.png';
                            $candidatePark2 = __DIR__ . '/../assets/icons/parking.png';
                            if (file_exists($candidatePark1)) $inferredLogo = 'Parking.png';
                            elseif (file_exists($candidatePark2)) $inferredLogo = 'parking.png';
                        }
                    }
                    if ($inferredLogo === null) {
                        // banks handled earlier; also check explicit 'bank' types
                        if ($typel === 'bank') {
                            $candidate = __DIR__ . '/../assets/icons/bank.png';
                            if (file_exists($candidate)) $inferredLogo = 'bank.png';
                        }
                    }
                }
            } catch (Exception $e) {}

        // Insert into MySQL locations table
        // But first: if a matching location already exists, reuse it and
        // add the current user->location mapping (favorites) instead
        try {
            $db = $this->pdo;
            // prefer exact match by osm_id when available
            if ($osmId) {
                try {
                    $chk = $db->prepare('SELECT id FROM locations WHERE osm_id = :osm LIMIT 1');
                    $chk->execute([':osm' => $osmId]);
                    $found = $chk->fetchColumn();
                    if ($found) {
                        $newId = (int)$found;
                        // If a user is logged in, ensure a favorites mapping exists so
                        // the importer associates the existing location with the user.
                        try {
                            $sessionUserId = $_SESSION['user_id'] ?? null;
                            if (!empty($sessionUserId) && !empty($this->pdo)) {
                                try {
                                    $fdb = $this->pdo;
                                    $fchk = $fdb->prepare('SELECT 1 FROM favorites WHERE user_id = :uid AND location_id = :lid LIMIT 1');
                                    $fchk->execute([':uid' => $sessionUserId, ':lid' => $newId]);
                                    $existsFav = $fchk->fetchColumn();
                                    if (!$existsFav) {
                                        $fins = $fdb->prepare('INSERT INTO favorites (user_id, location_id, created_at) VALUES (:uid, :lid, NOW())');
                                        $fins->execute([':uid' => $sessionUserId, ':lid' => $newId]);
                                    }
                                } catch (Exception $e) {
                                    // non-fatal: do not block import if favorites insert fails
                                }
                            }
                        } catch (Exception $e) { /* ignore */ }

                        // return existing id to caller
                        if ($internal) return ['ok' => true, 'id' => $newId, 'existing' => true];
                        echo json_encode(['ok' => true, 'id' => $newId, 'existing' => true]);
                        return;
                    }
                } catch (Exception $e) { /* ignore and continue to other checks */
                }
            }
            // fallback: try match by exact name + lat + lon when coordinates present
            if ($lat !== null && $lon !== null && $name) {
                try {
                    $chk2 = $db->prepare('SELECT id FROM locations WHERE name = :name AND latitude = :lat AND longitude = :lon LIMIT 1');
                    $chk2->execute([':name' => $name, ':lat' => $lat, ':lon' => $lon]);
                    $found2 = $chk2->fetchColumn();
                    if ($found2) {
                        $newId = (int)$found2;
                        if ($internal) return ['ok' => true, 'id' => $newId, 'existing' => true];
                        echo json_encode(['ok' => true, 'id' => $newId, 'existing' => true]);
                        return;
                    }
                } catch (Exception $e) { /* ignore and continue to insert */
                }
            }
            // detect if the locations table supports a `logo` column so we can insert it when available
            $colsList = $db->query("SHOW COLUMNS FROM locations")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_column($colsList, 'Field');
            // map of column => type for sensible binds (e.g., DATE vs DATETIME)
            $colTypes = array_column($colsList, 'Type', 'Field');
            $hasLogoCol = in_array('logo', $colNames, true);

            // Determine whether DB has additional columns we can populate from tags
            // Start with core columns. We'll add `tags` only when no individual tag->column mapping matched.
            $cols = ['name','type','latitude','longitude','osm_id'];
            $placeholders = [':name',':type',':lat',':lon',':osm'];
            if ($hasLogoCol) {
                $cols[] = 'logo';
                $placeholders[] = ':logo';
            }

            // Map commonly used OSM tag keys into existing DB columns when present
            // Collect any extra binds BEFORE preparing the statement so placeholders
            // match the bound variables (prevents HY093 errors).
            $bindExtras = [];
            $matchedAnyTagColumn = false;
            $shouldBindTagsColumn = false;
            if (is_array($tagsArr)) {
                $map = [
                    // address fields
                    'addr:street' => 'addr_street', 'street' => 'addr_street',
                    'addr:housenumber' => 'addr_housenumber', 'housenumber' => 'addr_housenumber',
                    'addr:city' => 'addr_city', 'city' => 'addr_city',
                    'addr:postcode' => 'addr_postcode', 'postcode' => 'addr_postcode',
                    'addr:postcode_alt' => 'addr_postco', // legacy variations
                    'addr:province' => 'addr_province', 'addr:state' => 'state', 'state' => 'state',

                    // identity / names
                    'name' => 'name', 'alt_name' => 'alt_name', 'old_name' => 'old_name', 'short_name' => 'short_name',
                    'name:en' => 'name_en', 'name:de' => 'name_de', 'name:fr' => 'name_fr',

                    // contact
                    'website' => 'website', 'url' => 'website', 'web' => 'website', 'contact:website' => 'website',
                    'phone' => 'phone', 'contact:phone' => 'phone', 'telephone' => 'phone',
                    'email' => 'email', 'contact:email' => 'email', 'fax' => 'fax',
                    'twitter' => 'twitter', 'facebook' => 'facebook', 'instagram' => 'instagram',

                    // operator/brand
                    'brand' => 'brand', 'operator' => 'operator',
                    'brand:wikidata' => 'brand_wikidata', 'brand:wikipedia' => 'brand_wikipedia',
                    'operator:wikidata' => 'operator_wikidata', 'operator:wikipedia' => 'operator_wikipedia',

                    // amenities and details
                    'opening_hours' => 'opening_hours', 'cuisine' => 'cuisine',
                    'internet_access' => 'internet_access', 'internet_access:fee' => 'internet_access_fee',
                    'internet_access:fee:yes' => 'internet_access_fee',

                    // miscellaneous
                    'wikidata' => 'wikidata', 'wikipedia' => 'wikipedia', 'ref' => 'ref',
                    'height' => 'height', 'capacity' => 'capacity', 'wheelchair' => 'wheelchair',

                    // postcode variants for legacy schema names
                    'addr:postco' => 'addr_postco', 'addr_postco' => 'addr_postco',

                    // fallback simple mappings
                    'country' => 'country'
                ];

                foreach ($map as $tagKey => $colName) {
                    if (in_array($colName, $colNames, true) && isset($tagsArr[$tagKey]) && $tagsArr[$tagKey] !== '') {
                        $cols[] = $colName;
                        $placeholders[] = ':' . $colName;
                        $bindExtras[$colName] = (string)$tagsArr[$tagKey];
                        $matchedAnyTagColumn = true;
                    }
                }

                // Generic mapping: for any tag key not covered above, try to map
                // tag keys into existing DB columns by normalizing the tag name
                // (replace ':' and '-' with '_') and matching exact or prefix
                // variants of column names. This ensures tags are distributed into
                // explicit columns when available instead of being stored in the
                // generic `tags` column.
                foreach ($tagsArr as $tagKey => $tagVal) {
                    // skip if already mapped via explicit map
                    $candidates = [];
                    $norm = preg_replace('/[^A-Za-z0-9_]/', '_', str_replace([':', '-'], ['_', '_'], $tagKey));
                    $candidates[] = $norm;
                    $candidates[] = strtolower($norm);
                    // also try a truncated prefix to match truncated column names
                    if (strlen($norm) > 10) $candidates[] = substr($norm, 0, 10);

                    foreach ($candidates as $cand) {
                        foreach ($colNames as $colNm) {
                            if (in_array($colNm, $cols, true)) continue; // already bound
                            // case-insensitive exact match
                            if (strtolower($colNm) === strtolower($cand)) {
                                $cols[] = $colNm;
                                $placeholders[] = ':' . $colNm;
                                $bindExtras[$colNm] = (string)$tagVal;
                                $matchedAnyTagColumn = true;
                                // break out to next tagKey
                                continue 3;
                            }
                            // prefix match: candidate is prefix of column name
                            // Only allow prefix matching for long normalized candidates to
                            // avoid short tag keys (e.g. 'building') matching unrelated
                            // columns like 'building_le'. Require candidate length >= 10.
                            if (strlen($cand) >= 10 && stripos($colNm, $cand) === 0) {
                                $cols[] = $colNm;
                                $placeholders[] = ':' . $colNm;
                                $bindExtras[$colNm] = (string)$tagVal;
                                $matchedAnyTagColumn = true;
                                continue 3;
                            }
                        }
                    }
                }

                // Compose address from addr:street and addr:housenumber when `address` column exists
                if (in_array('address', $colNames, true)) {
                    $street = trim((string)($tagsArr['addr:street'] ?? $tagsArr['street'] ?? ''));
                    $hnum = trim((string)($tagsArr['addr:housenumber'] ?? $tagsArr['housenumber'] ?? ''));
                    $addr = trim($street . ($hnum !== '' ? ' ' . $hnum : ''));
                    if ($addr !== '') {
                        $cols[] = 'address';
                        $placeholders[] = ':address';
                        $bindExtras['address'] = $addr;
                    }
                }

                // Also support mapping postcode into either `addr_postco` or `addr_postcode` columns if they exist
                if (!empty($tagsArr['addr:postcode']) || !empty($tagsArr['postcode'])) {
                    $pc = $tagsArr['addr:postcode'] ?? ($tagsArr['postcode'] ?? null);
                    if ($pc !== null) {
                        if (in_array('addr_postco', $colNames, true)) {
                                if (!in_array('addr_postco', $cols, true)) { $cols[] = 'addr_postco'; $placeholders[] = ':addr_postco'; }
                                $bindExtras['addr_postco'] = (string)$pc;
                            }
                            if (in_array('addr_postcode', $colNames, true)) {
                                if (!in_array('addr_postcode', $cols, true)) { $cols[] = 'addr_postcode'; $placeholders[] = ':addr_postcode'; }
                                $bindExtras['addr_postcode'] = (string)$pc;
                            }
                    }
                }

                // Ensure primary `city` column is populated when addr:city or city tag is present.
                // Some schemas use `addr_city` for the address field; if both exist prefer to fill both.
                if (in_array('city', $colNames, true)) {
                    // If we mapped addr:city into addr_city earlier, mirror it into city as well
                    if (in_array('addr_city', $colNames, true) && isset($bindExtras['addr_city']) && !in_array('city', $cols, true)) {
                        $cols[] = 'city';
                        $placeholders[] = ':city';
                        $bindExtras['city'] = (string)$bindExtras['addr_city'];
                        $matchedAnyTagColumn = true;
                    }
                    // If schema lacks addr_city but tag addr:city exists, bind it directly to city
                    if (!in_array('addr_city', $colNames, true) && isset($tagsArr['addr:city']) && !in_array('city', $cols, true)) {
                        $cols[] = 'city';
                        $placeholders[] = ':city';
                        $bindExtras['city'] = (string)$tagsArr['addr:city'];
                        $matchedAnyTagColumn = true;
                    }
                    // Also accept a plain 'city' tag
                    if (isset($tagsArr['city']) && !in_array('city', $cols, true) && !array_key_exists('city', $bindExtras)) {
                        $cols[] = 'city';
                        $placeholders[] = ':city';
                        $bindExtras['city'] = (string)$tagsArr['city'];
                        $matchedAnyTagColumn = true;
                    }
                }
                // If database has a `created_at` column, set it to current timestamp.
                if (in_array('created_at', $colNames, true)) {
                    $cols[] = 'created_at';
                    $placeholders[] = ':created_at';
                    // Use DATE-only when column type is DATE (no time), otherwise use DATETIME
                    $ctype = strtolower($colTypes['created_at'] ?? '');
                    if (strpos($ctype, 'date') !== false && strpos($ctype, 'datetime') === false) {
                        $bindExtras['created_at'] = date('Y-m-d');
                    } else {
                        $bindExtras['created_at'] = date('Y-m-d H:i:s');
                    }
                }

                // If none of the tag keys matched individual DB columns, store the raw tags
                // (shortened) into the `tags` column — but only if the column exists.
                $shouldBindTagsColumn = false;
                if (!$matchedAnyTagColumn && in_array('tags', $colNames, true)) {
                    $cols[] = 'tags';
                    $placeholders[] = ':tags';
                    $shouldBindTagsColumn = true;
                }

                // If the schema includes a `tags_text` column (text/blob), store full tags JSON there.
                // This preserves full tag data when the `tags` (varchar) column is too small.
                $shouldBindTagsText = false;
                if (in_array('tags_text', $colNames, true)) {
                    // ensure column type can hold larger content (text/longtext/blob)
                    $t = strtolower($colTypes['tags_text'] ?? '');
                    if (strpos($t, 'text') !== false || strpos($t, 'blob') !== false) {
                        $cols[] = 'tags_text';
                        $placeholders[] = ':tags_text';
                        $shouldBindTagsText = true;
                    }
                }
            }

            // Build final column list including any extra columns discovered and populated from tags
            $cols = array_values(array_unique($cols));
            $placeholders = array_values(array_unique($placeholders));

            // Defensive filter: ensure we only include columns that actually exist in the DB
            // Keep core columns even if not reported (name,type,latitude,longitude,osm_id)
            $coreKeep = ['name','type','latitude','longitude','osm_id'];
            $finalCols = [];
            $finalPlaceholders = [];
            foreach ($cols as $i => $c) {
                if (in_array($c, $colNames, true) || in_array($c, $coreKeep, true)) {
                    $finalCols[] = $c;
                    $finalPlaceholders[] = $placeholders[$i] ?? (':' . $c);
                } else {
                    // skip columns that don't exist anymore (e.g. description, coordinates)
                    try { if (function_exists('import_debug_log')) import_debug_log(['event' => 'skip_missing_column', 'column' => $c, 'source' => __FILE__]); } catch (Throwable $e) {}
                }
            }

            $cols = $finalCols;
            $placeholders = $finalPlaceholders;

            // If we matched any individual tag->column, ensure we do NOT include
            // the legacy `tags` varchar column even if it exists in the schema.
            if (!empty($matchedAnyTagColumn)) {
                $idx = array_search('tags', $cols, true);
                if ($idx !== false) {
                    array_splice($cols, $idx, 1);
                    array_splice($placeholders, $idx, 1);
                    $shouldBindTagsColumn = false;
                    try { if (function_exists('import_debug_log')) import_debug_log(['event' => 'removed_tags_column_due_to_mapping', 'cols' => $cols]); } catch (Throwable $e) {}
                }
            }

            $sqlIns = 'INSERT INTO locations (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
            $ins = $db->prepare($sqlIns);
            $ins->bindValue(':name', $name);
            $ins->bindValue(':type', $type);
            $ins->bindValue(':lat', $lat);
            $ins->bindValue(':lon', $lon);

            // Ensure tagsText is a string (JSON) and provide a shortened `tags` for DB column
            $tagsText = is_array($tagsText) ? json_encode($tagsText) : (string)$tagsText;
            $tagsShort = mb_substr($tagsText, 0, 255);
            if (!empty($shouldBindTagsColumn)) {
                $ins->bindValue(':tags', $tagsShort);
            }
            if (!empty($shouldBindTagsText)) {
                // bind the full tags JSON/text into tags_text column when available
                $ins->bindValue(':tags_text', $tagsText);
            }
            $ins->bindValue(':osm', $osmId, PDO::PARAM_INT);
                            // Debug: log SQL and binds
                            try {
                                if (function_exists('import_debug_log')) {
                                    $bindsLog = ['name' => $name, 'type' => $type, 'lat' => $lat, 'lon' => $lon, 'osm' => $osmId, 'tags_short_len' => strlen($tagsShort), 'tags_text_len' => strlen($tagsText), 'hasLogo' => $hasLogoCol];
                                    $dbg = ['event' => 'insert_prepare', 'sql' => $sqlIns, 'binds' => $bindsLog, 'remote' => $_SERVER['REMOTE_ADDR'] ?? null, 'session_user' => $_SESSION['user_id'] ?? null, 'source_file' => __FILE__];
                                    // include first few included files for context
                                    $incs = @get_included_files();
                                    if (is_array($incs)) $dbg['included'] = array_slice($incs, 0, 8);
                                    import_debug_log($dbg);
                                }
                            } catch (Throwable $e) { }

            // Note: tags_text column present in some schemas. To avoid insert failures
            // due to mismatched column sizes across installations, we skip inserting
            // the full `tags_text` field here. The shortened `tags` column is still
            // stored to preserve basic tag info.

            if ($hasLogoCol) {
                // bind NULL when no inferred logo
                if ($inferredLogo !== null) {
                    $ins->bindValue(':logo', $inferredLogo);
                } else {
                    $ins->bindValue(':logo', null, PDO::PARAM_NULL);
                }
            }

            // Bind any extra tag->column values
            foreach ($bindExtras as $col => $val) {
                try {
                    $ins->bindValue(':' . $col, $val);
                } catch (Exception $e) { /* ignore binding errors */ }
            }
            // Debug: include actual columns being inserted
            try {
                if (function_exists('import_debug_log')) import_debug_log(['event' => 'insert_columns', 'cols' => $cols, 'placeholders' => $placeholders]);
            } catch (Throwable $e) {}
            try {
                $ins->execute();
            } catch (PDOException $e) {
                // If a duplicate key on osm_id occurred (race condition), return the existing id instead of failing.
                $sqlState = $e->getCode();
                $msg = $e->getMessage();
                try { if (function_exists('import_debug_log')) import_debug_log(['event' => 'insert_failed_exception', 'sqlState' => $sqlState, 'msg' => $msg, 'sql' => $sqlIns]); } catch (Throwable $ee) {}
                // SQLSTATE 23000 is integrity constraint violation (MySQL duplicate key)
                if (strpos((string)$sqlState, '23000') !== false || stripos($msg, 'Duplicate') !== false) {
                    try {
                        $chk = $db->prepare('SELECT id FROM locations WHERE osm_id = :osm LIMIT 1');
                        $chk->execute([':osm' => $osmId]);
                        $foundId = $chk->fetchColumn();
                        if ($foundId) {
                            $newId = (int)$foundId;
                            // Return existing id to caller
                            if ($internal) return ['ok' => true, 'id' => $newId, 'existing' => true, 'note' => 'duplicate_key_resolved'];
                            echo json_encode(['ok' => true, 'id' => $newId, 'existing' => true, 'note' => 'duplicate_key_resolved']);
                            return;
                        }
                    } catch (Exception $ex) {
                        // fall through to rethrow below
                    }
                }
                // rethrow for outer catch to handle
                throw $e;
            }
            // Log execution result
            try {
                if (function_exists('import_debug_log')) {
                    $errInfo = $ins->errorInfo();
                    import_debug_log(['event' => 'insert_execute', 'sql' => $sqlIns, 'errorInfo' => $errInfo, 'lastInsertId' => $db->lastInsertId(), 'remote' => $_SERVER['REMOTE_ADDR'] ?? null, 'session_user' => $_SESSION['user_id'] ?? null]);
                }
            } catch (Throwable $e) {}
            $newId = (int)$db->lastInsertId();
            // Mirror tags_text into tags for compatibility after insert
            try {
                if (in_array('tags', $colNames, true)) {
                    $sTags = $db->prepare('SELECT tags, tags_text FROM locations WHERE id = :id LIMIT 1');
                    $sTags->bindValue(':id', $newId, PDO::PARAM_INT);
                    $sTags->execute();
                    $rrt = $sTags->fetch(PDO::FETCH_ASSOC);
                    if ($rrt && array_key_exists('tags_text', $rrt) && array_key_exists('tags', $rrt) && !empty($rrt['tags_text']) && (empty($rrt['tags']) || $rrt['tags'] === null)) {
                        $mup = $db->prepare('UPDATE locations SET tags = :tags WHERE id = :id');
                        $mup->bindValue(':tags', $rrt['tags_text']);
                        $mup->bindValue(':id', $newId, PDO::PARAM_INT);
                        try {
                            $mup->execute();
                        } catch (Exception $e) { /* ignore */
                        }
                    }
                }
            } catch (Exception $e) { /* ignore */
            }
            // If a user is logged in, create a favorites mapping so the importer
            // automatically associates the creating user with the new location.
            try {
                $sessionUserId = $_SESSION['user_id'] ?? null;
                if (!empty($sessionUserId)) {
                    try {
                        $fchk = $db->prepare('SELECT 1 FROM favorites WHERE user_id = :uid AND location_id = :lid LIMIT 1');
                        $fchk->execute([':uid' => $sessionUserId, ':lid' => $newId]);
                        $exists = $fchk->fetchColumn();
                        if (!$exists) {
                            $fins = $db->prepare('INSERT INTO favorites (user_id, location_id, created_at) VALUES (:uid, :lid, NOW())');
                            $fins->execute([':uid' => $sessionUserId, ':lid' => $newId]);
                        }
                    } catch (Exception $e) {
                        // Non-fatal: do not break import on favorites failure
                        try { if (function_exists('import_debug_log')) import_debug_log(['event' => 'favorites_insert_failed', 'msg' => $e->getMessage(), 'user_id' => $sessionUserId, 'location_id' => $newId]); } catch (Throwable $ee) {}
                    }
                } else {
                    try { if (function_exists('import_debug_log')) import_debug_log(['event' => 'favorites_skipped_no_user', 'location_id' => $newId]); } catch (Throwable $e) {}
                }
            } catch (Throwable $e) {
                // swallow any unexpected errors to keep import robust
            }
        } catch (Exception $e) {
            if ($internal) return ['ok' => false, 'error' => 'insert_failed', 'msg' => $e->getMessage()];
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'insert_failed', 'msg' => $e->getMessage()]);
            return;
        }

        // Backfill city/state/country using Nominatim reverse geocoding (overpass removed)
        $foundCity = null;
        $foundState = null;
        $foundCountry = null;
        $adminQueryDebug = null;
        if ($lat !== null && $lon !== null) {
            try {
                if (!function_exists('reverse_geocode_location') && file_exists(__DIR__ . '/../helpers/import_helpers.php')) {
                    require_once __DIR__ . '/../helpers/import_helpers.php';
                }
                if (function_exists('reverse_geocode_location')) {
                    $rev = reverse_geocode_location($lat, $lon, null);
                    if (is_array($rev)) {
                        $foundCity = $rev['city'] ?? null;
                        $foundState = $rev['state'] ?? null;
                        $foundCountry = $rev['country'] ?? null;
                        $adminQueryDebug = ['source' => 'nominatim', 'ok' => true];
                    } else {
                        $adminQueryDebug = ['source' => 'nominatim', 'ok' => false];
                    }
                } else {
                    $adminQueryDebug = ['error' => 'reverse_geocode_location not available'];
                }
            } catch (Exception $e) {
                $adminQueryDebug = ['error' => $e->getMessage()];
            }
        }
        // sanitize helpers
        $sanitize = function ($s) {
            if ($s === null) {
                return null;
            }
            $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$s));
            if ($s === '') {
                return null;
            }
            return mb_substr($s, 0, 255);
        };
        $foundCity = $sanitize($foundCity);
        $foundState = $sanitize($foundState);
        $foundCountry = $sanitize($foundCountry);

        // normalize country to ISO2 (best-effort)
        $countryISO = null;
        if ($foundCountry) {
            // Try helper mapping
            if (function_exists('countryToIsoCode')) {
                $countryISO = countryToIsoCode($foundCountry);
            }
            if (!$countryISO) {
                $map = ['Canada' => 'CA','United States' => 'US','United Kingdom' => 'GB','Germany' => 'DE','France' => 'FR','Spain' => 'ES','Italy' => 'IT','Austria' => 'AT','Switzerland' => 'CH','Netherlands' => 'NL','Belgium' => 'BE','Australia' => 'AU','New Zealand' => 'NZ'];
                if (isset($map[$foundCountry])) {
                    $countryISO = $map[$foundCountry];
                }
            }
            if ($countryISO) {
                $foundCountry = $countryISO;
            }
        }

        // Update the inserted row with found values if any
        $updateParts = [];
        $updateBinds = [];
        if ($foundCity !== null) {
            $updateParts[] = 'city = :city';
            $updateBinds[':city'] = $foundCity;
        }
        if ($foundState !== null) {
            $updateParts[] = 'state = :state';
            $updateBinds[':state'] = $foundState;
        }
        if ($foundCountry !== null) {
            $updateParts[] = 'country = :country';
            $updateBinds[':country'] = $foundCountry;
        }
        if (!empty($updateParts)) {
            try {
                $sqlu = 'UPDATE locations SET ' . implode(', ', $updateParts) . ' WHERE id = :id';
                $u = $db->prepare($sqlu);
                foreach ($updateBinds as $k => $v) {
                    $u->bindValue($k, $v);
                }
                $u->bindValue(':id', $newId, PDO::PARAM_INT);
                $u->execute();
                // Mirror tags_text into tags for compatibility when tags is empty
                try {
                    if (in_array('tags', $colNames, true)) {
                        $sTags = $db->prepare('SELECT tags, tags_text FROM locations WHERE id = :id LIMIT 1');
                        $sTags->bindValue(':id', $newId, PDO::PARAM_INT);
                        $sTags->execute();
                        $rrt = $sTags->fetch(PDO::FETCH_ASSOC);
                        if ($rrt && array_key_exists('tags_text', $rrt) && array_key_exists('tags', $rrt) && !empty($rrt['tags_text']) && (empty($rrt['tags']) || $rrt['tags'] === null)) {
                            $mup = $db->prepare('UPDATE locations SET tags = :tags WHERE id = :id');
                            $mup->bindValue(':tags', $rrt['tags_text']);
                            $mup->bindValue(':id', $newId, PDO::PARAM_INT);
                            try {
                                $mup->execute();
                            } catch (Exception $e) { /* ignore */
                            }
                        }
                    }
                } catch (Exception $e) { /* ignore */
                }
            } catch (Exception $e) {
                // update failed, but import succeeded; return result with warning
                // Attempt to continue to post-import backfill even if admin update failed
                try {
                    $this->postImportBackfill($db, $newId, $name, $tagsText, $type, $inferredLogo);
                } catch (Exception $ex) {
                }
                if ($internal) return ['ok' => true,'id' => $newId,'warning' => 'backfill_update_failed','msg' => $e->getMessage(),'backfill' => ['city' => $foundCity,'state' => $foundState,'country' => $foundCountry],'debug' => ['admin_query' => $adminQueryDebug]];
                echo json_encode(['ok' => true,'id' => $newId,'warning' => 'backfill_update_failed','msg' => $e->getMessage(),'backfill' => ['city' => $foundCity,'state' => $foundState,'country' => $foundCountry],'debug' => ['admin_query' => $adminQueryDebug]]);
                return;
            }
        }

        // Run a lightweight post-import backfill for this single row to set type/logo when possible.
        try {
            $this->postImportBackfill($db, $newId, $name, $tagsText, $type, $inferredLogo);
        } catch (Exception $e) {
            // ignore post-backfill failures but include debug info
            $adminQueryDebug['post_backfill_error'] = $e->getMessage();
        }

        if ($internal) return ['ok' => true,'id' => $newId,'backfill' => ['city' => $foundCity,'state' => $foundState,'country' => $foundCountry],'debug' => ['admin_query' => $adminQueryDebug]];
        echo json_encode(['ok' => true,'id' => $newId,'backfill' => ['city' => $foundCity,'state' => $foundState,'country' => $foundCountry],'debug' => ['admin_query' => $adminQueryDebug]]);
        return;
    }

    /**
     * Lightweight post-import backfill for a single inserted location row.
     * Attempts to set a more specific `type` and a sensible `logo` when safe.
     */
    private function postImportBackfill($db, $id, $name, $tagsText, $assignedType, $inferredLogo = null)
    {
        if (!$db || !$id) {
            return;
        }
        // Detect whether table has logo column
        $colsList = $db->query("SHOW COLUMNS FROM locations")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($colsList, 'Field');
        $hasLogoCol = in_array('logo', $colNames, true);

        // Normalize tags string to lower for checks
        $tagsLower = is_string($tagsText) ? strtolower($tagsText) : '';
        $nameStr = is_string($name) ? $name : '';

        // Fetch current row to examine logo/type and location coords and address fields
        $s = $db->prepare('SELECT type, logo, osm_id, latitude, longitude, city, state, country FROM locations WHERE id = :id LIMIT 1');
        $s->bindValue(':id', $id, PDO::PARAM_INT);
        $s->execute();
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }

        $currentType = $row['type'] ?? null;
        $currentLogo = $row['logo'] ?? null;

        $updates = [];
        $binds = [];

        // If still generic type, try to infer from tags or name (repeat some importer heuristics)
        if (($currentType === null || strtolower($currentType) === 'poi')) {
            // tags checks
            if (strpos($tagsLower, '"amenity"=>"bank"') !== false || strpos($tagsLower, '"amenity":"bank"') !== false) {
                $updates[] = 'type = :type_val';
                $binds[':type_val'] = 'Bank';
            } elseif (preg_match('/\bfood\s*bank\b/i', $nameStr) || stripos($tagsLower, 'social_facility') !== false) {
                $updates[] = 'type = :type_val';
                $binds[':type_val'] = 'Food Bank';
            } elseif (preg_match('/\b(bank|atm)\b/i', $nameStr)) {
                $updates[] = 'type = :type_val';
                $binds[':type_val'] = 'Bank';
            } elseif (!empty($assignedType) && strtolower($assignedType) !== 'poi') {
                $updates[] = 'type = :type_val';
                $binds[':type_val'] = $assignedType;
            }
        }

        // Set logo when missing and we have an inferred logo or can detect category from tags/name
        if ($hasLogoCol && (is_null($currentLogo) || trim($currentLogo) === '' || strtolower(trim($currentLogo)) === 'poi.png')) {
            if ($inferredLogo) {
                $updates[] = 'logo = :logo_val';
                $binds[':logo_val'] = $inferredLogo;
            } else {
                // Try explicit category detection from tags JSON/text and name string
                // Prefer charging station icon for charging points
                $detected = null;
                // helper closures
                $hasTag = function($k, $v) use ($tagsLower) {
                    return (strpos($tagsLower, '"' . $k . '"') !== false && (strpos($tagsLower, '"' . $v . '"') !== false || strpos($tagsLower, '=>' . '"' . $v . '"') !== false));
                };
                // Detect categories and set type/logo accordingly when possible
                if ($hasTag('tourism', 'hotel') || stripos($nameStr, 'hotel') !== false) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Hotel';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'hotel.png';
                } elseif ($hasTag('tourism', 'attraction') || $hasTag('tourism', 'viewpoint') || $hasTag('tourism', 'zoo') || $hasTag('natural', 'waterfall') || $hasTag('waterway', 'waterfall')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Attraction';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'Attractions.png';
                } elseif ($hasTag('tourism', 'information')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Tourist Info';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'TouristInfo.png';
                } elseif ($hasTag('tourism', 'camp_site') || $hasTag('tourism', 'caravan_site')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Campground';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'campground.png';
                } elseif ($hasTag('leisure', 'park') || $hasTag('leisure', 'nature_reserve') || $hasTag('natural', 'nature_reserve')) {
                    // Nature parks: set specific type and national park logo
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'natureparks';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'national_park.png';
                } elseif ($hasTag('amenity', 'restaurant') || $hasTag('amenity', 'fast_food') || $hasTag('amenity', 'food_court') || $hasTag('shop', 'bakery') || $hasTag('amenity', 'cafe')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Food';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'food.png';
                } elseif ($hasTag('amenity', 'bar') || $hasTag('amenity', 'pub') || $hasTag('amenity', 'nightclub')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Nightlife';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'nightlife.png';
                } elseif ($hasTag('public_transport', 'stop_position') || $hasTag('public_transport', 'station') || $hasTag('amenity', 'bus_station') || $hasTag('highway', 'bus_stop')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Transport';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'Transportation.png';
                } elseif ($hasTag('amenity', 'charging_station') || strpos($tagsLower, 'charging_station') !== false) {
                    // charging stations: prefer a dedicated charging icon if available
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Charging Station';
                    $candCharge = __DIR__ . '/../assets/icons/charging.png';
                    if (file_exists($candCharge)) {
                        $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'charging.png';
                    } else {
                        // fallback to gas station icon if dedicated charging icon missing
                        $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'gas_station.png';
                    }
                } elseif ($hasTag('amenity', 'fuel') || $hasTag('shop', 'fuel')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Fuel';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'gas_station.png';
                } elseif ($hasTag('shop', 'supermarket') || $hasTag('amenity', 'supermarket')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Supermarket';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'supermarket.png';
                } elseif ($hasTag('shop', 'department_store') || strpos($tagsLower, 'department_store') !== false || stripos($nameStr, 'department store') !== false) {
                        // Department stores -> use supermarket icon as fallback
                        $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Supermarket';
                        $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'supermarket.png';
                } elseif ($hasTag('amenity', 'hospital') || $hasTag('amenity', 'pharmacy')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Healthcare';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'Pharmacy.png';
                } elseif ($hasTag('shop', 'laundry') || $hasTag('amenity', 'laundry')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Laundry';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'Laundry.png';
                } elseif ($hasTag('leisure', 'fitness_centre') || $hasTag('leisure', 'sports_hall') || $hasTag('leisure', 'sports_centre') || $hasTag('leisure', 'fitness_station')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Fitness';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'Fitness.png';
                } elseif ($hasTag('shop', 'cannabis')) {
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Cannabis';
                    $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'Cannabis.png';
                } elseif ($hasTag('shop', 'tobacco') || $hasTag('shop', 'e-cigarette')) {
                    // Tobacco/vape: set type and use existing tobaccoVape.png icon when present
                    $updates[] = 'type = :type_val'; $binds[':type_val'] = 'Tobacco';
                    $candTv = __DIR__ . '/../assets/icons/tobaccoVape.png';
                    if (file_exists($candTv)) {
                        $updates[] = 'logo = :logo_val'; $binds[':logo_val'] = 'tobaccoVape.png';
                    }
                }
                // If any logo/type binds were added above they will be applied below
            }
        }

        if (!empty($updates)) {
            $sql = 'UPDATE locations SET ' . implode(', ', $updates) . ' WHERE id = :id';
            $upd = $db->prepare($sql);
            foreach ($binds as $k => $v) {
                $upd->bindValue($k, $v);
            }
            $upd->bindValue(':id', $id, PDO::PARAM_INT);
            $upd->execute();

            // attempt to log the change
            try {
                if (file_exists(__DIR__ . '/../helpers/backfill.php')) {
                    require_once __DIR__ . '/../helpers/backfill.php';
                }
                $changes = [];
                foreach ($binds as $k => $v) {
                    $field = ltrim($k, ':');
                    $changes[$field] = $v;
                }
                $logEntry = [
                    'location_id' => $id,
                    'osm_id' => $row['osm_id'] ?? null,
                    'previous' => ['type' => $row['type'] ?? null, 'logo' => $row['logo'] ?? null],
                    'changes' => $changes,
                ];
                if (function_exists('log_backfill')) {
                    log_backfill($logEntry);
                }
            } catch (Exception $e) { /* ignore logging errors */
            }
        }

        // Instead of performing an immediate admin polygon lookup here, enqueue a backfill task
        // so the potentially slow overpass lookup can be processed asynchronously by a worker.
        try {
            $lat = isset($row['latitude']) ? (float)$row['latitude'] : null;
            $lon = isset($row['longitude']) ? (float)$row['longitude'] : null;
            // Only enqueue when coordinates are available and at least one of city/state/country is missing
            if (($lat !== null && $lon !== null) && (empty($row['city']) || empty($row['state']) || empty($row['country']))) {
                // detect if backfill_queue table exists before insert
                try {
                    $qCheck = $db->query("SHOW TABLES LIKE 'backfill_queue'")->fetchAll(PDO::FETCH_ASSOC);
                    if ($qCheck) {
                        $ins = $db->prepare('INSERT INTO backfill_queue (location_id, osm_id, lat, lon, status, attempts, payload, created_at, updated_at) VALUES (:lid, :osm, :lat, :lon, :status, :attempts, :payload, NOW(), NOW())');
                        $ins->bindValue(':lid', $id, PDO::PARAM_INT);
                        $ins->bindValue(':osm', $row['osm_id'] ?? null, PDO::PARAM_INT);
                        $ins->bindValue(':lat', $lat);
                        $ins->bindValue(':lon', $lon);
                        $ins->bindValue(':status', 'pending');
                        $ins->bindValue(':attempts', 0, PDO::PARAM_INT);
                        $payload = json_encode(['reason' => 'post_import', 'created_by' => 'importer']);
                        $ins->bindValue(':payload', $payload);
                        $ins->execute();
                        // log enqueue
                        try {
                            if (file_exists(__DIR__ . '/../helpers/backfill.php')) {
                                require_once __DIR__ . '/../helpers/backfill.php';
                            } if (function_exists('log_backfill')) {
                                log_backfill(['action' => 'enqueue_admin_backfill', 'location_id' => $id, 'osm_id' => $row['osm_id'] ?? null]);
                            }
                        } catch (Exception $e) {
                        }
                    }
                } catch (Exception $e) {
                    // if queue isn't available, ignore and continue silently
                }
            }
        } catch (Exception $e) { /* ignore enqueue errors */
        }
    }

    // Simple JSON API: /api/locations?page=1&per_page=20
    public function apiList()
    {
        // Pagination defaults
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(5, min(200, (int)($_GET['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;

        // Optional bbox filter: we accept bbox=lat1,lon1,lat2,lon2 (south,west,north,east)
        $params = [];
        $where = '';

        // Detect schema columns: prefer latitude/longitude columns, fall back to POINT(coordinates)
        $colQuery = $this->pdo->query("SHOW COLUMNS FROM locations")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($colQuery, 'Field');
        $hasLatLon = in_array('latitude', $colNames, true) && in_array('longitude', $colNames, true);
        // legacy `coordinates` column may contain a POINT/geometry in spatial DB setups.
        // Spatial DB functions (ST_X/ST_Y) are not available in our MySQL-only runtime,
        // so we never rely on ST_* here. If latitude/longitude columns are missing,
        // API will return rows with NULL coords and bbox queries will yield no results.
        $hasCoordinates = in_array('coordinates', $colNames, true);

        if (!empty($_GET['bbox'])) {
            $parts = array_map('trim', explode(',', $_GET['bbox']));
            if (count($parts) === 4) {
                // Expect lat1,lon1,lat2,lon2 and normalize
                $f = array_map('floatval', $parts);
                $minLat = min($f[0], $f[2]);
                $maxLat = max($f[0], $f[2]);
                $minLon = min($f[1], $f[3]);
                $maxLon = max($f[1], $f[3]);

                if ($hasLatLon) {
                    $where = 'WHERE latitude BETWEEN :minLat AND :maxLat AND longitude BETWEEN :minLon AND :maxLon';
                } else {
                    // No usable lat/lon columns available; cannot satisfy bbox filter.
                    // Return empty result set for bbox queries rather than invoking a spatial DB.
                    $where = 'WHERE 0';
                }
                $params = [':minLat' => $minLat, ':maxLat' => $maxLat, ':minLon' => $minLon, ':maxLon' => $maxLon];
            }
        }

        // Build select clause based on schema
        if ($hasLatLon) {
            $selectCoords = 'latitude, longitude';
        } else {
            // Do not attempt to call spatial DB ST_* functions. Expose NULL coords
            // when latitude/longitude columns are not present.
            $selectCoords = 'NULL AS latitude, NULL AS longitude';
        }

        // Provide empty placeholders for description/city/country if they don't exist in the schema.
        $sql = "SELECT id, name, type, '' AS description, $selectCoords, '' AS city, '' AS country FROM locations $where ORDER BY id DESC LIMIT :limit OFFSET :offset";
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                // lat/lon are decimal, bind as string to keep precision
                $stmt->bindValue($k, $v);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: application/json');
            echo json_encode(['page' => $page, 'per_page' => $perPage, 'data' => $rows]);
            exit;
        } catch (Throwable $e) {
            // Log and return JSON error (avoid HTML fatal page)
            error_log('API /api/locations error: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
            exit;
        }
    }
}
