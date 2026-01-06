<?php
$s = file_get_contents('src/api/locations/search.php');
$lines = explode("\n", $s);
$open = $close = 0;
foreach ($lines as $i => $ln) {
    $open += substr_count($ln, '{');
    $close += substr_count($ln, '}');
    $balance = $open - $close;
    // print lines in the suspect region and any place balance drops below zero
    if (($i+1) >= 1 && ($i+1) <= 500) {
        if ($balance < 0 || ($i+1 >= 200 && $i+1 <= 360 && ($i+1 % 1) === 0)) {
            echo sprintf("%4d: balance=%d | %s\n", $i+1, $balance, $ln);
        }
    }
}
echo "final_open:" . $open . " final_close:" . $close . "\n";
// print lines that are standalone closing braces to inspect context
echo "Standalone closing brace lines:\n";
foreach ($lines as $i => $ln) {
    if (trim($ln) === '}') {
        echo ($i+1) . "\n";
    }
}
// detect consecutive closing-brace-only lines
echo "Consecutive closing brace lines (pairs):\n";
for ($i=0;$i<count($lines)-1;$i++) {
    if (trim($lines[$i]) === '}' && trim($lines[$i+1]) === '}') {
        echo ($i+1) . " and " . ($i+2) . "\n";
    }
}
