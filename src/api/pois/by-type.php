<?php

// API: Get POIs by type (from MySQL locations table)
// This ensures the map shows the SAME locations as the location_dropdown
// Parameters:
//   - poi_type: Category key (e.g., 'hotels', 'food') or direct location type (e.g., 'Hotel', 'Food')
//   - country: Optional country filter
//   - state: Optional state filter

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../../config/mysql.php';
    $db = get_db();

    $poiType = $_GET['poi_type'] ?? '';
    $country = $_GET['country'] ?? '';
    $state = $_GET['state'] ?? '';

    if (!$poiType) {
        http_response_code(400);
        echo json_encode(['error' => 'poi_type parameter required', 'ok' => false]);
        exit;
    }

    // Map category keys to location types (matching 19 active filters)
    $categoryToTypeMap = [
        'hotel' => 'Hotel',
        'food' => 'Food',
        'tourist_info' => 'Tourist Information',
        'attraction' => 'Attractions',
        'nightlife' => 'Nightlife',
        'gas_stations' => 'Gas Station',
        'charging_station' => 'Charging Station',
        'parking' => 'Parking',
        'bank' => 'Bank',
        'healthcare' => 'Healthcare',
        'fitness' => 'Fitness',
        'laundry' => 'Laundry',
        'supermarket' => 'Supermarket',
        'tobacco' => 'Tobacco / Vape',
        'cannabis' => 'Cannabis',
        'transport' => 'Transport',
        'dump_station' => 'Dump Station',
        'campgrounds' => 'Campground',
        'natureparks' => 'Nature Parks'
    ];

    // Convert category key to location type if needed
    $locationType = $poiType;
    if (isset($categoryToTypeMap[strtolower($poiType)])) {
        $locationType = $categoryToTypeMap[strtolower($poiType)];
    }

    // Query locations table - SAME SOURCE as location_dropdown
    $query = 'SELECT id, name, type, latitude, longitude, country, state, city FROM locations WHERE 1=1';
    $params = [];

    if ($locationType) {
        $query .= ' AND type = :type';
        $params[':type'] = $locationType;
    }
    if ($country) {
        $query .= ' AND country = :country';
        $params[':country'] = $country;
    }
    if ($state) {
        $query .= ' AND state = :state';
        $params[':state'] = $state;
    }

    $query .= ' ORDER BY name ASC LIMIT 1000';

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $pois = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Rename latitude/longitude to lat/lon for map compatibility
    $pois = array_map(function ($poi) {
        $poi['lat'] = $poi['latitude'];
        $poi['lon'] = $poi['longitude'];
        unset($poi['latitude'], $poi['longitude']);
        return $poi;
    }, $pois);

    http_response_code(200);
    echo json_encode(['pois' => $pois, 'ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'ok' => false]);
}
