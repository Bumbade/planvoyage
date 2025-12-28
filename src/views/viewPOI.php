<?php
// viewPOI.php - single POI profile and edit form (clean replacement)
if (file_exists(__DIR__ . '/../config/env.php')) require_once __DIR__ . '/../config/env.php';
if (file_exists(__DIR__ . '/../helpers/i18n.php')) require_once __DIR__ . '/../helpers/i18n.php';
if (file_exists(__DIR__ . '/../helpers/session.php')) { require_once __DIR__ . '/../helpers/session.php'; start_secure_session(); }
if (file_exists(__DIR__ . '/../helpers/auth.php')) require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../config/mysql.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = get_db();

$error = '';
$success = '';

// fetch row (or empty array)
$row = [];
if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM locations WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    try { $stmt->execute(); $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) { $error = $e->getMessage(); }
}

// table columns for dynamic rendering
$tableCols = [];
try {
    $colStmt = $db->query("DESCRIBE locations");
    foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $c) $tableCols[] = $c['Field'];
} catch (Exception $e) { /* ignore */ }

// preferred user-friendly order for displaying and editing fields
$preferredOrder = [
    'name', 'type', 'brand_wikipedia', 'brand_wikidata', 'description', 'logo',
    'addr_street', 'addr_city', 'addr_province', 'addr_postcode',
    'city', 'state', 'country',
    'phone', 'email', 'fax', 'website', 'twitter', 'facebook', 'instagram', 'opening_hours',
    'operator', 'operator_wikipedia', 'operator_wikidata', 'brand',
    'capacity', 'ref', 'wheelchair', 'internet_access', 'internet_access_fee',
    'latitude', 'longitude'
];

// friendly label overrides
$labelMap = [
    'addr_street' => 'Street',
    'addr_city' => 'Address City',
    'addr_province' => 'Province/State',
    'addr_postcode' => 'Postal Code',
    'brand_wikipedia' => 'Brand (Wikipedia)',
    'brand_wikidata' => 'Brand (Wikidata)',
    'opening_hours' => 'Opening Hours'
];
// Add labels for new fields
$labelMap['phone'] = 'Phone';
$labelMap['email'] = 'Email';
$labelMap['fax'] = 'Fax';
$labelMap['website'] = 'Website';
$labelMap['twitter'] = 'Twitter';
$labelMap['facebook'] = 'Facebook';
$labelMap['instagram'] = 'Instagram';
$labelMap['operator'] = 'Operator';
$labelMap['operator_wikipedia'] = 'Operator (Wikipedia)';
$labelMap['operator_wikidata'] = 'Operator (Wikidata)';
$labelMap['brand'] = 'Brand';
$labelMap['capacity'] = 'Capacity';
$labelMap['ref'] = 'Reference';
$labelMap['wheelchair'] = 'Wheelchair Access';
$labelMap['internet_access'] = 'Internet Access';
$labelMap['internet_access_fee'] = 'Internet Access Fee';

// grouping for visual sections
$groups = [
    'Primary' => ['name','type','brand_wikipedia','brand_wikidata','description','osm_id','logo'],
    'Address' => ['addr_street','addr_city','addr_province','addr_postcode','city','state','country'],
    'Contact' => ['phone','website','opening_hours'],
    'Location' => ['latitude','longitude']
];

// helper: parse tags text into associative array (supports JSON or => style)
$parseTags = function($tagsText) {
    $res = [];
    if (empty($tagsText)) return $res;
    // try JSON first
    $dec = @json_decode($tagsText, true);
    if (is_array($dec)) return $dec;
    // fallback: tags stored like '\"key\"=>\"value\"', 'key=value' or similar
    $pairs = preg_split('/,(?=(?:[^\"\"]*\"[^\"\"]*\")*[^\"\"]*$)/', $tagsText);
    foreach ($pairs as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (strpos($p, '=>') !== false) {
            list($k,$v) = explode('=>', $p, 2);
        } elseif (strpos($p, '=') !== false) {
            list($k,$v) = explode('=', $p, 2);
        } else { continue; }
        $k = trim($k, " \"'\n\r\t");
        $v = trim($v, " \"'\n\r\t");
        if ($k === '') continue;
        $res[$k] = $v;
    }
    return $res;
};

// fallback helper for admin check
if (!function_exists('session_is_admin')) {
    function session_is_admin() {
        if (function_exists('is_admin_user')) return is_admin_user();
        if (!empty($_SESSION['is_admin'])) return true;
        $uid = $_SESSION['user_id'] ?? null;
        return ($uid !== null && (int)$uid === 1);
    }
}

$currentUser = $_SESSION['user_id'] ?? null;
$isAdmin = session_is_admin();
$canEdit = $isAdmin || ($currentUser && isset($row['user_id']) && $row['user_id'] !== null && ((int)$currentUser === (int)$row['user_id']));

// Handle POST update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {
    $token = $_POST['csrf_token'] ?? '';
        if (!function_exists('csrf_check') || !csrf_check($token)) {
            $error = t('invalid_csrf', 'Invalid CSRF token');
        } elseif (!$row) {
            $error = t('row_not_found', 'Row not found');
        } elseif (!$canEdit) {
            $error = t('permission_denied', 'Permission denied');
    } else {
        $exclude = ['id','osm_id','coordinates','created_at','updated_at'];
        $set = [];
        $params = [];
        foreach ($tableCols as $col) {
            if (in_array($col, $exclude, true)) continue;
            if (!array_key_exists($col, $row)) continue;
            if (!array_key_exists($col, $_POST)) continue;
            $val = trim((string)($_POST[$col] ?? ''));
            if ($val === '') $val = null;
            if (($col === 'latitude' || $col === 'longitude') && $val !== null) {
                          if (!is_numeric($val)) { $error = sprintf(t('invalid_value_for', 'Invalid value for %s'), $col); break; }
                $val = (float)$val;
            }
            $set[] = "`$col` = :$col";
            $params[":$col"] = $val;
        }

        // server-side enforcement: if type is Hotel and logo column exists, set a hotel icon
        if (empty($error) && in_array('type', $tableCols, true) && isset($_POST['type']) && trim($_POST['type']) === 'Hotel' && in_array('logo', $tableCols, true)) {
            $iconsDir = __DIR__ . '/../../icons';
            $hotelIcon = null;
            if (is_dir($iconsDir)) {
                $ents = @scandir($iconsDir);
                if (is_array($ents)) {
                    foreach ($ents as $e) {
                        if ($e === '.' || $e === '..') continue;
                        if (!preg_match('/\.(png|jpg|jpeg|svg)$/i', $e)) continue;
                        if (strtolower($e) === 'hotel.png') { $hotelIcon = $e; break; }
                        if ($hotelIcon === null) $hotelIcon = $e;
                    }
                }
            }
            if ($hotelIcon === null) $hotelIcon = 'hotel.png';
            // ensure we will write logo
            if (!in_array('`logo` = :logo', $set, true)) { $set[] = "`logo` = :logo"; }
            $params[':logo'] = $hotelIcon;
        }

        if (empty($error)) {
            if (!empty($set)) {
                $sql = 'UPDATE locations SET ' . implode(', ', $set) . ' WHERE id = :id LIMIT 1';
                $u = $db->prepare($sql);
                foreach ($params as $k => $v) {
                    if ($v === null) $u->bindValue($k, null, PDO::PARAM_NULL);
                    else $u->bindValue($k, $v);
                }
                $u->bindValue(':id', $id, PDO::PARAM_INT);
                try { $u->execute(); $success = 'Saved'; } catch (Exception $e) { $error = $e->getMessage(); }
                    try { $u->execute(); $success = t('saved', 'Saved'); } catch (Exception $e) { $error = $e->getMessage(); }

                // reload row
                $stmt2 = $db->prepare('SELECT * FROM locations WHERE id = :id LIMIT 1');
                $stmt2->bindValue(':id', $id, PDO::PARAM_INT);
                $stmt2->execute();
                $row = $stmt2->fetch(PDO::FETCH_ASSOC) ?: [];

                // Mirror `tags_text` into `tags` for compatibility when tags is empty
                try {
                    if (in_array('tags_text', $tableCols, true) && in_array('tags', $tableCols, true)) {
                        $tagsTextVal = $row['tags_text'] ?? null;
                        $tagsVal = $row['tags'] ?? null;
                        if (!empty($tagsTextVal) && (empty($tagsVal) || $tagsVal === null)) {
                            $up = $db->prepare('UPDATE locations SET tags = :tags WHERE id = :id LIMIT 1');
                            $up->bindValue(':tags', $tagsTextVal);
                            $up->bindValue(':id', $id, PDO::PARAM_INT);
                            try { $up->execute(); } catch (Exception $e) { /* ignore mirror failures */ }
                            // update current row so the UI immediately reflects the mirrored value
                            $row['tags'] = $tagsTextVal;
                        }
                    }
                } catch (Exception $e) { /* ignore */ }

                    // If admin fields missing, trigger background backfill (existing behavior)
                $needsBackfill = false;
                try { $needsBackfill = (empty($row['city']) || empty($row['country'])) || (array_key_exists('state', $row) && empty($row['state'])); } catch (Exception $e) { $needsBackfill = false; }
                if ($needsBackfill) {
                    $backfillScript = realpath(__DIR__ . '/../../_development/tools/backfill_locations_for_ids.php');
                    if ($backfillScript && file_exists($backfillScript)) {
                        $cmdPhp = defined('PHP_BINARY') ? PHP_BINARY : 'php'; $escPhp = escapeshellarg($cmdPhp); $escScript = escapeshellarg($backfillScript); $escId = escapeshellarg((string)$id);
                        if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows') { $cmd = 'start /B "" ' . $escPhp . ' ' . $escScript . ' ' . $escId . ' --apply'; @pclose(@popen($cmd, 'r')); }
                        else { $cmd = $escPhp . ' ' . $escScript . ' ' . $escId . ' --apply > /dev/null 2>&1 &'; @exec($cmd . ' 2>&1'); }
                            $success .= ' ' . t('backfill_requested', 'Backfill requested.');
                    }
                }
            } else {
                $error = 'No fields to update';
            }
        }
    }
}

// Handle POST create for new POIs when no id is provided (allow any logged-in user)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id === 0) {
    $token = $_POST['csrf_token'] ?? '';
    if (!function_exists('csrf_check') || !csrf_check($token)) {
        $error = t('invalid_csrf', 'Invalid CSRF token');
    } elseif (empty($currentUser)) {
        $error = t('please_login_to_edit_pois', 'Please log in to edit or add POIs.');
    } else {
        $exclude = ['id','osm_id','coordinates','created_at','updated_at'];
        $insertCols = [];
        $placeholders = [];
        $params = [];
        foreach ($tableCols as $col) {
            if (in_array($col, $exclude, true)) continue;
            if (!array_key_exists($col, $_POST)) continue;
            $val = trim((string)($_POST[$col] ?? ''));
            if ($val === '') $val = null;
            if (($col === 'latitude' || $col === 'longitude') && $val !== null) {
                if (!is_numeric($val)) { $error = sprintf(t('invalid_value_for', 'Invalid value for %s'), $col); break; }
                $val = (float)$val;
            }
            $insertCols[] = "`$col`";
            $placeholders[] = ':' . $col;
            $params[':' . $col] = $val;
        }

        // ensure creator association when column exists
        if (in_array('user_id', $tableCols, true) && !array_key_exists(':user_id', $params)) {
            $insertCols[] = '`user_id`';
            $placeholders[] = ':user_id';
            $params[':user_id'] = $currentUser;
        }

        // add created_at if available
        if (in_array('created_at', $tableCols, true) && !in_array('created_at', $insertCols, true)) {
            $insertCols[] = '`created_at`';
            $placeholders[] = ':created_at';
            $params[':created_at'] = date('Y-m-d H:i:s');
        }

        if (empty($error)) {
            if (empty($insertCols)) {
                $error = t('no_fields_to_create', 'No fields to create');
            } else {
                $sql = 'INSERT INTO locations (' . implode(',', $insertCols) . ') VALUES (' . implode(',', $placeholders) . ')';
                $i = $db->prepare($sql);
                foreach ($params as $k => $v) {
                    if ($v === null) $i->bindValue($k, null, PDO::PARAM_NULL);
                    else $i->bindValue($k, $v);
                }
                try {
                    $i->execute();
                    $newId = (int)$db->lastInsertId();
                    // redirect to the created POI page
                    header('Location: ' . app_url('/index.php/locations/view') . '?id=' . $newId);
                    exit;
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            }
        }
    }
}

global $appBase;
if (!function_exists('config')) {
    require_once __DIR__ . '/../config/app.php';
}
$appBase = config('app.base') ?? '/Allgemein/planvoyage/src';
require_once __DIR__ . '/../includes/header.php';
// Note: poi-form.css is now consolidated in features.css (loaded in header.php)
?>

<main>
    <div class="poi-header">
        <div class="poi-header-logo-block">
            <?php if (!empty($row['logo'])): ?><img src="<?php echo htmlspecialchars(asset_url('assets/icons/' . $row['logo'])); ?>" alt="" class="poi-header-logo" /><?php endif; ?>
            <p class="poi-header-type"><?php echo htmlspecialchars($row['type'] ?? ''); ?></p>
        </div>
        <h1 class="poi-title"><?php echo htmlspecialchars($row['name'] ?? 'POI'); ?></h1>
    </div>
    <?php if (!empty($error)): ?><div class="error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (!empty($success)): ?><div class="flash-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="poi-container">
        <div class="poi-main">
            <?php
                $is_edit = (isset($_GET['edit']) && $_GET['edit']) || $_SERVER['REQUEST_METHOD'] === 'POST';
            ?>

            <?php if (!$is_edit): ?>
                <div class="poi-actions">
                        <h3><?php echo htmlspecialchars(t('details','Details')); ?></h3>
                    <div class="poi-buttons">
                            <a class="btn btn-cancel" href="<?php echo htmlspecialchars(app_url('/index.php/locations')); ?>"><?php echo htmlspecialchars(t('back_to_poi_map','Back to POI map')); ?></a>
                            <?php if ($canEdit): ?><a class="btn" href="<?php echo htmlspecialchars(app_url('/index.php/locations/view') . '?id=' . urlencode($id) . '&edit=1'); ?>"><?php echo htmlspecialchars(t('edit','Edit')); ?></a><?php endif; ?>
                    </div>
                </div>

                <?php if (empty($_SESSION['user_id'])): ?>
                    <div class="notice"><?php echo htmlspecialchars(t('please_login_to_edit_pois','Please log in to edit or add POIs.')); ?> <a href="<?php echo htmlspecialchars(app_url('/user/login')); ?>"><?php echo htmlspecialchars(t('login','Login')); ?></a></div>
                <?php endif; ?>

                <?php
                    // Render groups in a friendly order: Primary, Location (map), Address, Contact, Other
                    $viewGroupOrder = ['Location','Address','Contact','Tags','Other'];
                    $excludeView = ['id','coordinates','created_at','updated_at'];
                    // ordered columns (preferred first, then remaining) used for falling-back to 'Other' fields
                    $ordered = array_values(array_unique(array_merge(array_intersect($preferredOrder, $tableCols), array_diff($tableCols, $preferredOrder))));
                ?>

                <div class="view-groups">
                <?php
                // Spezial-Layout für Campgrounds
                $isCampground = (isset($row['type']) && strtolower($row['type']) === 'campground');
                if ($isCampground) {
                    // Karte mit Lat/Lon klein
                    echo '<div class="group-box group-location"><h4 class="poi-section">Map</h4>';
                    $lat = $row['latitude'] ?? null; $lon = $row['longitude'] ?? null;
                    echo '<div class="poi-map-wrap"><div id="poi-map-view" class="poi-map"></div>';
                    if ($lat !== null && $lat !== '' && $lon !== null && $lon !== '') {
                        echo '<div style="font-size:0.95em;color:#666;margin-top:4px;">Lat: ' . htmlspecialchars($lat) . ' &nbsp; Lon: ' . htmlspecialchars($lon) . '</div>';
                    } else {
                        echo '<p class="muted">' . htmlspecialchars(t('no_coordinates_available','No coordinates available')) . '</p>';
                    }
                    echo '</div></div>';

                    // Address
                    echo '<div class="group-box"><h4 class="poi-section">Address</h4>';
                    $addressFields = [
                        'city','addr_postcode','addr_street','addr_housenumber','state','country'
                    ];
                    foreach ($addressFields as $col) {
                        $val = $row[$col] ?? null;
                        if ($val === null || $val === '') continue;
                        $label = $labelMap[$col] ?? ucwords(str_replace('_', ' ', $col));
                        echo '<div class="field-row"><span class="field-label">' . htmlspecialchars($label) . ':</span><span class="field-value">' . nl2br(htmlspecialchars((string)$val)) . '</span></div>';
                    }
                    echo '</div>';

                    // Contact
                    echo '<div class="group-box"><h4 class="poi-section">Contact</h4>';
                    $contactFields = ['phone','email','website'];
                    foreach ($contactFields as $col) {
                        $val = $row[$col] ?? null;
                        if ($val === null || $val === '') continue;
                        $label = $labelMap[$col] ?? ucwords(str_replace('_', ' ', $col));
                        echo '<div class="field-row"><span class="field-label">' . htmlspecialchars($label) . ':</span><span class="field-value">';
                        if ($col === 'website') {
                            echo '<a href="' . htmlspecialchars($val) . '" target="_blank" rel="noopener" class="website-btn">' . htmlspecialchars($val) . '</a>';
                        } elseif ($col === 'phone') {
                            $tel = preg_replace('/[^+0-9]/', '', $val);
                            echo '<a href="tel:' . htmlspecialchars($tel) . '" class="phone-btn">' . htmlspecialchars($val) . '</a>';
                        } elseif ($col === 'email') {
                            echo '<a href="mailto:' . htmlspecialchars($val) . '" class="email-btn">' . htmlspecialchars($val) . '</a>';
                        } else {
                            echo nl2br(htmlspecialchars((string)$val));
                        }
                        echo '</span></div>';
                    }
                    echo '</div>';

                    // Amenities
                    echo '<div class="group-box"><h4 class="poi-section">Amenities</h4>';
                    $amenitiesFields = [
                        'drinking_water','internet_access','internet_access_fee','power_supply','sanitary_dump_station','shower','tents','toilets'
                    ];
                    foreach ($amenitiesFields as $col) {
                        $val = $row[$col] ?? null;
                        if ($val === null || $val === '') continue;
                        $label = $labelMap[$col] ?? ucwords(str_replace('_', ' ', $col));
                        echo '<div class="field-row"><span class="field-label">' . htmlspecialchars($label) . ':</span><span class="field-value">' . nl2br(htmlspecialchars((string)$val)) . '</span></div>';
                    }
                    echo '</div>';

                    // Infos
                    echo '<div class="group-box"><h4 class="poi-section">Infos</h4>';
                    $infoFields = ['operator','capacity','fee'];
                    foreach ($infoFields as $col) {
                        $val = $row[$col] ?? null;
                        if ($val === null || $val === '') continue;
                        $label = $labelMap[$col] ?? ucwords(str_replace('_', ' ', $col));
                        echo '<div class="field-row"><span class="field-label">' . htmlspecialchars($label) . ':</span><span class="field-value">' . nl2br(htmlspecialchars((string)$val)) . '</span></div>';
                    }
                    echo '</div>';

                    // Wetter-Abschnitt (Platzhalter)
                    echo '<div class="group-box"><h4 class="poi-section">Weather</h4>';
                    echo '<div id="weather-section">Aktuelles Wetter und 5-Tage-Vorhersage (Platzhalter)</div>';
                    echo '</div>';
                } else {
                    // Standard-Logik für andere POI-Typen (wie bisher)
                    foreach ($viewGroupOrder as $g) {
                        $isLocation = ($g === 'Location');
                        echo '<div class="group-box' . ($isLocation ? ' group-location' : '') . '">';
                        echo '<h4 class="poi-section">' . htmlspecialchars($g) . '</h4>';
                        // ...existing code...
                    }
                }
                ?>
                </div>

            <?php else: ?>
                <?php if ($canEdit): ?>
                    <h3><?php echo htmlspecialchars(t('edit_poi','Edit POI')); ?></h3>
                    <form method="post" id="poi-edit-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(function_exists('csrf_token') ? csrf_token() : ''); ?>" />
                        <?php
                            // render inputs in grouped rows with side-by-side fields where sensible
                            $excludeEdit = ['id','coordinates','created_at','updated_at','user_id'];
                            $typeOptions = ['Campground','Park','Hotel','Food','Shopping','Gas Station','Bank','poi'];
                            $orderedEdit = array_values(array_unique(array_merge(array_intersect($preferredOrder, $tableCols), array_diff($tableCols, $preferredOrder))));

                            // Layout definition per group: rows with columns
                            $layout = [
                                'Primary' => [ ['name','type'], ['brand_wikipedia','brand_wikidata'], ['description'], ['logo'] ],
                                'Address' => [ ['addr_street'], ['addr_city','addr_postcode','addr_province'], ['city','state','country'] ],
                                'Contact' => [ ['phone','website'], ['opening_hours'] ],
                                'Location' => [ ['latitude','longitude'] ]
                            ];

                            // render group-by-group
                            $leftoverFields = [];
                            foreach ($groups as $g => $cols) {
                                // determine which of these columns exist and are editable
                                $available = array_values(array_intersect($cols, $orderedEdit));
                                $rows = $layout[$g] ?? [];
                                $printed = [];
                                if (!empty($available)) {
                                    echo '<h4 class="poi-section">' . htmlspecialchars($g) . '</h4>';
                                    foreach ($rows as $rowDef) {
                                        // collect cells that actually exist
                                        $cells = array_values(array_intersect($rowDef, $available));
                                        if (empty($cells)) continue;
                                        echo '<div class="poi-row">';
                                        foreach ($cells as $col) {
                                            if (in_array($col, $excludeEdit, true)) continue;
                                            $val = $row[$col] ?? '';
                                            $label = $labelMap[$col] ?? ucwords(str_replace('_', ' ', $col));
                                            echo '<div class="poi-field">';
                                            // special controls
                                            if ($col === 'logo') {
                                                echo '<label>' . htmlspecialchars($label) . '<br />';
                                                if (!empty($val)) {
                                                    echo '<div class="logo-display"><img src="' . htmlspecialchars(asset_url('assets/icons/' . $val)) . '" alt="" class="poi-logo-thumb" /><div>' . htmlspecialchars($val) . '</div></div>';
                                                } else {
                                                    echo '<div class="muted">' . htmlspecialchars(t('no_logo','(no logo)')) . '</div>';
                                                }
                                                echo '<input type="hidden" name="logo" value="' . htmlspecialchars($val) . '" />';
                                                echo '</label>';
                                            } elseif ($col === 'type') {
                                                echo '<label>' . htmlspecialchars($label) . '<br /><select name="type" class="poi-field-full">';
                                                foreach ($typeOptions as $opt) { $sel = ($opt === ($val ?? '')) ? ' selected' : ''; echo '<option value="' . htmlspecialchars($opt) . '"' . $sel . '>' . htmlspecialchars($opt) . '</option>'; }
                                                echo '</select></label>';
                                            } elseif ($col === 'description') {
                                                echo '<label>' . htmlspecialchars($label) . '<br /><textarea name="' . htmlspecialchars($col) . '" class="poi-field-full poi-textarea">' . htmlspecialchars($val) . '</textarea></label>';
                                            } else {
                                                if ($col === 'osm_id') {
                                                    // show osm_id as read-only field (do not save by server-side exclusion)
                                                    echo '<label>' . htmlspecialchars($label) . '<br /><input name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" class="poi-field-full" readonly /></label>';
                                                } else {
                                                    echo '<label>' . htmlspecialchars($label) . '<br /><input name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" class="poi-field-full" /></label>';
                                                }
                                            }
                                            echo '</div>';
                                            $printed[] = $col;
                                        }
                                        echo '</div>';
                                    }
                                    // collect unprinted available fields for Advanced panel
                                    $left = array_values(array_diff($available, $printed));
                                    foreach ($left as $col) {
                                        if (in_array($col, $excludeEdit, true)) continue;
                                        $leftoverFields[] = $col;
                                    }
                                }
                                // if this is the Location group, render an interactive map
                                if ($g === 'Location') {
                                    $latVal = htmlspecialchars((string)($row['latitude'] ?? ''));
                                    $lonVal = htmlspecialchars((string)($row['longitude'] ?? ''));
                                    echo '<div id="poi-map"></div>';
                                    // store lat/lon for JS below by embedding hidden inputs
                                    echo '<input type="hidden" id="poi-map-lat" value="' . $latVal . '" />';
                                    echo '<input type="hidden" id="poi-map-lon" value="' . $lonVal . '" />';
                                }
                            }

                            // render a tags editor (if the table supports tags). We render key/value rows
                            // and serialize into the hidden `tags` field on submit via small JS below.
                            if (in_array('tags', $tableCols, true) || in_array('tags_text', $tableCols, true)) {
                                // Prefer `tags_text` column if present, fallback to `tags` for compatibility
                                $existingRaw = $row['tags_text'] ?? ($row['tags'] ?? null);
                                $existingTags = $parseTags($existingRaw);
                                $phKey = htmlspecialchars(t('tag_key','Key'));
                                $phVal = htmlspecialchars(t('tag_value','Value'));
                                echo '<h4 class="poi-section">' . htmlspecialchars(t('tags','Tags')) . '</h4>';
                                echo '<div id="tags-editor">';
                                if (!empty($existingTags) && is_array($existingTags)) {
                                    foreach ($existingTags as $k => $v) {
                                        $kEsc = htmlspecialchars($k);
                                        $vEsc = htmlspecialchars($v);
                                        echo '<div class="tag-row"><input class="tag-key" name="tags_key[]" value="' . $kEsc . '" placeholder="' . $phKey . '" /> <input class="tag-val" name="tags_val[]" value="' . $vEsc . '" placeholder="' . $phVal . '" /> <button type="button" class="btn btn-small btn-remove-tag">' . htmlspecialchars(t('remove','Remove')) . '</button></div>';
                                    }
                                }
                                // empty template row (hidden) for JS cloning
                                echo '<div id="tag-row-template"><div class="tag-row"><input class="tag-key" name="tags_key[]" value="" placeholder="' . $phKey . '" /> <input class="tag-val" name="tags_val[]" value="" placeholder="' . $phVal . '" /> <button type="button" class="btn btn-small btn-remove-tag">' . htmlspecialchars(t('remove','Remove')) . '</button></div></div>';
                                echo '<div class="tag-actions"><button type="button" id="add-tag-btn" class="btn">' . htmlspecialchars(t('add_tag','Add tag')) . '</button></div>';
                                // hidden serialized tags_text field that server will save
                                $rawTags = htmlspecialchars($existingRaw ?? '');
                                echo '<input type="hidden" name="tags_text" id="tags-text-serialized" value="' . $rawTags . '" />';
                                echo '</div>';
                            }

                            // any leftover editable columns not mapped into groups -> merge with previously-collected leftovers
                            $allPrinted = [];
                            foreach ($groups as $g) $allPrinted = array_merge($allPrinted, array_intersect($g, $orderedEdit));
                            $remaining = array_values(array_diff($orderedEdit, $allPrinted));
                            foreach ($remaining as $col) {
                                if (in_array($col, $excludeEdit, true)) continue;
                                $leftoverFields[] = $col;
                            }

                            // render Advanced panel with leftover fields in two-column responsive layout
                            if (!empty($leftoverFields)) {
                                    echo '<details class="details-section"><summary class="details-summary">' . htmlspecialchars(t('advanced_fields','Advanced fields')) . '</summary>';
                                echo '<div class="details-grid">';
                                foreach (array_values(array_unique($leftoverFields)) as $col) {
                                    if (in_array($col, $excludeEdit, true)) continue;
                                    $val = $row[$col] ?? '';
                                    $label = $labelMap[$col] ?? ucwords(str_replace('_', ' ', $col));
                                    echo '<div><label>' . htmlspecialchars($label) . '<br />';
                                    if ($col === 'description') echo '<textarea name="' . htmlspecialchars($col) . '" class="poi-textarea">' . htmlspecialchars($val) . '</textarea>';
                                    else echo '<input name="' . htmlspecialchars($col) . '" value="' . htmlspecialchars($val) . '" class="poi-field-full" />';
                                    echo '</label></div>';
                                }
                                echo '</div></details>';
                            }
                        ?>
                        <div class="form-actions"><button class="btn" type="submit"><?php echo htmlspecialchars(t('save_changes','Save changes')); ?></button><a class="btn btn-cancel" href="<?php echo htmlspecialchars(app_url('/locations/view') . '?id=' . urlencode($id)); ?>"><?php echo htmlspecialchars(t('cancel','Cancel')); ?></a></div>
                    </form>
                <?php else: ?>
                    <div class="error"><?php echo htmlspecialchars(t('no_permission_edit_poi','You do not have permission to edit this POI.')); ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</main>

    <!-- Leaflet CSS/JS for interactive map (used for edit + view maps) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="<?php echo asset_url('assets/vendor/leaflet/leaflet.js'); ?>"></script>
    <script>
    (function(){
        try {
            function initMap(id, lat, lon, opts) {
                if (!document.getElementById(id)) return;
                var hasCoords = lat !== null && lon !== null && !isNaN(lat) && !isNaN(lon) && lat !== 0 && lon !== 0;
                var map = L.map(id);
                if (hasCoords) map.setView([lat, lon], 15);
                else map.setView([0,0], 2);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'© OpenStreetMap contributors'}).addTo(map);
                var marker = L.marker(hasCoords ? [lat, lon] : map.getCenter(), {draggable: !!opts.draggable}).addTo(map);
                if (opts.draggable) {
                    marker.on('dragend', function(){
                        var p = marker.getLatLng();
                        var latInput = document.querySelector('input[name="latitude"]');
                        var lonInput = document.querySelector('input[name="longitude"]');
                        if (latInput) latInput.value = p.lat;
                        if (lonInput) lonInput.value = p.lng;
                    });
                    map.on('click', function(e){
                        marker.setLatLng(e.latlng);
                        var latInput = document.querySelector('input[name="latitude"]');
                        var lonInput = document.querySelector('input[name="longitude"]');
                        if (latInput) latInput.value = e.latlng.lat;
                        if (lonInput) lonInput.value = e.latlng.lng;
                    });
                    var latInput = document.querySelector('input[name="latitude"]');
                    var lonInput = document.querySelector('input[name="longitude"]');
                    [latInput, lonInput].forEach(function(el){ if(!el) return; el.addEventListener('change', function(){
                        var la = parseFloat(latInput.value)||0, lo = parseFloat(lonInput.value)||0; marker.setLatLng([la,lo]); map.setView([la,lo], 15);
                    }); });
                }
            }

            // init edit map if present (reads hidden inputs)
            var latEdit = parseFloat(document.getElementById('poi-map-lat')?.value || '0');
            var lonEdit = parseFloat(document.getElementById('poi-map-lon')?.value || '0');
            if (document.getElementById('poi-map')) initMap('poi-map', isNaN(latEdit)?null:latEdit, isNaN(lonEdit)?null:lonEdit, {draggable:true});

            // init view map if present (server-side coords)
            var viewLat = <?php echo json_encode(isset($row['latitude']) && $row['latitude'] !== null && $row['latitude'] !== '' ? (float)$row['latitude'] : null); ?>;
            var viewLon = <?php echo json_encode(isset($row['longitude']) && $row['longitude'] !== null && $row['longitude'] !== '' ? (float)$row['longitude'] : null); ?>;
            if (document.getElementById('poi-map-view')) initMap('poi-map-view', viewLat, viewLon, {draggable:false});

            // Tags editor: add/remove rows and serialize to hidden input before submit
            try {
                var tagsEditor = document.getElementById('tags-editor');
                if (tagsEditor) {
                    var tmpl = document.getElementById('tag-row-template');
                    var addBtn = document.getElementById('add-tag-btn');
                    addBtn?.addEventListener('click', function(){
                        var clone = tmpl.cloneNode(true);
                        clone.style.display = '';
                        clone.id = '';
                        // insert before template so template remains last
                        tmpl.parentNode.insertBefore(clone, tmpl);
                        bindRemoveButtons();
                    });
                    function bindRemoveButtons(){
                        Array.from(tagsEditor.querySelectorAll('.btn-remove-tag')).forEach(function(b){
                            if (b._bound) return; b._bound = true;
                            b.addEventListener('click', function(){ var r = b.closest('.tag-row'); if (r) r.remove(); });
                        });
                    }
                    bindRemoveButtons();
                    var form = document.getElementById('poi-edit-form');
                    if (form) form.addEventListener('submit', function(e){
                        // gather key/value pairs and serialize into tags_text in hstore-like format: key=>"value",...
                        var keys = Array.from(tagsEditor.querySelectorAll('.tag-key')).map(function(i){ return i.value.trim(); });
                        var vals = Array.from(tagsEditor.querySelectorAll('.tag-val')).map(function(i){ return i.value; });
                        var parts = [];
                        for (var i=0;i<keys.length;i++){
                            var k = keys[i]; if (!k) continue; var v = vals[i] || '';
                            // escape double quotes and backslashes inside value
                            var safe = String(v).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
                            parts.push(k + '=>"' + safe + '"');
                        }
                        var outText = parts.join(',');
                        var hidden = document.getElementById('tags-text-serialized');
                        if (hidden) hidden.value = outText;
                    });
                }
            } catch (e) { console.error('tags editor error', e); }

        } catch (e) { console.error('Map init error', e); }
    })();
    </script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>