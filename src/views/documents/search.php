<?php
// src/views/documents/search.php
if (file_exists(__DIR__ . '/../../helpers/i18n.php')) {
    require_once __DIR__ . '/../../helpers/i18n.php';
}
// Start session early for user context
if (file_exists(__DIR__ . '/../../helpers/session.php')) {
    require_once __DIR__ . '/../../helpers/session.php';
    start_secure_session();
}
// Load utility functions
if (file_exists(__DIR__ . '/../../helpers/utils.php')) {
    require_once __DIR__ . '/../../helpers/utils.php';
}
global $appBase;
if (!function_exists('config')) {
    require_once __DIR__ . '/../../config/app.php';
}
$appBase = config('app.base') ?? '/Allgemein/planvoyage/src';
require_once __DIR__ . '/../../includes/header.php';
?>
<main>
    <h1><?php echo htmlspecialchars(t('documents', 'Documents')); ?></h1>
    <div class="container">
        <div id="doc-search">
            <input id="doc-q" type="search" placeholder="<?php echo htmlspecialchars(t('documents_search_placeholder', 'Search documents...')); ?>">
            <button id="doc-search-btn"><?php echo htmlspecialchars(t('search', 'Search')); ?></button>
        </div>
        <div id="doc-results"></div>
        <div id="doc-pager"></div>
    </div>
</main>
<script>
// server-side computed API endpoint (respects APP_BASE)
var API_URL = '<?php echo htmlspecialchars(api_url('api/documents'), ENT_QUOTES); ?>';

document.getElementById('doc-search-btn').addEventListener('click', function(){
    var q = document.getElementById('doc-q').value;
    if (!q) return alert('<?php echo htmlspecialchars(t('enter_search_query', 'Please enter a search query'), ENT_QUOTES); ?>');
    var url = API_URL + '?q=' + encodeURIComponent(q) + '&page=1';
    var pagerEl = document.getElementById('doc-pager');
    pagerEl.innerHTML = '<em>' + <?php echo json_encode(t('loading', 'Loading...')); ?> + '</em>';
    fetch(url, { cache: 'no-store' }).then(function(res){
        var el = document.getElementById('doc-results');
            if (!res.ok) {
            res.text().then(function(txt){
                el.innerHTML = '<p class="doc-error">' + <?php echo json_encode(t('server_error', 'Server error')); ?> + ' ' + res.status + '</p><pre>' + escapeHtml(txt.slice(0,2000)) + '</pre>';
            });
            return;
        }
        // try to parse JSON safely
        res.text().then(function(txt){
            try {
                var j = JSON.parse(txt);
                } catch (e) {
                el.innerHTML = '<p class="doc-error">' + <?php echo json_encode(t('invalid_json_response', 'Invalid JSON response from server')); ?> + '</p><pre>'+escapeHtml(txt.slice(0,2000))+'</pre>';
                return;
            }
            if (!j || !Array.isArray(j.data) || j.data.length===0){ el.innerHTML = '<p><?php echo htmlspecialchars(t('no_results', 'No results'), ENT_QUOTES); ?></p>'; pagerEl.innerHTML = ''; return; }
            var html = '<ul>';
            j.data.forEach(function(it){ html += '<li><strong>'+escapeHtml(it.title || it.filename)+'</strong> &middot; '+escapeHtml(it.created_at||'')+'<br>'+escapeHtml((it.content||'').slice(0,300))+'</li>'; });
            html += '</ul>';
            el.innerHTML = html;
            // pager
            var total = j.total || 0; var per = j.per_page || 20; var page = j.page || 1;
            var pages = Math.ceil(total / per);
            if (pages <= 1) { pagerEl.innerHTML = ''; }
            else {
                var ph = '';
                if (page > 1) ph += '<button id="doc-prev">&laquo; Prev</button> ';
                ph += ' Page ' + page + ' of ' + pages + ' ';
                if (page < pages) ph += ' <button id="doc-next">Next &raquo;</button>';
                pagerEl.innerHTML = ph;
                if (page > 1) document.getElementById('doc-prev').addEventListener('click', function(){ doPage(q, page-1); });
                if (page < pages) document.getElementById('doc-next').addEventListener('click', function(){ doPage(q, page+1); });
            }
        });
    }).catch(function(err){ console.error(err); alert('<?php echo htmlspecialchars(t('search_failed', 'Search failed'), ENT_QUOTES); ?>'); });
});
function doPage(q, page){
    var url = API_URL + '?q=' + encodeURIComponent(q) + '&page=' + page;
    var el = document.getElementById('doc-results');
    var pagerEl = document.getElementById('doc-pager');
    pagerEl.innerHTML = '<em>' + <?php echo json_encode(t('loading', 'Loading...')); ?> + '</em>';
    fetch(url, { cache: 'no-store' }).then(function(res){
        if (!res.ok) { pagerEl.innerHTML = '<span class="doc-error">' + <?php echo json_encode(t('server_error', 'Server error')); ?> + ' ' + res.status + '</span>'; return; }
        res.json().then(function(j){
            if (!j || !Array.isArray(j.data) || j.data.length===0){ el.innerHTML = '<p><?php echo htmlspecialchars(t('no_results', 'No results'), ENT_QUOTES); ?></p>'; pagerEl.innerHTML=''; return; }
            var html = '<ul>';
            j.data.forEach(function(it){ html += '<li><strong>'+escapeHtml(it.title || it.filename)+'</strong> &middot; '+escapeHtml(it.created_at||'')+'<br>'+escapeHtml((it.content||'').slice(0,300))+'</li>'; });
            html += '</ul>';
            el.innerHTML = html;
            // rebuild pager (simple)
            var total = j.total || 0; var per = j.per_page || 20; var page = j.page || 1; var pages = Math.ceil(total/per);
            var ph = '';
            if (page > 1) ph += '<button id="doc-prev">&laquo; Prev</button> ';
            ph += ' Page ' + page + ' of ' + pages + ' ';
            if (page < pages) ph += ' <button id="doc-next">Next &raquo;</button>';
            pagerEl.innerHTML = ph;
            if (page > 1) document.getElementById('doc-prev').addEventListener('click', function(){ doPage(q, page-1); });
            if (page < pages) document.getElementById('doc-next').addEventListener('click', function(){ doPage(q, page+1); });
        });
    }).catch(function(){ pagerEl.innerHTML = '<span class="doc-error">' + <?php echo json_encode(t('network_error', 'Network error')); ?> + '</span>'; });
}
// escapeHtml is imported from helpers/utils.php
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
