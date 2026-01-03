<?php
// Test script to check tobacco Overpass QL generation

$maps = [
	'tobacco' => [
		['k'=>'vending','v'=>'cigarettes'],
		['k'=>'shop','v'=>'tobacco'],
		['k'=>'shop','v'=>'vape'],
		['k'=>'shop','v'=>'vape_shop'],
		['k'=>'shop','v'=>'ecigarette'],
		['k'=>'shop','v'=>'e-cigarette'],
		['k'=>'shop','v'=>'ecig'],
		['k'=>'shop','v'=>'cigar'],
		['k'=>'shop','v'=>'cigars'],
		['k'=>'shop','v'=>'tobacconist'],
		['k'=>'shop','v'=>'smoke_shop'],
	],
];

$minLat = 51.126475;
$minLon = -115.617542;
$maxLat = 51.210217;
$maxLon = -115.446224;

$conds = [];
foreach ($maps['tobacco'] as $m) {
	$baseFilter = sprintf('[%s=%s]', $m['k'], $m['v']);
	$cond = sprintf('node%s(%F,%F,%F,%F);way%s(%F,%F,%F,%F);rel%s(%F,%F,%F,%F);',
		$baseFilter, $minLat, $minLon, $maxLat, $maxLon,
		$baseFilter, $minLat, $minLon, $maxLat, $maxLon,
		$baseFilter, $minLat, $minLon, $maxLat, $maxLon
	);
	$conds[] = $cond;
	echo "Condition: " . $cond . "\n\n";
}

$overpassQL = '[out:json][timeout:25];(' . implode('', $conds) . ');out center;';
echo "Full Overpass QL:\n";
echo $overpassQL;
echo "\n\nLength: " . strlen($overpassQL) . " bytes\n";
?>
