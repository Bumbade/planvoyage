<?php

// src/api/locations/search.php
// Simple search endpoint for locations used by the typeahead.

require_once __DIR__ . '/../../config/mysql.php';
// start session so we can respect user-scoped filtering when requested
if (file_exists(__DIR__ . '/../../helpers/session.php')) {
    require_once __DIR__ . '/../../helpers/session.php';
    start_secure_session();
}
// Load auth helper so admins can see all POIs
if (file_exists(__DIR__ . '/../../helpers/auth.php')) {
    require_once __DIR__ . '/../../helpers/auth.php';
}
// optional import debug logger for missing asset reporting
if (file_exists(__DIR__ . '/../../helpers/import_log.php')) {
    require_once __DIR__ . '/../../helpers/import_log.php';
}
header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? $_GET['search'] ?? ''));
$typeFilter = trim($_GET['type'] ?? '');
// support multiple types as CSV when filtering from frontend (e.g. types=food,hotel)
$typesFilter = trim($_GET['types'] ?? '');
$countryFilter = trim($_GET['country'] ?? '');
$stateFilter = trim($_GET['state'] ?? '');
$limit = isset($_GET['limit']) ? min(200, max(1, (int)$_GET['limit'])) : 50;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
// compute page for consistent response shape
$page = $limit > 0 ? (int)(floor($offset / $limit) + 1) : 1;

// Support direct fetch by id: /api/locations?id=123
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Optional bounding box: lat1,lon1,lat2,lon2 (south,west,north,east)
$bbox = isset($_GET['bbox']) ? trim($_GET['bbox']) : null;
// Optional filter: when set (mine=1 or user_only=1) restrict results to current session user_id
$explicitFilterByUser = (isset($_GET['mine']) && $_GET['mine']) || (isset($_GET['user_only']) && $_GET['user_only']) || (isset($_GET['only_mine']) && $_GET['only_mine']);
// Default behaviour: if a user is logged in and NOT an admin, show only their POIs
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$filterByUser = $explicitFilterByUser || ($sessionUserId > 0 && !(function_exists('is_admin_user') && is_admin_user()));
$currentUserId = $filterByUser ? $sessionUserId : 0;

// Initialize $sql early to avoid undefined variable in error handler
$sql = '';

try {
    $db = get_db();

        // Inspect favorites indexes so we can optionally FORCE INDEX if available.
        $favIndexes = [];
        try {
            $ri = $db->query("SHOW INDEX FROM favorites")->fetchAll(PDO::FETCH_ASSOC);
            $favIndexes = array_column($ri, 'Key_name');
        } catch (Exception $e) {
            // ignore: favorites table may not exist in some environments
            $favIndexes = [];
        }
        $hasFavoritesIndex = in_array('idx_favorites_user_loc', $favIndexes, true);

    // Detect schema: prefer separate latitude/longitude columns, fall back to POINT 'coordinates'
    $cols = $db->query("SHOW COLUMNS FROM locations")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($cols, 'Field');
    $hasLatLon = in_array('latitude', $colNames, true) && in_array('longitude', $colNames, true);
    $hasCoordinates = in_array('coordinates', $colNames, true);
    // Detect whether a raw tags column exists (hstore or json stored as text)
    $hasTags = in_array('tags', $colNames, true) || in_array('tags_json', $colNames, true) || in_array('tags_text', $colNames, true);
    // detect optional state column
    $hasState = in_array('state', $colNames, true);
    // pick best tags column for text searching when present
    $tagsColumn = null;
    if (in_array('tags_text', $colNames, true)) $tagsColumn = 'tags_text';
    elseif (in_array('tags', $colNames, true)) $tagsColumn = 'tags';
    elseif (in_array('tags_json', $colNames, true)) $tagsColumn = 'tags_json';

    // Prepare SQL parts depending on schema
    if ($hasLatLon) {
        $selectCoords = 'latitude, longitude';
    } elseif ($hasCoordinates) {
        $selectCoords = 'ST_Y(coordinates) AS latitude, ST_X(coordinates) AS longitude';
    } else {
        // No coordinate columns - still return rows but with null coords
        $selectCoords = 'NULL AS latitude, NULL AS longitude';
    }
    // include logo column if present
    $hasLogo = in_array('logo', $colNames, true);
    // include tags column if present
    $selectTags = $hasTags ? ', tags' : '';
    // include osm id/type if present in schema so frontend can dedupe with Overpass/PostGIS results
    $selectOsm = '';
    if (in_array('osm_id', $colNames, true)) $selectOsm .= ', osm_id';
    if (in_array('osm_type', $colNames, true)) $selectOsm .= ', osm_type';
    // include state if present
    $selectState = $hasState ? ', state' : '';
    // description/city/country may be missing in some schemas — select safe defaults
    $selectDesc = in_array('description', $colNames, true) ? ', description' : ", '' AS description";
    $selectCity = in_array('city', $colNames, true) ? ', city' : ", '' AS city";
    $selectCountry = in_array('country', $colNames, true) ? ', country' : ", '' AS country";
    // include optional extras: user_id, contact and address fields if present
    $selectExtras = '';
    $extraCols = [];
    $maybeWebsite = ['website','url','web'];
    $maybePhone = ['phone','telephone','contact_phone','contact_telephone'];
    $maybeStreet = ['street','housenumber','address','postcode','postal_code'];
    if (in_array('user_id', $colNames, true)) $extraCols[] = 'user_id';
    foreach ($maybeWebsite as $c) { if (in_array($c, $colNames, true)) { $extraCols[] = $c; break; } }
    foreach ($maybePhone as $c) { if (in_array($c, $colNames, true)) { $extraCols[] = $c; break; } }
    foreach ($maybeStreet as $c) { if (in_array($c, $colNames, true)) { $extraCols[] = $c; } }
    if (!empty($extraCols)) {
        // prefix each with comma and join
        $selectExtras = ', ' . implode(', ', $extraCols);
    }

    if ($bbox) {
        // Expect: lat1,lon1,lat2,lon2 (south,west,north,east) — normalize to min/max
        $parts = array_map('trim', explode(',', $bbox));
        if (count($parts) === 4) {
            $f = array_map('floatval', $parts);
            $minLat = min($f[0], $f[2]);
            $maxLat = max($f[0], $f[2]);
            $minLon = min($f[1], $f[3]);
            $maxLon = max($f[1], $f[3]);

            $extraWhere = '';
            if ($countryFilter !== '') {
                $extraWhere .= " AND country = :_country";
            }
            if ($stateFilter !== '') {
                $extraWhere .= " AND state = :_state";
            }
            if ($typesFilter !== '') {
                // build IN clause using named placeholders to avoid mixing positional and named params
                $typeParts = array_values(array_filter(array_map('trim', explode(',', $typesFilter))));
                if (!empty($typeParts)) {
                    $phNames = [];
                    foreach ($typeParts as $ix => $tp) {
                        $ph = ':_type_' . $ix;
                        $phNames[] = $ph;
                    }
                    $extraWhere .= ' AND type IN (' . implode(',', $phNames) . ')';
                }
            }
                if ($hasLatLon) {
                    // Optimize user-scoped bbox queries by driving the plan from `favorites` when filtering by user.
                    // Use STRAIGHT_JOIN to encourage the optimizer to start with `favorites` (indexed by user_id,location_id)
                    // and then lookup `locations` by PK; still allow returning locations owned by the user even if
                    // they are not present in `favorites` via the OR condition on l.user_id.
                    if ($filterByUser && $currentUserId) {
                        // build ownership condition: always require favorite membership; if locations has user_id, allow
                        // locations owned directly by the user as well.
                        $ownershipCond = 'f.user_id = :_user_id';
                        if (in_array('user_id', $colNames, true)) {
                            $ownershipCond = '(f.user_id = :_user_id OR l.user_id = :_user_id)';
                        }
                        $sql = 'SELECT l.id, l.name, l.type, ' . $selectCoords . $selectDesc . $selectCity . $selectState . $selectCountry . ($hasLogo ? ', l.logo' : '') . $selectOsm . $selectTags . $selectExtras . ' FROM favorites f JOIN locations l ON l.id = f.location_id WHERE ' . $ownershipCond . ' AND l.latitude BETWEEN :minLat AND :maxLat AND l.longitude BETWEEN :minLon AND :maxLon' . $extraWhere . ' ORDER BY l.name ASC LIMIT :lim OFFSET :off';
                    } else {
                        $sql = 'SELECT id, name, type, ' . $selectCoords . $selectDesc . $selectCity . $selectState . $selectCountry . ($hasLogo ? ', logo' : '') . $selectOsm . $selectTags . $selectExtras . ' FROM locations WHERE latitude BETWEEN :minLat AND :maxLat AND longitude BETWEEN :minLon AND :maxLon' . $extraWhere . ' ORDER BY name ASC LIMIT :lim OFFSET :off';
                    }
                } elseif ($hasCoordinates) {
                    if ($filterByUser && $currentUserId) {
                        $ownershipCond = 'f.user_id = :_user_id';
                        if (in_array('user_id', $colNames, true)) {
                            $ownershipCond = '(f.user_id = :_user_id OR l.user_id = :_user_id)';
                        }
                        $sql = 'SELECT l.id, l.name, l.type, ' . $selectCoords . $selectDesc . $selectCity . $selectState . $selectCountry . ($hasLogo ? ', l.logo' : '') . $selectOsm . $selectTags . $selectExtras . ' FROM favorites f JOIN locations l ON l.id = f.location_id WHERE ' . $ownershipCond . ' AND ST_Y(l.coordinates) BETWEEN :minLat AND :maxLat AND ST_X(l.coordinates) BETWEEN :minLon AND :maxLon' . $extraWhere . ' ORDER BY l.name ASC LIMIT :lim OFFSET :off';
                    } else {
                        $sql = 'SELECT id, name, type, ' . $selectCoords . $selectDesc . $selectCity . $selectState . $selectCountry . ($hasLogo ? ', logo' : '') . $selectOsm . $selectTags . $selectExtras . ' FROM locations WHERE ST_Y(coordinates) BETWEEN :minLat AND :maxLat AND ST_X(coordinates) BETWEEN :minLon AND :maxLon' . $extraWhere . ' ORDER BY name ASC LIMIT :lim OFFSET :off';
                    }
                } else {
                // No coordinates - return empty result in bbox case
                echo json_encode(['page' => $page, 'per_page' => $limit, 'data' => []]);
                exit;
            }

            $stmt = $db->prepare($sql);
            $stmt->bindValue(':minLat', $minLat);
            $stmt->bindValue(':maxLat', $maxLat);
            $stmt->bindValue(':minLon', $minLon);
            $stmt->bindValue(':maxLon', $maxLon);
            if ($filterByUser && $currentUserId) {
                $stmt->bindValue(':_user_id', $currentUserId, PDO::PARAM_INT);
            }
            // bind named type placeholders if present
            if ($typesFilter !== '') {
                $tp = array_values(array_filter(array_map('trim', explode(',', $typesFilter))));
                foreach ($tp as $ix => $v) {
                    $ph = ':_type_' . $ix;
                    $stmt->bindValue($ph, $v, PDO::PARAM_STR);
                }
            }
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            // malformed bbox -> return empty standardized shape
            echo json_encode(['page' => $page, 'per_page' => $limit, 'data' => []]);
            exit;
        }
    } elseif ($id !== null && $id > 0) {
        // Fetch single by id
        if ($hasLatLon || $hasCoordinates) {
            $sql = 'SELECT id, name, type, ' . $selectCoords . $selectDesc . $selectCity . $selectState . $selectCountry . ($hasLogo ? ', logo' : '') . $selectTags . $selectExtras . ' FROM locations WHERE id = :id LIMIT 1';
        } else {
            $sql = 'SELECT id, name, type, NULL AS latitude, NULL AS longitude' . $selectDesc . $selectCity . ($hasState ? ', NULL AS state' : '') . $selectCountry . ($hasLogo ? ', logo' : '') . $selectExtras . ' FROM locations WHERE id = :id LIMIT 1';
        }
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    } elseif ($q === '') {
        // Return a small set of recent/popular locations if no query.
        // If a 'type' parameter is provided, filter by that type.
        $where = '';
        $bindings = [];
        // user filter SQL (use favorites table to determine ownership). If locations has a user_id column,
        // include it as an additional ownership condition; otherwise rely on favorites membership only.
        if ($filterByUser && $currentUserId) {
            $where = ' WHERE (EXISTS (SELECT 1 FROM favorites f WHERE f.location_id = locations.id AND f.user_id = :_user_id)';
            if (in_array('user_id', $colNames, true)) {
                $where .= ' OR user_id = :_user_id';
            }
            $where .= ')';
            $bindings[':_user_id'] = [$currentUserId, PDO::PARAM_INT];
        }
        // single type param (backwards compatible)
        if ($typeFilter !== '') {
            $tlow = strtolower($typeFilter);
            $isAttractionType = in_array($tlow, ['attraction','attractions'], true) || stripos($tlow, 'attract') !== false;
            $isTransportType = in_array($tlow, ['transport','transportation'], true) || stripos($tlow, 'transport') !== false;
            $isCampgroundType = in_array($tlow, ['campground','campgrounds','camp_site'], true) || stripos($tlow, 'camp') !== false;

            // Build WHERE fragment for the single type filter. If we have a tags column,
            // also include tag-based LIKE checks for attractions/transport/campgrounds.
            $conds = [];
            if ($tagsColumn !== null && ($isAttractionType || $isTransportType || $isCampgroundType)) {
                if ($isAttractionType) {
                    $conds = array_merge($conds, [
                        $tagsColumn . " LIKE :_atag1",
                        $tagsColumn . " LIKE :_atag2",
                        $tagsColumn . " LIKE :_atag3",
                        $tagsColumn . " LIKE :_atag4",
                        'LOWER(name) LIKE :_atag5'
                    ]);
                }
                if ($isTransportType) {
                    $conds = array_merge($conds, [
                        $tagsColumn . " LIKE :_tntag1",
                        $tagsColumn . " LIKE :_tntag2",
                        $tagsColumn . " LIKE :_tntag3",
                        $tagsColumn . " LIKE :_tntag4",
                    ]);
                }
                if ($isCampgroundType) {
                    $conds = array_merge($conds, [
                        $tagsColumn . " LIKE :_cgp1",
                        $tagsColumn . " LIKE :_cgp2",
                    ]);
                }
                $where .= ($where === '' ? ' WHERE ' : ' AND ') . '(type = :_type OR (' . implode(' OR ', $conds) . '))';
                $bindings[':_type'] = [$typeFilter, PDO::PARAM_STR];
                if ($isAttractionType) {
                    $bindings[':_atag1'] = ['%"tourism":"attraction"%', PDO::PARAM_STR];
                    $bindings[':_atag2'] = ['%"tourism"=>"attraction"%', PDO::PARAM_STR];
                    $bindings[':_atag3'] = ['%"tourism":"viewpoint"%', PDO::PARAM_STR];
                    $bindings[':_atag4'] = ['%"natural":"waterfall"%', PDO::PARAM_STR];
                    $bindings[':_atag5'] = ['%waterfall%', PDO::PARAM_STR];
                }
                if ($isTransportType) {
                    $bindings[':_tntag1'] = ['%"public_transport":"stop_position"%', PDO::PARAM_STR];
                    $bindings[':_tntag2'] = ['%"public_transport"=>"station"%', PDO::PARAM_STR];
                    $bindings[':_tntag3'] = ['%"amenity":"bus_station"%', PDO::PARAM_STR];
                    $bindings[':_tntag4'] = ['%"highway":"bus_stop"%', PDO::PARAM_STR];
                }
                if ($isCampgroundType) {
                    $bindings[':_cgp1'] = ['%"tourism":"camp_site"%', PDO::PARAM_STR];
                    $bindings[':_cgp2'] = ['%"tourism"=>"caravan_site"%', PDO::PARAM_STR];
                }
            } else {
                $where .= ($where === '' ? ' WHERE ' : ' AND ') . 'type = :_type';
                $bindings[':_type'] = [$typeFilter, PDO::PARAM_STR];
            }
        }

        // multiple types via CSV (frontend may send types=food,hotel)
        if ($typesFilter !== '') {
            $parts = array_filter(array_map('trim', explode(',', $typesFilter)));
            if (!empty($parts)) {
                $placeholders = implode(',', array_fill(0, count($parts), '?'));
                $includeAttractionTags = false;
                foreach ($parts as $pt) {
                    $pl = strtolower($pt);
                    if (in_array($pl, ['attraction','attractions'], true) || stripos($pl, 'attract') !== false) { $includeAttractionTags = true; break; }
                }
                if ($includeAttractionTags && $tagsColumn !== null) {
                    $where .= ($where === '' ? ' WHERE ' : ' AND ') . "(type IN ($placeholders) OR ({$tagsColumn} LIKE :_atag1 OR {$tagsColumn} LIKE :_atag2 OR {$tagsColumn} LIKE :_atag3 OR {$tagsColumn} LIKE :_atag4 OR LOWER(name) LIKE :_atag5))";
                    $bindings[':_atag1'] = ['%"tourism":"attraction"%', PDO::PARAM_STR];
                    $bindings[':_atag2'] = ['%"tourism"=>"attraction"%', PDO::PARAM_STR];
                    $bindings[':_atag3'] = ['%"tourism":"viewpoint"%', PDO::PARAM_STR];
                    $bindings[':_atag4'] = ['%"natural":"waterfall"%', PDO::PARAM_STR];
                    $bindings[':_atag5'] = ['%waterfall%', PDO::PARAM_STR];
                } else {
                    $where .= ($where === '' ? ' WHERE ' : ' AND ') . "type IN ($placeholders)";
                }
                // store positional binds as numeric keys starting at 1, will bind later
                $i = 1;
                foreach ($parts as $p) {
                    $bindings[$i] = [$p, PDO::PARAM_STR];
                    $i++;
                }
            }
        }
        if ($countryFilter !== '') {
            if ($where === '') {
                $where = ' WHERE country = :_country';
            } else {
                $where .= ' AND country = :_country';
            }
            $bindings[':_country'] = [$countryFilter, PDO::PARAM_STR];
        }
        if ($stateFilter !== '') {
            if ($where === '') {
                $where = ' WHERE state = :_state';
            } else {
                $where .= ' AND state = :_state';
            }
            $bindings[':_state'] = [$stateFilter, PDO::PARAM_STR];
        }

        // If caller explicitly requested `mine=1` (explicitFilterByUser) return a larger set
        // of the user's own POIs (no bbox) so clients can render them globally. Cap at 2000 by default.
        if ($filterByUser && $currentUserId && $explicitFilterByUser) {
            $userLimit = max($limit, 2000);
            $sql = 'SELECT id, name, type, ' . $selectCoords . $selectDesc . $selectCity . $selectState . $selectCountry . ($hasLogo ? ', logo' : '') . $selectTags . $selectExtras . ' FROM locations' . $where . ' ORDER BY name ASC LIMIT :lim OFFSET :off';
            $stmt = $db->prepare($sql);
            foreach ($bindings as $k => $v) {
                $stmt->bindValue($k, $v[0], $v[1]);
            }
            $stmt->bindValue(':lim', $userLimit, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $sql = 'SELECT id, name, type, ' . $selectCoords . $selectDesc . $selectCity . $selectState . $selectCountry . ($hasLogo ? ', logo' : '') . $selectTags . $selectExtras . ' FROM locations' . $where . ' ORDER BY created_at DESC LIMIT :lim OFFSET :off';
            $stmt = $db->prepare($sql);
            foreach ($bindings as $k => $v) {
                $stmt->bindValue($k, $v[0], $v[1]);
            }
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
        
    } else {
        // Basic LIKE search on name and type. If the query looks like a shopping-category query
        // (contains 'shop', 'shopping', 'supermarket', 'department', 'mall', 'superstore'),
        // also search the tags column for shop=department_store/supermarket/mall values (hstore/json text).
        $like = '%' . str_replace('%', '\\%', $q) . '%';

        // determine whether the query suggests a shopping or bank category
        $qlow = strtolower($q);
        $shoppingHints = ['shop','shopping','supermarket','department','mall','superstore'];
        $bankHints = ['bank','atm'];
        $includeShopTags = false;
        $includeBankTags = false;
        foreach ($shoppingHints as $h) {
            if (strpos($qlow, $h) !== false) {
                $includeShopTags = true;
                break;
            }
        }
        foreach ($bankHints as $h) {
            if (strpos($qlow, $h) !== false) {
                $includeBankTags = true;
                break;
            }
        }

        if ($hasTags && ($includeShopTags || $includeBankTags)) {
            // Build a WHERE that includes name/type like plus optional tag checks for shop and bank
            $conds = [];
            $conds[] = "name LIKE :like";
            $conds[] = "type LIKE :like";
            if ($includeShopTags) {
                $conds[] = "LOWER(COALESCE(tags,'')) LIKE :shop_dep";
                $conds[] = "LOWER(COALESCE(tags,'')) LIKE :shop_sup";
                $conds[] = "LOWER(COALESCE(tags,'')) LIKE :shop_mall";
                $conds[] = "LOWER(COALESCE(tags,'')) LIKE :shop_superstore";
                $conds[] = "LOWER(COALESCE(tags,'')) LIKE :shop_generic";
            }
            if ($includeBankTags) {
                $conds[] = "LOWER(COALESCE(tags,'')) LIKE :bank_amenity";
                $conds[] = "LOWER(COALESCE(tags,'')) LIKE :bank_generic";
            }
                $sql = 'SELECT id, name, type, ' . $selectCoords . $selectDesc . $selectCity . $selectState . $selectCountry . ($hasLogo ? ', logo' : '') . $selectTags . $selectExtras . ' FROM locations WHERE (' . implode(' OR ', $conds) . ')' . $where;
            if ($countryFilter !== '') {
                $sql .= ' AND country = :_country';
            }
            if ($stateFilter !== '') {
                $sql .= ' AND state = :_state';
            }
            $sql .= ' ORDER BY name ASC LIMIT :lim OFFSET :off';
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':like', $like, PDO::PARAM_STR);
            if ($includeShopTags) {
                $stmt->bindValue(':shop_dep', '%"shop"=>"department_store"%', PDO::PARAM_STR);
                $stmt->bindValue(':shop_sup', '%"shop"=>"supermarket"%', PDO::PARAM_STR);
                $stmt->bindValue(':shop_mall', '%"shop"=>"mall"%', PDO::PARAM_STR);
                $stmt->bindValue(':shop_superstore', '%superstore%', PDO::PARAM_STR);
                $stmt->bindValue(':shop_generic', '%department_store%', PDO::PARAM_STR);
            }
            if ($includeBankTags) {
                $stmt->bindValue(':bank_amenity', '%"amenity"=>"bank"%', PDO::PARAM_STR);
                $stmt->bindValue(':bank_generic', '%bank%', PDO::PARAM_STR);
            }
            if ($filterByUser && $currentUserId) {
                $stmt->bindValue(':_user_id', $currentUserId, PDO::PARAM_INT);
            }
            if ($countryFilter !== '') {
                $stmt->bindValue(':_country', $countryFilter, PDO::PARAM_STR);
            }
            if ($stateFilter !== '') {
                $stmt->bindValue(':_state', $stateFilter, PDO::PARAM_STR);
            }
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $sql = 'SELECT id, name, type, ' . $selectCoords . $selectDesc . $selectCity . $selectState . $selectCountry . ($hasLogo ? ', logo' : '') . $selectTags . $selectExtras . ' FROM locations WHERE (name LIKE :like OR type LIKE :like)' . $where;
            if ($countryFilter !== '') {
                $sql .= ' AND country = :_country';
            }
            if ($stateFilter !== '') {
                $sql .= ' AND state = :_state';
            }
            $sql .= ' ORDER BY name ASC LIMIT :lim OFFSET :off';
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':like', $like, PDO::PARAM_STR);
            if ($filterByUser && $currentUserId) {
                $stmt->bindValue(':_user_id', $currentUserId, PDO::PARAM_INT);
            }
            if ($countryFilter !== '') {
                $stmt->bindValue(':_country', $countryFilter, PDO::PARAM_STR);
            }
            if ($stateFilter !== '') {
                $stmt->bindValue(':_state', $stateFilter, PDO::PARAM_STR);
            }
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize rows so all expected keys exist (some installations may lack columns)
    foreach ($rows as &$r) {
        if (!array_key_exists('description', $r)) {
            $r['description'] = '';
        }
        if (!array_key_exists('city', $r)) {
            $r['city'] = '';
        }
        if (!array_key_exists('country', $r)) {
            $r['country'] = '';
        }
        // Ensure latitude/longitude keys exist even if null
        if (!array_key_exists('latitude', $r)) {
            $r['latitude'] = null;
        }
        if (!array_key_exists('longitude', $r)) {
            $r['longitude'] = null;
        }
        if (!array_key_exists('logo', $r)) {
            $r['logo'] = null;
        }
        if (!array_key_exists('tags', $r)) {
            $r['tags'] = null;
        }
        // mark source so frontend can choose icons accordingly
        $r['source'] = 'mysql';
        // provide a simple logo status field so the client can react when icons are missing
        $r['logo_status'] = 'unknown';
        $r['logo_missing'] = null; // will be set later
    }
    // Validate referenced logo files exist on disk; if missing or if the `logo` value is a data: URI,
    // write a debug log entry. Mark hotel POIs specially so we can inspect hotel icons.
    try {
        $assetIconDir = realpath(__DIR__ . '/../../assets/icons');
        foreach ($rows as &$rr) {
            try {
                $logo = isset($rr['logo']) ? trim((string)$rr['logo']) : '';
                $tagsText = isset($rr['tags']) ? (string)$rr['tags'] : '';
                $isHotel = false;
                if (!empty($rr['type']) && stripos((string)$rr['type'], 'hotel') !== false) $isHotel = true;
                if (!$isHotel && $tagsText !== '' && (stripos($tagsText, 'tourism') !== false && stripos($tagsText, 'hotel') !== false)) $isHotel = true;

                if ($logo === '') {
                    $rr['logo_status'] = 'missing';
                    if ($isHotel) {
                        if (function_exists('import_debug_log')) import_debug_log(['event' => 'hotel_missing_logo', 'id' => $rr['id'] ?? null, 'type' => $rr['type'] ?? null, 'tags' => $tagsText]);
                        else error_log('hotel_missing_logo: id=' . ($rr['id'] ?? '') . ' type=' . ($rr['type'] ?? '') . ' tags=' . $tagsText);
                    }
                    continue;
                }

                // If the stored logo value is itself a data URI, log that as it's unexpected
                if (stripos($logo, 'data:image/') === 0) {
                    $rr['logo_status'] = 'data_uri';
                    if (function_exists('import_debug_log')) import_debug_log(['event' => 'logo_is_data_uri', 'id' => $rr['id'] ?? null, 'logo_preview' => substr($logo,0,200)]);
                    else error_log('logo_is_data_uri: id=' . ($rr['id'] ?? '') . ' preview=' . substr($logo,0,200));
                    continue;
                }

                // Otherwise, check file presence under assets/icons and assets/icons/thumbs
                $checked = [];
                if ($assetIconDir) {
                    $checked[] = $assetIconDir . DIRECTORY_SEPARATOR . $logo;
                    $checked[] = $assetIconDir . DIRECTORY_SEPARATOR . 'thumbs' . DIRECTORY_SEPARATOR . 'marker-' . $logo;
                }
                $exists = false;
                foreach ($checked as $p) { if ($p && file_exists($p)) { $exists = true; break; } }
                if ($exists) {
                    $rr['logo_status'] = 'ok';
                    $rr['logo_missing'] = false;
                } else {
                    $rr['logo_status'] = 'missing_file';
                    $rr['logo_missing'] = true;
                    if (function_exists('import_debug_log')) {
                        import_debug_log(['event' => ($isHotel ? 'hotel_missing_logo_file' : 'missing_logo_file'), 'id' => $rr['id'] ?? null, 'logo' => $logo, 'checked_paths' => $checked]);
                    } else {
                        error_log('missing_logo_file: id=' . ($rr['id'] ?? '') . ' logo=' . $logo . ' checked=' . json_encode($checked));
                    }
                }
            } catch (Throwable $e) { /* ignore per-row errors */ }
        }
        unset($rr);
    } catch (Throwable $e) { /* ignore overall logging errors */ }

    // ensure every row has a boolean logo_missing for easier client checks
    foreach ($rows as &$rr2) {
        if (!isset($rr2['logo_missing'])) {
            $rr2['logo_missing'] = ($rr2['logo_status'] !== 'ok');
        }
    }
    unset($rr2);

    // Return the standardized shape used elsewhere: { page, per_page, data }
    echo json_encode(['page' => $page, 'per_page' => $limit, 'data' => $rows]);
    exit;
} catch (Exception $e) {
    // Log the error with full context for debugging
    $errorContext = [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'query' => $sql ?: 'not_set',
        'request_params' => [
            'q' => $q ?? '',
            'bbox' => $bbox ?? '',
            'id' => $id ?? '',
            'limit' => $limit ?? '',
            'type' => $typeFilter ?? '',
        ]
    ];

    error_log('Search.php ERROR: ' . json_encode($errorContext));

    // Fallback strategy:
    // PostGIS search requires a bbox. If we don't have one, we cannot fallback.
    // Only attempt PostGIS fallback if:
    // 1. We have a bbox parameter (spatial search)
    // 2. PostGIS file exists
    // 3. No other special parameters that MySQL has already handled

    // PostGIS fallback removed — return a structured error without attempting Postgres.
    http_response_code(500);
    echo json_encode([
        'page' => $page,
        'per_page' => $limit,
        'data' => [],
        'error' => 'search_failed',
        'message' => 'MySQL search failed and no PostGIS fallback is available: ' . $e->getMessage(),
        'debug' => [
            'query' => $sql ?: 'not_set',
            'bbox' => $bbox ?? null,
            'has_postgis_fallback' => false
        ]
    ]);
    exit;
}
