<?php
/**
 * LocationService - Centralized API service for location/POI operations
 * 
 * Handles:
 * - POI list retrieval (using Location model)
 * - POI search (PostGIS, using Location model)
 * - POI import from OpenStreetMap (using Location model)
 * - Unified error responses
 */

require_once __DIR__ . '/../models/Location.php';

class LocationService
{
    private $pdo;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo) {
            $this->pdo = $pdo;
        } else {
            if (file_exists(__DIR__ . '/../config/mysql.php')) {
                require_once __DIR__ . '/../config/mysql.php';
                $this->pdo = get_db();
            }
        }
    }

    /**
     * Get paginated list of locations from MySQL using Location model
     * 
     * Returns JSON with pagination info and location data
     */
    public function listLocations(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->pdo) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Database connection failed'
            ]);
            return;
        }

        try {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $perPage;
            $search = trim($_GET['search'] ?? '');

            // Use Location model to fetch locations
            $locations = [];
            if ($search !== '') {
                $locations = Location::search($this->pdo, $search, $perPage);
            } else {
                $locations = Location::findAll($this->pdo, $perPage, $offset);
            }

            // Convert to array format
            $data = array_map(fn($loc) => $loc->toArray(), $locations);

            echo json_encode([
                'success' => true,
                'page' => $page,
                'per_page' => $perPage,
                'data' => $data
            ]);
        } catch (Throwable $e) {
            error_log('LocationService::listLocations - ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Failed to retrieve locations'
            ]);
        }
    }

    /**
     * Search locations using PostGIS
     * 
     * Expects query parameters:
     * - bbox: "minLon,minLat,maxLon,maxLat"
     * - search: text search (optional)
     * 
     * Returns JSON array of POI features
     */
    public function searchPostgis(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(410);
        echo json_encode([
            'ok' => false,
            'error' => 'postgis_removed',
            'msg' => 'PostGIS-backed search removed. Use Overpass-backed search endpoints.'
        ]);
        return;
    }

    /**
     * Import a single POI from OpenStreetMap
     * 
     * Expects POST:
     * - osm_id: OpenStreetMap feature ID
     * - osm_type: "node", "way", or "relation"
     * - csrf_token: CSRF token for security
     * 
     * Returns JSON with import result
     */
    public function importPoi(): void
    {
        // Delegate import to the Overpass-backed controller implementation.
        // LocationController::import already implements Overpass lookup, tag parsing and MySQL insert.
        if (!class_exists('\LocationController')) {
            require_once __DIR__ . '/../controllers/LocationController.php';
        }
        try {
            $ctrl = new LocationController();
            // This will echo JSON and return; keep behavior identical for callers.
            $ctrl->import(null, false);
            return;
        } catch (Throwable $e) {
            error_log('LocationService::importPoi delegate failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Import delegation failed']);
            return;
        }

        // If delegation didn't return, run the manual import flow inside a try
        try {
            // Reverse geocode to fill city/state/country when possible
            $city = null; $state = null; $country = null;
            if (function_exists('reverse_geocode_location') && is_numeric($latitude) && is_numeric($longitude)) {
                try {
                    $rg = reverse_geocode_location($latitude, $longitude, $pg);
                    if (is_array($rg)) {
                        $city = $rg['city'] ?? null;
                        $state = $rg['state'] ?? null;
                        $country = $rg['country'] ?? null;
                    }
                } catch (Throwable $e) {}
            }

            // Build a normalized tags-lower map for easy checking
            $tLower = [];
            foreach ($tags as $k => $v) {
                $tLower[strtolower($k)] = $v;
            }

            // Map tags/name to application category keys used in filters
            $mapType = 'poi';
            // shop-based
            $shop = $tLower['shop'] ?? '';
            $amenity = $tLower['amenity'] ?? '';
            $tourism = $tLower['tourism'] ?? '';
            $leisure = $tLower['leisure'] ?? '';
            $brand = $tLower['brand'] ?? ($tLower['operator'] ?? '');

            // If no specific map type yet, try to derive from amenity/shop values
            $derived = '';
            if ($amenity) $derived = $amenity;
            elseif ($shop) $derived = $shop;
            elseif ($tourism) $derived = $tourism;
            elseif ($leisure) $derived = $leisure;

            if ($amenity === 'fuel' || $shop === 'fuel') {
                $mapType = 'gas_stations';
            } elseif ($amenity === 'bank' || strtolower($shop) === 'bank' || strtolower($amenity) === 'atm') {
                $mapType = 'banks';
            } elseif ($tourism === 'hotel' || $amenity === 'hotel') {
                $mapType = 'hotels';
            } elseif ($amenity === 'restaurant' || $tourism === 'restaurant' || $shop === 'restaurant' || $amenity === 'cafe') {
                $mapType = 'restaurants';
            } elseif ($shop === 'supermarket' || strtolower($shop) === 'supermarket') {
                $mapType = 'supermarket';
            } elseif ($leisure && in_array($leisure, ['fitness_centre','sports_centre','swimming_pool','stadium'])) {
                $mapType = 'fitness';
            } elseif ($tourism === 'attraction' || $amenity === 'museum' || $tourism === 'museum') {
                $mapType = 'attractions';
            } elseif ($shop && in_array($shop, ['convenience','bakery','butcher','deli'])) {
                $mapType = 'food';
            } elseif ($derived && in_array($derived, ['cafe','bar','pub','ice_cream','fast_food'])) {
                $mapType = 'restaurants';
            } elseif ($derived && in_array($derived, ['restaurant'])) {
                $mapType = 'restaurants';
            } elseif ($shop && in_array($shop, ['laundry'])) {
                $mapType = 'laundry';
            } elseif ($amenity === 'pharmacy' || $shop === 'pharmacy') {
                $mapType = 'pharmacy';
            } elseif ($shop && in_array($shop, ['tobacco','e-cigarette','vape'])) {
                // normalize tobacco/vape shops to 'tobacco' category
                $mapType = 'tobacco';
            } elseif ($shop && in_array($shop, ['cannabis'])) {
                $mapType = 'cannabis';
            } elseif ($amenity === 'parking' || ($tLower['parking'] ?? '') === 'yes') {
                $mapType = 'parking';
            } elseif ($amenity === 'sanitary_dump_station' || ($tLower['sanitary_dump_station'] ?? '') === 'yes') {
                $mapType = 'dump_station';
            } elseif ($shop) {
                // generic shopping
                $mapType = 'shopping';
            }

            // Choose a logo filename for known categories (optional)
            $logo = null;
            $logoMap = [
                'gas_stations' => 'gas_station.png',
                'supermarket' => 'supermarket.png',
                'banks' => 'bank.png',
                'hotels' => 'hotel.png',
                'restaurants' => 'restaurant.png',
                'food' => 'food.png',
                'attractions' => 'Attractions.png',
                'fitness' => 'Fitness.png',
                'laundry' => 'Laundry.png',
                'tourist_info' => 'TouristInfo.png',
                'shopping' => 'shopping.png',
                'tobacco_vape' => 'TabacoVape.png',
                'tobacco' => 'TabacoVape.png',
                'parking' => 'Parking.png',
                'pharmacy' => 'Pharmacy.png',
                'dump_station' => 'dump_station.png',
                'cannabis' => 'Cannabis.png'
            ];
            // prefer exact mapType icon, then try derived amenity/shop keys
            if (isset($logoMap[$mapType])) {
                $logo = $logoMap[$mapType];
            } else {
                $lk = strtolower($mapType);
                if (isset($logoMap[$lk])) $logo = $logoMap[$lk];
                elseif ($amenity && isset($logoMap[$amenity])) $logo = $logoMap[$amenity];
                elseif ($shop && isset($logoMap[$shop])) $logo = $logoMap[$shop];
            }

            // Insert into MySQL locations table: detect available columns and map values
            if (!$this->pdo) {
                throw new Exception('MySQL database not available');
            }

            $baseCols = ['name', 'type', 'latitude', 'longitude', 'coordinates', 'osm_id'];

            // Fetch actual DB columns
            $colsList = [];
            try {
                $colsList = $this->pdo->query("SHOW COLUMNS FROM locations")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $colsList = [];
            }
            $colNames = array_column($colsList, 'Field');

            // Build value map from tags, feature and reverse geocode results
            $valueMap = [];
            $valueMap['name'] = $feature['name'] ?? '';
            // Normalize type: map internal categories to display labels
            $typeLabelMap = [
                'restaurants' => 'Food',
                'food' => 'Food',
                'supermarket' => 'Food'
            ];
            $valueMap['type'] = $mapType ? ($typeLabelMap[$mapType] ?? ucwords(str_replace('_', ' ', $mapType))) : ($feature['name'] ?? 'Poi');
            $valueMap['latitude'] = is_numeric($latitude) ? $latitude : null;
            $valueMap['longitude'] = is_numeric($longitude) ? $longitude : null;
            $valueMap['osm_id'] = $osmId;

            // Coordinates string (lat,lon) stored in `coordinates` varchar column
            if (is_numeric($latitude) && is_numeric($longitude)) {
                $valueMap['coordinates'] = $latitude . ',' . $longitude;
            }

            $getTag = function($k) use ($tLower, $tags) {
                $lk = strtolower($k);
                if (isset($tLower[$lk]) && $tLower[$lk] !== '') return $tLower[$lk];
                if (isset($tags[$k]) && $tags[$k] !== '') return $tags[$k];
                return null;
            };

            $valueMap['description'] = $tags['description'] ?? null;
            // prefer explicit addr:city/tag, then common city-like tags, then reverse geocode, then feature city
            $valueMap['city'] = $getTag('addr:city') ?? $getTag('city') ?? $getTag('town') ?? $getTag('village') ?? $city ?? ($feature['city'] ?? null);
            $valueMap['state'] = $state ?? null;
            $valueMap['country'] = $country ?? ($feature['country'] ?? null);
            // determine logo: prefer category-based logo, otherwise try explicit tag or brand-derived icon
            $valueMap['logo'] = $logo ?? $getTag('logo') ?? null;
            // if still no logo, try derive from brand/operator tag and existing icon files (multiple extensions)
            if (empty($valueMap['logo'])) {
                $brandTag = $getTag('brand') ?? $getTag('operator') ?? null;
                if (!empty($brandTag)) {
                    // normalize candidate base name
                    $candidateBase = preg_replace('/[^A-Za-z0-9_\-]/', '', strtolower($brandTag));
                    $tryExts = ['.svg', '.png', '-logo.svg', '-logo.png', '.jpg', '.jpeg'];
                    $iconsDir = realpath(__DIR__ . '/../icons') ?: (__DIR__ . '/../icons');
                    $found = null;
                    $tried = [];
                    foreach ($tryExts as $ext) {
                        $fn = $candidateBase . $ext;
                        $tried[] = $fn;
                        if ($iconsDir && file_exists($iconsDir . DIRECTORY_SEPARATOR . $fn)) {
                            $found = $fn;
                            break;
                        }
                    }
                    if ($found) {
                        $valueMap['logo'] = $found;
                    } else {
                        // Log missing candidates so icons can be added later
                        error_log(sprintf('LocationService::importPoi - logo candidates not found for brand "%s"; tried: %s; iconsDir=%s', $brandTag, implode(',', $tried), $iconsDir));
                    }
                }
            }
            // Do not populate the raw `tags` column; map tags to their dedicated fields instead
            $valueMap['website'] = $getTag('website');
            $valueMap['brand'] = $getTag('brand');
            $valueMap['operator'] = $getTag('operator');
            $valueMap['addr_street'] = $getTag('addr:street');
            $valueMap['phone'] = $getTag('phone');
            $valueMap['opening_hours'] = $getTag('opening_hours');
            $valueMap['opening_hou'] = $valueMap['opening_hours'];
            $valueMap['addr_postco'] = $getTag('addr:postcode') ?? $getTag('postcode') ?? null;
            $valueMap['alt_name'] = $getTag('alt_name') ?? null;
            $valueMap['old_name'] = $getTag('old_name') ?? null;
            $valueMap['short_name'] = $getTag('short_name') ?? null;
            $valueMap['email'] = $getTag('email') ?? null;
            $valueMap['website_en'] = $getTag('website:en') ?? null;
            $valueMap['website_fr'] = $getTag('website:fr') ?? null;
            $valueMap['brand_wikid'] = $getTag('brand:wikidata') ?? null;
            $valueMap['brand_wikip'] = $getTag('brand:wikipedia') ?? null;
            $valueMap['operator_wi'] = $getTag('operator:wikidata') ?? null;
            $valueMap['operator_wi1'] = $getTag('operator:wikipedia') ?? null;
            $valueMap['addr_city'] = $valueMap['city'];
            $valueMap['addr_unit'] = $getTag('addr:unit') ?? null;
            // If user is authenticated, set user_id to attribute the import
            if (session_status() !== PHP_SESSION_ACTIVE) {
                if (function_exists('start_secure_session')) {
                    start_secure_session();
                } else {
                    @session_start();
                }
            }
            $userId = $_SESSION['user_id'] ?? null;
            // Fallback: if the app session wasn't started under the app's session name
            // but a PHPSESSID cookie exists (common when login was performed without
            // the app's custom session helper), try to read that session for user_id.
            if (empty($userId) && !empty($_COOKIE['PHPSESSID'])) {
                $prevName = session_name();
                $prevId = session_id();
                // close current session to avoid conflicts
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                // Read PHPSESSID session
                session_name('PHPSESSID');
                session_id($_COOKIE['PHPSESSID']);
                @session_start();
                $phpSessUser = $_SESSION['user_id'] ?? null;
                // close PHPSESSID session
                session_write_close();
                // restore previous session
                session_name($prevName);
                if (!empty($prevId)) {
                    session_id($prevId);
                    @session_start();
                }
                if (!empty($phpSessUser)) {
                    $userId = $phpSessUser;
                }
            }
            $valueMap['user_id'] = is_numeric($userId) ? (int)$userId : null;

            // If city was not resolved, log helpful context for later adjustments
            if (empty($valueMap['city'])) {
                $tagKeys = array_keys($tags);
                error_log(sprintf('LocationService::importPoi - city not set for osm=%s name="%s" tags_keys=%s reverse_geocode_city=%s', $osmId, $feature['name'] ?? '', implode(',', $tagKeys), $city ?? '')); 
            }

            // Determine columns to insert
            $cols = $baseCols;
            $binds = [];
            foreach ($colNames as $cname) {
                if (in_array($cname, ['id','created_at'], true)) continue;
                if (array_key_exists($cname, $valueMap)) {
                    if (!in_array($cname, $cols, true)) $cols[] = $cname;
                    $binds[$cname] = $valueMap[$cname];
                }
            }

            $placeholders = array_map(function($c){ return ':' . $c; }, $cols);
            $sql = 'INSERT INTO locations (' . implode(', ', $cols) . ', created_at) VALUES (' . implode(', ', $placeholders) . ', NOW())';

            $stmt = $this->pdo->prepare($sql);
            error_log('LocationService::importPoi SQL: ' . $sql);
            // Debug: log the columns and bound values when APP_DEBUG is enabled
            $appDebug = getenv('APP_DEBUG') ?: null;
            if (!empty($appDebug) && ($appDebug === '1' || strtolower($appDebug) === 'true')) {
                $debugSample = ['cols' => $cols, 'binds' => array_intersect_key($binds, array_flip($cols))];
                // mask long text
                foreach ($debugSample['binds'] as $k => $v) {
                    if (is_string($v) && strlen($v) > 200) $debugSample['binds'][$k] = substr($v,0,200) . '...';
                }
                error_log('LocationService::importPoi binds: ' . json_encode($debugSample));
            }

            // Bind values (use integer binding for osm_id and other integer-like columns)
            $intCols = ['osm_id','user_id','addr_unit','capacity','ref_walmart','building_le','protect_cla'];
            foreach ($cols as $col) {
                $param = ':' . $col;
                $v = null;
                if (array_key_exists($col, $binds)) $v = $binds[$col];
                if ($v === null || $v === '') {
                    $stmt->bindValue($param, null, PDO::PARAM_NULL);
                } else {
                    if (in_array($col, $intCols, true)) {
                        // Only bind as integer when the value is numeric; otherwise bind NULL
                        if (is_numeric($v)) {
                            $stmt->bindValue($param, (int)$v, PDO::PARAM_INT);
                        } else {
                            $stmt->bindValue($param, null, PDO::PARAM_NULL);
                        }
                    } else {
                        $stmt->bindValue($param, $v, PDO::PARAM_STR);
                    }
                }
            }

            $ok = $stmt->execute();
            if (!$ok) {
                $err = $stmt->errorInfo();
                error_log('LocationService::importPoi execute failed: ' . json_encode($err));
            } else {
                // Log DB diagnostics to ensure the insert went to the expected database
                $dbName = null;
                try {
                    $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
                } catch (Throwable $e) {
                    $dbName = 'unknown';
                }
                $affected = null;
                try { $affected = $stmt->rowCount(); } catch (Throwable $e) { $affected = null; }
                $insertId = null;
                try { $insertId = $this->pdo->lastInsertId(); } catch (Throwable $e) { $insertId = null; }
                // If lastInsertId() returned empty/zero, log extra diagnostics to help investigate drivers/schema
                if (empty($insertId) || $insertId === '0' || $insertId === 0) {
                    try {
                        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                    } catch (Throwable $e) { $driver = 'unknown'; }
                    try {
                        $serverVer = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
                    } catch (Throwable $e) { $serverVer = 'unknown'; }
                    try {
                        $emulate = $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) ? 'true' : 'false';
                    } catch (Throwable $e) { $emulate = 'unknown'; }
                    // Try SELECT LAST_INSERT_ID() on this connection
                    $lastIdConn = null;
                    try {
                        $r = $this->pdo->query('SELECT LAST_INSERT_ID() AS li')->fetch(PDO::FETCH_ASSOC);
                        $lastIdConn = $r['li'] ?? null;
                    } catch (Throwable $e) { $lastIdConn = null; }
                    // Check table auto_increment value (SHOW TABLE STATUS)
                    $ai = null;
                    try {
                        $tbl = $this->pdo->quote('locations');
                        $q = $this->pdo->query('SHOW TABLE STATUS LIKE ' . $tbl);
                        $st = $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
                        $ai = $st['Auto_increment'] ?? null;
                    } catch (Throwable $e) { $ai = null; }

                    error_log(sprintf('LocationService::importPoi DIAG lastInsertId empty; driver=%s server_version=%s emulate_prepares=%s last_insert_id_conn=%s table_auto_inc=%s', $driver, $serverVer, $emulate, var_export($lastIdConn, true), var_export($ai, true)));
                }
                error_log(sprintf('LocationService::importPoi execute succeeded; db=%s affected=%s lastInsertId=%s', $dbName, var_export($affected, true), var_export($insertId, true)));
            }

            // Try fetch inserted id (if driver supports RETURNING), otherwise use lastInsertId
            $newId = null;
            try {
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($res && isset($res['id'])) $newId = $res['id'];
            } catch (Throwable $e) {
                // ignore
            }
            if (empty($newId)) {
                try { $newId = (int)$this->pdo->lastInsertId(); } catch (Throwable $e) { $newId = null; }
            }
            // Ensure we return a meaningful type and id. If lastInsertId() is 0 or empty,
            // try to find the inserted row by `osm_id` or by (name, latitude, longitude).
            $typeToInsert = $valueMap['type'] ?? null;
            if (empty($newId) || $newId === 0 || $newId === '0') {
                // Try lookup by osm_id first
                if (!empty($valueMap['osm_id'])) {
                    try {
                        $s = $this->pdo->prepare('SELECT id FROM locations WHERE osm_id = :osm_id LIMIT 1');
                        $s->execute([':osm_id' => (int)$valueMap['osm_id']]);
                        $r = $s->fetch(PDO::FETCH_ASSOC);
                        if ($r && isset($r['id'])) $newId = (int)$r['id'];
                    } catch (Throwable $e) {
                        // ignore lookup error
                    }
                }
            }
            if (empty($newId) || $newId === 0 || $newId === '0') {
                // Fallback: match by name + coords (best-effort)
                try {
                    $s = $this->pdo->prepare('SELECT id FROM locations WHERE name = :name AND latitude = :lat AND longitude = :lon ORDER BY id DESC LIMIT 1');
                    $s->execute([':name' => $valueMap['name'] ?? '', ':lat' => $valueMap['latitude'], ':lon' => $valueMap['longitude']]);
                    $r = $s->fetch(PDO::FETCH_ASSOC);
                    if ($r && isset($r['id'])) $newId = (int)$r['id'];
                } catch (Throwable $e) {
                    // ignore
                }
            }

            if (!isset($typeToInsert)) {
                $typeToInsert = $valueMap['type'] ?? null;
            }
            echo json_encode([
                'success' => true,
                'id' => $newId ?? 0,
                'name' => $feature['name'] ?? '',
                'latitude' => $latitude,
                'longitude' => $longitude,
                'type' => $typeToInsert
            ]);
        } catch (Throwable $e) {
            error_log('LocationService::importPoi - ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Import failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Build the INSERT SQL for the given OSM feature and return diagnostics
     * This does not execute the statement — it reports which DB columns would be used
     * and which expected columns are missing.
     */
    public function debugImportSql(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // Only allow POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
            return;
        }

        $osmId = isset($_POST['osm_id']) ? trim($_POST['osm_id']) : null;
        $osmType = isset($_POST['osm_type']) ? trim($_POST['osm_type']) : 'node';

        if (!$osmId || !is_numeric($osmId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid OSM ID']);
            return;
        }

        // PostGIS debug endpoint removed — use Overpass import debug tools instead.
        http_response_code(410);
        echo json_encode([
            'success' => false,
            'error' => 'postgis_removed',
            'msg' => 'PostGIS lookup removed. Use import_from_overpass_fast.php or LocationController::import for Overpass-based debug.'
        ]);
        return;

        // Build optionalMap like importPoi
        $city = null; $state = null; $country = null;
        if (function_exists('reverse_geocode_location') && is_numeric($feature['latitude'] ?? null) && is_numeric($feature['longitude'] ?? null)) {
            try { $rg = reverse_geocode_location((float)$feature['latitude'], (float)$feature['longitude'], $pg); if (is_array($rg)) { $city = $rg['city'] ?? null; $state = $rg['state'] ?? null; $country = $rg['country'] ?? null; } } catch (Throwable $e) {}
        }

        $tLower = [];
        foreach ($tags as $k => $v) $tLower[strtolower($k)] = $v;

        $optionalMap = [
            'description' => $tags['description'] ?? '',
            'city' => $tLower['addr:city'] ?? $city ?? ($feature['city'] ?? ''),
            'state' => $state ?? null,
            'country' => $country ?? ($feature['country'] ?? ''),
            'logo' => null,
            'website' => $tLower['website'] ?? ($tags['website'] ?? null),
            'brand' => $tLower['brand'] ?? ($tags['brand'] ?? null),
            'operator' => $tLower['operator'] ?? ($tags['operator'] ?? null),
            'addr:street' => $tLower['addr:street'] ?? ($tags['addr:street'] ?? null),
            'addr:postcode' => $tLower['addr:postcode'] ?? ($tags['addr:postcode'] ?? null),
            'phone' => $tLower['phone'] ?? ($tags['phone'] ?? null),
            'opening_hours' => $tLower['opening_hours'] ?? ($tags['opening_hours'] ?? null),
            'coordinates' => null,
            'osm_id' => $osmId,
            'user_id' => null
        ];

        $cols = ['name','type','latitude','longitude','coordinates','osm_id'];
        $foundOptional = [];
        // get existing columns
        try {
            $colsList = $this->pdo->query("SHOW COLUMNS FROM locations")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_column($colsList, 'Field');
        } catch (Throwable $e) {
            $colNames = [];
        }

        foreach ($optionalMap as $col => $val) {
            $dbCol = $col;
            if (strpos($dbCol, ':') !== false) $dbCol = str_replace(':', '_', $dbCol);
            if (in_array($dbCol, $colNames, true)) {
                $cols[] = $dbCol;
                $foundOptional[] = $dbCol;
            }
        }

        $placeholders = array_map(function($c){ return ':' . $c; }, $cols);
        $sql = 'INSERT INTO locations (' . implode(', ', $cols) . ', created_at) VALUES (' . implode(', ', $placeholders) . ', NOW())';

        $missing = [];
        // Determine expected DB columns from optionalMap
        $expectedDbCols = [];
        foreach (array_keys($optionalMap) as $col) {
            $dbCol = $col;
            if (strpos($dbCol, ':') !== false) $dbCol = str_replace(':', '_', $dbCol);
            $expectedDbCols[] = $dbCol;
            if (!in_array($dbCol, $colNames, true)) $missing[] = $dbCol;
        }

        echo json_encode([
            'success' => true,
            'sql' => $sql,
            'insert_columns' => $cols,
            'found_optional_columns' => $foundOptional,
            'db_columns' => $colNames,
            'expected_optional_columns' => $expectedDbCols,
            'missing_optional_columns' => $missing
        ]);
    }

    /**
     * Check if a column exists in a table
     */
    private function columnExists(string $table, string $column): bool
    {
        if (!$this->pdo) {
            return false;
        }

        try {
            $sql = "SELECT 1 FROM $table LIMIT 0";
            $stmt = $this->pdo->query($sql);
            
            // Get column count and names
            $columns = range(0, $stmt->columnCount() - 1);
            foreach ($columns as $i) {
                $meta = $stmt->getColumnMeta($i);
                if ($meta['name'] === $column) {
                    return true;
                }
            }
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }
}
