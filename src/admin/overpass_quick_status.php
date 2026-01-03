<?php
// Admin status page for Overpass quick proxy
require_once __DIR__ . '/../config/env.php';
if (file_exists(__DIR__ . '/../helpers/session.php')) require_once __DIR__ . '/../helpers/session.php';
if (file_exists(__DIR__ . '/../helpers/auth.php')) require_once __DIR__ . '/../helpers/auth.php';
start_secure_session();

if (!function_exists('is_admin_user') || !is_admin_user()) {
    http_response_code(403);
    die('Access denied. Admin only.');
}

$logFile = __DIR__ . '/../../logs/overpass_quick.log';
$cacheDir = __DIR__ . '/../../tmp/overpass_quick_cache';
$apcuAvailable = function_exists('apcu_fetch');

$lastLines = [];
if (file_exists($logFile)) {
    $data = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($data !== false) {
        $lastLines = array_slice($data, -200);
    }
}
// If main log is empty or not writable, try fallback tmp log (written when logs/ isn't writable)
if (empty($lastLines)) {
    $tmpLog = __DIR__ . '/../../tmp/overpass_quick_cache/overpass_quick.log';
    if (file_exists($tmpLog)) {
        $data2 = @file($tmpLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($data2 !== false) {
            $lastLines = array_slice($data2, -200);
        }
    }
}

// Parse recent log lines to build per-mirror statistics
$mirrorStats = [];
$recentAttempts = [];
foreach ($lastLines as $ln) {
    // ATTEMPT lines
    if (preg_match('/^(\S+)\s+ATTEMPT:\s+ep=(\S+)\s+attempt=(\d+)\s+http_code=(\d+)\s+total_time=([0-9\.]+)\s+err=(.*)$/', $ln, $m)) {
        $ts = $m[1];
        $ep = $m[2];
        $attempt = (int)$m[3];
        $http_code = (int)$m[4];
        $total_time = (float)$m[5];
        $err = trim($m[6]);
        // normalize err (strip surrounding quotes if present)
        $err = trim($err, '"');

        if (!isset($mirrorStats[$ep])) {
            $mirrorStats[$ep] = ['attempts'=>0,'successes'=>0,'failures'=>0,'total_time'=>0.0,'last_time'=>null,'last_http'=>null,'last_err'=>null];
        }
        $mirrorStats[$ep]['attempts']++;
        if ($http_code >= 200 && $http_code < 300) {
            $mirrorStats[$ep]['successes']++;
            $mirrorStats[$ep]['total_time'] += $total_time;
        } else {
            $mirrorStats[$ep]['failures']++;
        }
        $mirrorStats[$ep]['last_time'] = $ts;
        $mirrorStats[$ep]['last_http'] = $http_code;
        $mirrorStats[$ep]['last_err'] = $err;

        $recentAttempts[] = ['ts'=>$ts,'ep'=>$ep,'http'=>$http_code,'time'=>$total_time,'err'=>$err,'line'=>$ln];
        continue;
    }

    // OVERPASS_FAIL lines: capture used endpoint and err
    if (preg_match('/^(\S+)\s+OVERPASS_FAIL:\s+used=(.*?)\s+err=(.*?)\s+info=(.*)$/', $ln, $m)) {
        $ts = $m[1];
        $used = trim($m[2]);
        $err = trim($m[3]);
        $used = trim($used, "'\" ");
        if ($used === 'NULL' || $used === 'null' || $used === '') {
            $used = '(none)';
        }
        if (!isset($mirrorStats[$used])) {
            $mirrorStats[$used] = ['attempts'=>0,'successes'=>0,'failures'=>0,'total_time'=>0.0,'last_time'=>null,'last_http'=>null,'last_err'=>null];
        }
        $mirrorStats[$used]['failures']++;
        $mirrorStats[$used]['last_time'] = $ts;
        $mirrorStats[$used]['last_err'] = trim($err, '"');
        $recentAttempts[] = ['ts'=>$ts,'ep'=>$used,'http'=>0,'time'=>0,'err'=>$err,'line'=>$ln];
        continue;
    }
}

// Compute averages
foreach ($mirrorStats as $ep => &$s) {
    $s['avg_time'] = $s['successes'] ? round($s['total_time'] / $s['successes'], 3) : null;
}
unset($s);

$cacheFiles = [];
$totalSize = 0;
if (is_dir($cacheDir)) {
    $it = new DirectoryIterator($cacheDir);
    foreach ($it as $f) {
        if ($f->isFile()) { $cacheFiles[] = $f->getFilename(); $totalSize += $f->getSize(); }
    }
}

// Load persisted mirror stats if present (from auto-rank)
$persistStats = [];
$statsFile = __DIR__ . '/../../tmp/overpass_quick_cache/mirror_stats.json';
if (file_exists($statsFile)) {
    $c = @file_get_contents($statsFile);
    $j = $c ? @json_decode($c, true) : null;
    if (is_array($j)) $persistStats = $j;
}

$HEAD_EXTRA = '<style>.admin-main{background-color:var(--bg-light);min-height:100vh;padding:1rem}.admin-header{padding:1rem 0}.overpass-status pre{background:#fff;border:1px solid #ddd;padding:8px;max-height:400px;overflow:auto}</style>';
include __DIR__ . '/../includes/header.php';
?>
<main class="admin-main">
    <div class="admin-header">
        <h1>Overpass Quick Proxy</h1>
        <p>Operational status, cache and recent logs for the lightweight Overpass proxy used by quick searches.</p>
    </div>

    <section class="overpass-status">
        <p><strong>APCu available:</strong> <?php echo $apcuAvailable ? 'yes' : 'no'; ?></p>
        <p><strong>Cache files:</strong> <?php echo count($cacheFiles); ?> (<?php echo number_format($totalSize); ?> bytes)</p>
        <h2>Mirror Statistics</h2>
        <?php if (empty($mirrorStats)): ?>
            <p>No mirror attempts found in logs.</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead><tr><th>Endpoint</th><th>Attempts</th><th>Successes</th><th>Failures</th><th>Avg Time (s)</th><th>Last HTTP</th><th>Last Error</th><th>Last Time</th></tr></thead>
                <tbody>
                <?php foreach ($mirrorStats as $ep => $st): ?>
                    <tr>
                        <td style="max-width:40ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($ep); ?></td>
                        <td><?php echo (int)$st['attempts']; ?></td>
                        <td><?php echo (int)$st['successes']; ?></td>
                        <td><?php echo (int)$st['failures']; ?></td>
                        <td><?php echo $st['avg_time'] !== null ? htmlspecialchars($st['avg_time']) : '-'; ?></td>
                        <td><?php echo htmlspecialchars($st['last_http'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($st['last_err'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($st['last_time'] ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>Persistent Mirror Stats (auto-rank)</h2>
        <?php if (empty($persistStats)): ?>
            <p>No persisted mirror stats found.</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead><tr><th>Endpoint</th><th>Attempts</th><th>Successes</th><th>Avg ms</th></tr></thead>
                <tbody>
                <?php foreach ($persistStats as $ep => $st): ?>
                    <tr>
                        <td style="max-width:40ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($ep); ?></td>
                        <td><?php echo (int)($st['attempts'] ?? 0); ?></td>
                        <td><?php echo (int)($st['successes'] ?? 0); ?></td>
                        <td><?php echo isset($st['avg_ms']) ? htmlspecialchars(round($st['avg_ms'],1)) : '-'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>Recent Attempts (tail)</h2>
        <pre><?php echo htmlspecialchars(implode("\n", array_slice($lastLines, -200))); ?></pre>
        <h2>Cache Files</h2>
        <ul>
        <?php foreach ($cacheFiles as $cf) { echo '<li>' . htmlspecialchars($cf) . '</li>'; } ?>
        </ul>
    </section>
</main>

<?php include __DIR__ . '/../includes/footer.php';
