<?php
// Test script to verify search_overpass_v2.php works for tobacco

$_GET['bbox'] = '51.126475,-115.617542,51.210217,-115.446224';
$_GET['types'] = 'tobacco';
$_GET['limit'] = '200';
$_GET['search'] = '';

// Include the API
ob_start();
include(__DIR__ . '/src/api/locations/search_overpass_v2.php');
$output = ob_get_clean();

echo "Response from search_overpass_v2.php:\n";
echo $output . "\n";

$decoded = json_decode($output, true);
if ($decoded) {
	echo "\nDecoded response:\n";
	echo "Page: " . $decoded['page'] . "\n";
	echo "Per page: " . $decoded['per_page'] . "\n";
	echo "Data count: " . count($decoded['data'] ?? []) . "\n";
	if (isset($decoded['error'])) {
		echo "Error: " . $decoded['error'] . "\n";
		echo "Message: " . $decoded['message'] . "\n";
	}
} else {
	echo "Failed to decode response as JSON\n";
}
?>
