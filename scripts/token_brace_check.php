<?php
$s = file_get_contents('src/api/locations/search.php');
$tokens = token_get_all($s);
$open = $close = 0;
foreach ($tokens as $t) {
    if (is_array($t)) {
        $text = $t[1];
        $line = $t[2];
    } else {
        $text = $t;
        // try to infer line by counting newlines in previous tokens
        $line = null;
    }
    if ($text === '{') { $open++; echo "open at line " . ($line ?? '?') . "\n"; }
    if ($text === '}') { $close++; echo "close at line " . ($line ?? '?') . "\n"; }
}
echo "opens=".$open." closes=".$close."\n";
