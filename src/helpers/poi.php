<?php
// helpers/poi.php - POI helper utilities

if (!function_exists('get_poi_categories')) {
    function get_poi_categories() {
        return [
            'hotel','attraction','tourist_info','food','nightlife','gas_stations','charging_station','parking',
            'bank','healthcare','fitness','laundry','supermarket','tobacco','cannabis',
            'transport','dump_station','campgrounds'
        ];
    }
}

?>