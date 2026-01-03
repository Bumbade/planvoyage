// PoiMapManager_Quick.js - lightweight quick-search module
// Completely separates the search input/button from filter-driven map fetches.

function makeApiCandidates(relPath, queryString) {
    const raw = String(window.API_BASE || window.APP_BASE || '/');
    let b = raw || '/';
    // Normalize: ensure trailing slash and strip any trailing index.php or /src segment
    if (b.charAt(b.length-1) !== '/') b += '/';
    b = b.replace(/\/index\.php\/?$/i, '/');
    const rootBase = b.replace(/\/src\/?$/i, '/');
    const withTrailing = base => (base.charAt(base.length-1) === '/' ? base : base + '/');
    // Build candidates: prefer absolute origin-prefixed top-level API path first,
    // then origin + src/, then relative top-level and relative src/.
    const origin = (window.location && window.location.origin) ? window.location.origin.replace(/\/$/, '') : '';
    // Limit candidates to at most two: origin-prefixed top-level API, then relative top-level.
    const orderedBases = [];
    if (origin) orderedBases.push(origin + withTrailing(rootBase));
    // Always include a relative top-level candidate as a second fallback
    orderedBases.push(withTrailing(rootBase));
    const seen = new Set(); const out = [];
    for (let baseCandidate of orderedBases) {
        let urlBase = baseCandidate;
        if (urlBase.charAt(urlBase.length-1) !== '/') urlBase += '/';
        const url = urlBase + relPath + (queryString ? ('?' + queryString) : '');
        if (!seen.has(url)) { seen.add(url); out.push(url); }
    }
    return out;
}

async function safeParseJson(resp, label) {
    const txt = await resp.text();
    try { return JSON.parse(txt); } catch (e) { throw new Error('Invalid JSON from ' + (label || 'server') + ': ' + (txt && txt.slice ? txt.slice(0,200) : txt)); }
}

function ensureResultsContainer() {
    let container = document.getElementById('poi-search-results');
    const input = document.getElementById('poi-search');
    if (!container) {
        container = document.createElement('div'); container.id = 'poi-search-results'; container.className = 'poi-search-results';
        container.style.cssText = 'margin-top:6px;max-height:280px;overflow:auto;background:#fff;padding:6px;border:1px solid #ddd;border-radius:4px;display:none;';
        // Insert after the flex-row containing input and button
        if (input && input.parentNode && input.parentNode.parentNode) {
            const flexRow = input.parentNode;
            flexRow.parentNode.insertBefore(container, flexRow.nextSibling);
        } else if (input && input.parentNode) {
            input.parentNode.appendChild(container);
        } else {
            document.body.appendChild(container);
        }
    }
    return container;
}

let searchCountdownTimer = null;

function renderStatus(state, msg, timeoutSeconds) {
    const c = ensureResultsContainer(); c.innerHTML = ''; c.style.display = 'block';
    const p = document.createElement('div'); p.className = 'poi-search-status';
    
    // Clear any existing countdown timer
    if (searchCountdownTimer) {
        clearInterval(searchCountdownTimer);
        searchCountdownTimer = null;
    }
    
    // Add countdown if timeout specified
    if (timeoutSeconds && timeoutSeconds > 0) {
        let remaining = Math.ceil(timeoutSeconds);
        p.innerHTML = msg + ' <span style="color:#666;">(' + remaining + 's)</span>';
        searchCountdownTimer = setInterval(() => {
            remaining--;
            if (remaining <= 0) {
                clearInterval(searchCountdownTimer);
                searchCountdownTimer = null;
                p.innerHTML = msg + ' <span style="color:#666;">(timeout...)</span>';
            } else {
                p.innerHTML = msg + ' <span style="color:#666;">(' + remaining + 's)</span>';
            }
        }, 1000);
    } else {
        p.textContent = msg || state;
    }
    
    c.appendChild(p);
}

function renderResults(rows) {
    const c = ensureResultsContainer(); c.innerHTML = ''; c.style.display = 'block';
    // Clear countdown timer when showing results
    if (searchCountdownTimer) {
        clearInterval(searchCountdownTimer);
        searchCountdownTimer = null;
    }
    if (!rows || !rows.length) { renderStatus('no_results', 'No results'); return; }
    const ul = document.createElement('ul'); ul.style.padding = '0'; ul.style.margin = '0';
    rows.forEach(r => {
        const name = r.name || r.display_name || '(no name)';
        const lat = r.lat || r.latitude || (r.center && r.center.lat);
        const lon = r.lon || r.longitude || (r.center && r.center.lon);
        const li = document.createElement('li'); li.style.listStyle = 'none'; li.style.padding = '6px 4px'; li.style.cursor = 'pointer';
        li.textContent = name;
        li.dataset.lat = lat; li.dataset.lon = lon;
        li.addEventListener('click', (ev) => {
            try {
                ev.preventDefault(); const la = parseFloat(li.dataset.lat); const lo = parseFloat(li.dataset.lon);
                if (!isNaN(la) && !isNaN(lo) && window.PV_POI_MANAGER && window.PV_POI_MANAGER.map) {
                    window.PV_POI_MANAGER.map.setView([la, lo], 16);
                    try { L.popup().setLatLng([la, lo]).setContent(name).openOn(window.PV_POI_MANAGER.map); } catch (e) {}
                }
            } catch (e) {}
        });
        ul.appendChild(li);
    });
    c.appendChild(ul);
}

function renderOverpassUnavailable(term, diagnostic) {
    const c = ensureResultsContainer(); c.innerHTML = ''; c.style.display = 'block';
    // Clear countdown timer when showing error
    if (searchCountdownTimer) {
        clearInterval(searchCountdownTimer);
        searchCountdownTimer = null;
    }
    const p = document.createElement('div'); p.className = 'poi-search-status';
    p.textContent = 'Overpass is currently unavailable. Try again?';
    c.appendChild(p);
    const btn = document.createElement('button'); btn.className = 'btn btn-small'; btn.style.marginTop = '6px'; btn.textContent = 'Retry Overpass';
    btn.addEventListener('click', (ev) => {
        ev.preventDefault(); renderStatus('searching','Retrying…', 20); doQuickSearch(term, { forceOverpass: true });
    });
    c.appendChild(btn);
    if (window.POI_DEBUG && diagnostic) {
        const pre = document.createElement('pre'); pre.style.maxHeight = '200px'; pre.style.overflow = 'auto'; pre.style.marginTop = '8px';
        try { pre.textContent = JSON.stringify(diagnostic, null, 2); } catch (e) { pre.textContent = String(diagnostic); }
        c.appendChild(pre);
    }
}

async function doQuickSearch(term, opts = {}) {
    term = String(term || '').trim(); if (!term) return renderStatus('empty','Please enter a search');
    opts = opts || {};
    renderStatus('searching','Searching…'); // No countdown for DB search (fast)
    const diagnostics = [];
    // If not forcing Overpass, try DB-first
    const params = new URLSearchParams({ search: term, limit: 10 });
    const dbCandidates = makeApiCandidates('api/locations/search.php', params.toString());
    if (!opts.forceOverpass) {
        // Query DB-first: if any candidate responds with non-empty results, use them.
        // If DB responds successfully but with an empty result set, fall back to Overpass quick proxy.
        let dbRespondedEmpty = false;
        for (const u of dbCandidates) {
            try {
                const resp = await fetch(u, { credentials: 'same-origin', signal: AbortSignal.timeout(8000) });
                if (!resp || !resp.ok) continue;
                const j = await safeParseJson(resp, u);
                const rows = Array.isArray(j.data) ? j.data : (Array.isArray(j) ? j : []);
                if (rows && rows.length) {
                    renderResults(rows);
                    return rows;
                }
                // Empty but valid DB response; note and stop checking other DB endpoints.
                dbRespondedEmpty = true;
                break;
            } catch (e) { continue; }
        }
    }
    // Overpass quick proxy fallback
    // Add bbox from map if available
    let bbox = '';
    try { if (window.PV_POI_MANAGER && window.PV_POI_MANAGER.map) { const b = window.PV_POI_MANAGER.map.getBounds(); bbox = [b.getSouth(), b.getWest(), b.getNorth(), b.getEast()].join(','); } } catch (e) {}
    const overParams = new URLSearchParams({ search: term, limit: 10 }); if (bbox) overParams.set('bbox', bbox);
    // Only use the lightweight quick Overpass proxy for quick search (no v2 fallback)
    let overCandidates = makeApiCandidates('api/locations/search_overpass_quick.php', overParams.toString());
    // Limit to a single Overpass proxy attempt to avoid repeated long waits
    if (Array.isArray(overCandidates) && overCandidates.length > 1) {
        overCandidates = [overCandidates[0]];
    }
    // If DB returned an empty but valid response, or no DB candidate succeeded, try Overpass quick proxy.
    // Overpass queries may take longer; match server-side timeout and allow more time
    const overTimeout = 20000;
    renderStatus('searching', 'Searching Overpass…', overTimeout / 1000); // Show countdown for Overpass
    for (const u of overCandidates) {
        try {
            const resp = await fetch(u, { credentials: 'same-origin', signal: AbortSignal.timeout(overTimeout) });
            if (!resp) continue;
            // If the server returned non-OK, try to parse body for JSON diagnostic
            if (!resp.ok) {
                try {
                    const maybe = await resp.text();
                    diagnostics.push({ url: u, status: resp.status, body: maybe.slice ? maybe.slice(0,400) : String(maybe) });
                } catch (e) {}
                continue;
            }
            const j = await safeParseJson(resp, u);
            if (j && j.error) {
                if (j.error === 'overpass_unreachable') {
                    // Show retry UI to the user
                    renderOverpassUnavailable(term, j.diagnostic);
                    return [];
                }
                // Try next candidate for other errors
                diagnostics.push({ url: u, error: j.error, diagnostic: j.diagnostic || null });
                continue;
            }
            const rows = Array.isArray(j.data) ? j.data : (Array.isArray(j) ? j : []);
            renderResults(rows);
            return rows;
        } catch (e) { continue; }
    }
    if (window.POI_DEBUG && diagnostics.length) {
        const c = ensureResultsContainer();
        const pre = document.createElement('pre'); pre.style.maxHeight = '300px'; pre.style.overflow = 'auto';
        try { pre.textContent = JSON.stringify(diagnostics, null, 2); } catch (ee) { pre.textContent = String(diagnostics); }
        c.appendChild(pre);
    }
    renderStatus('no_results','No results');
    return [];
}

function bindQuickSearch() {
    const input = document.getElementById('poi-search');
    const btn = document.getElementById('poi-search-btn');
    if (!input || !btn) return;
    
    // Minimum zoom level required to enable search (zoom 8 = city level)
    const MIN_ZOOM = 8;
    
    // Start with input disabled until zoom check passes
    input.disabled = true;
    input.readOnly = true;
    btn.disabled = true;
    input.placeholder = 'Zoom in (level ' + MIN_ZOOM + '+) to search';
    input.style.cursor = 'not-allowed';
    input.style.backgroundColor = '#f0f0f0';
    
    // Function to update input state based on zoom
    const updateSearchState = () => {
        try {
            const map = window.PV_POI_MANAGER && window.PV_POI_MANAGER.map;
            if (!map) return;
            const zoom = map.getZoom();
            const enabled = zoom >= MIN_ZOOM;
            input.disabled = !enabled;
            input.readOnly = !enabled;
            btn.disabled = !enabled;
            if (!enabled) {
                input.placeholder = 'Zoom in (level ' + MIN_ZOOM + '+) to search';
                input.value = '';
                input.style.cursor = 'not-allowed';
                input.style.backgroundColor = '#f0f0f0';
                ensureResultsContainer().style.display = 'none';
            } else {
                input.placeholder = 'Search by name (press Enter)';
                input.style.cursor = '';
                input.style.backgroundColor = '';
            }
        } catch (e) {}
    };
    
    // Listen to map zoom changes
    try {
        const map = window.PV_POI_MANAGER && window.PV_POI_MANAGER.map;
        if (map) {
            map.on('zoomend', updateSearchState);
            map.on('moveend', updateSearchState);
            // Initial state check
            updateSearchState();
        } else {
            // Map not ready yet, poll for it
            const checkMapInterval = setInterval(() => {
                const m = window.PV_POI_MANAGER && window.PV_POI_MANAGER.map;
                if (m) {
                    clearInterval(checkMapInterval);
                    m.on('zoomend', updateSearchState);
                    m.on('moveend', updateSearchState);
                    updateSearchState();
                }
            }, 500);
            // Stop polling after 30 seconds
            setTimeout(() => clearInterval(checkMapInterval), 30000);
        }
    } catch (e) {}
    
    const handler = (ev) => { 
        ev && ev.preventDefault && ev.preventDefault(); 
        // Block search if input is disabled (zoom too low)
        if (input.disabled) return;
        const term = String(input.value || '').trim(); 
        if (!term) return; 
        doQuickSearch(term); 
    };
    btn.addEventListener('click', handler);
    input.addEventListener('keydown', (ev) => { 
        // Block all input when disabled
        if (input.disabled || input.readOnly) {
            ev.preventDefault();
            ev.stopPropagation();
            return false;
        }
        if (ev.key === 'Enter') handler(ev); 
    });
    
    // Block keyboard input completely when disabled
    input.addEventListener('keypress', (ev) => {
        if (input.disabled || input.readOnly) {
            ev.preventDefault();
            ev.stopPropagation();
            return false;
        }
    });
    
    // Block paste events when disabled
    input.addEventListener('paste', (ev) => {
        if (input.disabled || input.readOnly) {
            ev.preventDefault();
            ev.stopPropagation();
            return false;
        }
    });

    // Autocomplete: debounce input and query DB search endpoint (fast)
    let acTimer = null;
    let acController = null;
    const debounceMs = 250;
    input.addEventListener('input', (ev) => {
        // Block autocomplete if input is disabled (zoom too low)
        if (input.disabled) {
            ev.preventDefault();
            input.value = '';
            return;
        }
        const term = String(input.value || '').trim();
        if (acTimer) { clearTimeout(acTimer); acTimer = null; }
        if (acController) { try { acController.abort(); } catch(e){} acController = null; }
        if (!term || term.length < 2) { /* hide suggestions */ const c = ensureResultsContainer(); c.innerHTML = ''; c.style.display = 'none'; return; }
        acTimer = setTimeout(async () => {
            acController = new AbortController();
            const params = new URLSearchParams({ q: term, limit: 10 });
            const cands = makeApiCandidates('api/locations/search.php', params.toString());
            if (!cands || !cands.length) return;
            try {
                const resp = await fetch(cands[0], { credentials: 'same-origin', signal: acController.signal });
                if (!resp || !resp.ok) return;
                const j = await safeParseJson(resp, 'autocomplete');
                const rows = Array.isArray(j.data) ? j.data : (Array.isArray(j) ? j : []);
                // Render suggestions (click to center). Do not trigger Overpass here.
                renderResults(rows);
            } catch (e) {
                // ignore autocomplete errors silently
            } finally {
                acController = null;
            }
        }, debounceMs);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    try { bindQuickSearch(); } catch (e) { console.warn('PoiMapManager_Quick init failed', e); }
});

export default { doQuickSearch };
