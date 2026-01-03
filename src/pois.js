// Fetch POIs and display them on a Leaflet map
// Version: 2025-11-15-v2 (syntax fixes applied)
(function () {
    function qs(selector) { return document.querySelector(selector); }

    // Normalize APP_BASE so concatenating paths is safe across this script
    try {
        var __APP_BASE_NORMALIZED = (window.APP_BASE || '/');
        if (typeof __APP_BASE_NORMALIZED !== 'string') __APP_BASE_NORMALIZED = String(__APP_BASE_NORMALIZED);
        if (__APP_BASE_NORMALIZED.charAt(__APP_BASE_NORMALIZED.length - 1) !== '/') __APP_BASE_NORMALIZED += '/';
        window.APP_BASE = __APP_BASE_NORMALIZED;
    } catch (e) { /* ignore */ }

    // Delegate all display-name logic to PoiMapManager (centralized implementation)
    var poiDisplayName;
    if (window.PoiMapManager && typeof PoiMapManager.poiDisplayName === 'function') {
        poiDisplayName = function(poi) { return PoiMapManager.poiDisplayName(poi); };
    } else {
        // Minimal guard: log missing central implementation and return empty string
        console.warn('PoiMapManager.poiDisplayName not found; include src/assets/js/PoiMapManager.js');
        poiDisplayName = function(poi) { return ''; };
    }
    try { window.poiDisplayName = poiDisplayName; } catch (e) {}

    // Debug logging helper: enable by setting `window.POI_DEBUG = true` in the console
    if (typeof window.POI_DEBUG === 'undefined') window.POI_DEBUG = false;
    function poiDebug() {
        if (!window.POI_DEBUG) return;
        try { if (console && console.log) console.log.apply(console, arguments); } catch (e) {}
    }

    function initMap() {
    // Default view: show the whole world on initial load
    // Center 0,0 and low zoom so global view is visible immediately
    var map = L.map('pois-map').setView([0.0, 0.0], 2);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        var markers = L.markerClusterGroup();
        map.addLayer(markers);
        // Helper to clear all POI-related visible layers and state
        function clearPoiLayers() {
            try { if (markers && typeof markers.clearLayers === 'function') markers.clearLayers(); } catch (e) {}
            try { if (window._poi_postgis_visible_layer && typeof window._poi_postgis_visible_layer.clearLayers === 'function') window._poi_postgis_visible_layer.clearLayers(); } catch (e) {}
            try { if (window._poi_postgis_layer && typeof window._poi_postgis_layer.clearLayers === 'function') window._poi_postgis_layer.clearLayers(); } catch (e) {}
            try { if (window._poi_debug_layer && typeof window._poi_debug_layer.clearLayers === 'function') window._poi_debug_layer.clearLayers(); } catch (e) {}
            try { markerByOsm = {}; poiByOsm = {}; selectedOsm = new Set(); updateSelectedUI(); } catch (e) {}
        }
        // expose for debugging in the console
        try { window._poi_map = map; window._poi_markers = markers; } catch (e) {}

        // Create a simple loading spinner overlay (hidden by default)
        try {
            if (!document.getElementById('pois-loading-spinner')) {
                var spinner = document.createElement('div'); spinner.id = 'pois-loading-spinner';
                spinner.style.display = 'none';
                spinner.style.position = 'absolute';
                spinner.style.left = '50%';
                spinner.style.top = '10px';
                spinner.style.transform = 'translateX(-50%)';
                spinner.style.zIndex = 9999;
                spinner.innerHTML = '<div style="background:rgba(255,255,255,0.95);padding:6px 10px;border-radius:6px;border:1px solid #ddd;box-shadow:0 2px 6px rgba(0,0,0,0.08)"><span class="spinner" style="display:inline-block;width:18px;height:18px;border:3px solid #ccc;border-top-color:#2b8af3;border-radius:50%;animation:spin 1s linear infinite;margin-right:8px;vertical-align:middle"></span><span style="vertical-align:middle">Loading POIs…</span></div>';
                var ss2 = document.createElement('style'); ss2.id = 'pois-js-spinner-style';
                ss2.textContent = '@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}';
                document.head.appendChild(ss2);
                var mapEl = document.getElementById('pois-map');
                if (mapEl) mapEl.style.position = mapEl.style.position || 'relative';
                if (mapEl) mapEl.appendChild(spinner);
            }
        } catch (e) { /* ignore spinner inject errors */ }

        // inject minimal CSS for selected markers (only once)
        try {
            if (!document.getElementById('pois-js-selected-style')) {
                var ss = document.createElement('style'); ss.id = 'pois-js-selected-style';
                ss.textContent = '\n.leaflet-marker-icon.poi-selected{ box-shadow: 0 0 8px 3px rgba(0,150,0,0.6); transform: scale(1.08); }\n.flash-poi{ animation: flash 1s ease; }\n@keyframes flash{0%{transform:scale(1)}50%{transform:scale(1.2)}100%{transform:scale(1)}}\n';
                document.head.appendChild(ss);
            }
            // inject list/grid styles for POI groups and cards
            if (!document.getElementById('pois-list-style')) {
                var ssList = document.createElement('style'); ssList.id = 'pois-list-style';
                ssList.textContent = '\n#poi-list{ margin-top:12px; }\n.poi-group{ margin:18px 0; padding-bottom:8px; border-bottom:1px solid #eee; }\n.poi-group h3{ margin:8px 0 12px; font-size:1.1em; }\n.poi-grid{ display:grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap:12px; align-items:start; }\n.poi-card{ background:#fff; border:1px solid #eee; border-radius:6px; padding:10px; box-shadow:0 1px 3px rgba(0,0,0,0.04); display:flex; flex-direction:column; min-height:120px; }\n.poi-logo{ width:64px; height:64px; object-fit:cover; border-radius:50%; margin-bottom:8px; align-self:center; }\n.poi-name{ font-weight:600; margin-bottom:6px; text-align:center; }\n.poi-meta{ font-size:0.9em; color:#666; text-align:center; margin-bottom:6px; }\n.poi-card a{ color:#2b8af3; }\n';
                document.head.appendChild(ssList);
            }
        } catch (e) { /* ignore style injection errors */ }

    // selection state for markers (osm_id strings)
    var selectedOsm = new Set();
    var markerByOsm = {}; // map osm_id -> marker (last seen)
    var poiByOsm = {}; // map osm_id -> full poi object (last seen)
    try { window._poi_markerByOsm = markerByOsm; window._poi_poiByOsm = poiByOsm; } catch (e) {}
    try { window._poi_reload = function() { try { var b = document.getElementById('load-pois-btn'); if (b) { b.click(); return; } console.warn('no load-pois-btn found to trigger reload'); } catch(e) { console.error(e); } }; } catch(e) {}
    var lastQueryKey = null; // used to avoid repeat identical queries

    // Configurable PostGIS behavior: allow overriding min zoom and request timeout
    var POIS_POSTGIS_MIN_ZOOM = (typeof window.POIS_POSTGIS_MIN_ZOOM !== 'undefined') ? window.POIS_POSTGIS_MIN_ZOOM : 7;
    var POIS_POSTGIS_TIMEOUT_MS = (typeof window.POIS_POSTGIS_TIMEOUT_MS !== 'undefined') ? window.POIS_POSTGIS_TIMEOUT_MS : 30000;
    try { console.info('POIS: PostGIS min zoom=', POIS_POSTGIS_MIN_ZOOM, 'timeout_ms=', POIS_POSTGIS_TIMEOUT_MS); } catch(e) {}

    // Render a list of POIs that exist in the application MySQL DB
    function renderPoiList(appRows) {
        try {
            var container = document.getElementById('poi-list');
            if (!container) return;
            container.innerHTML = '';
            if (!Array.isArray(appRows) || appRows.length === 0) {
                container.innerHTML = '<div class="notice">No imported POIs in the application database for the visible area.</div>';
                return;
            }
            var base = (window.APP_BASE || '/'); if (base.charAt(base.length - 1) !== '/') base += '/';
            // Group POIs by type so the list can be shown under headings
            var groups = {};
            appRows.forEach(function(poi){
                try {
                    var key = (poi.type && String(poi.type).trim()) ? String(poi.type).trim() : 'poi';
                    if (!groups[key]) groups[key] = [];
                    groups[key].push(poi);
                } catch (e) { /* ignore grouping error */ }
            });

            // Preferred ordering for common types
            var preferredOrder = ['Campground','Park','Hotel','Food','Shopping','Gas Station','Bank','poi'];
            var groupKeys = Object.keys(groups).sort(function(a,b){
                var ia = preferredOrder.indexOf(a);
                var ib = preferredOrder.indexOf(b);
                if (ia !== -1 || ib !== -1) {
                    if (ia === -1) return 1;
                    if (ib === -1) return -1;
                    return ia - ib;
                }
                return a.localeCompare(b);
            });
            // Render each group with a heading and country subgroups
            groupKeys.forEach(function(gk){
                try {
                    var wrapper = document.createElement('div'); wrapper.className = 'poi-group';
                    var header = document.createElement('div'); header.className = 'poi-group-header expanded'; header.setAttribute('role', 'button'); header.setAttribute('tabindex', '0');
                    var title = document.createElement('h3'); title.className = 'poi-group-title';
                    var icon = document.createElement('img'); icon.className = 'poi-group-icon';
                    icon.src = base + 'assets/icons/' + encodeURIComponent(CATEGORY_ICONS[gk] || 'poi.png');
                    icon.alt = '';
                    title.appendChild(icon);
                    var titleSpan = document.createElement('span'); titleSpan.textContent = gk;
                    title.appendChild(titleSpan);
                    var count = document.createElement('span'); count.className = 'poi-group-count'; count.textContent = groups[gk].length;
                    title.appendChild(count);
                    header.appendChild(title);
                    var toggle = document.createElement('div'); toggle.className = 'poi-group-toggle'; toggle.setAttribute('aria-hidden', 'true'); toggle.textContent = '▼';
                    header.appendChild(toggle);
                    wrapper.appendChild(header);
                    var inner = document.createElement('div'); inner.className = 'poi-countries-container'; inner.style.display = 'flex'; inner.style.flexDirection = 'column';
                    
                    // Group POIs by country within this category
                    var countryGroups = {};
                    groups[gk].forEach(function(poi){
                        var country = (poi.country || '').trim() || 'Unknown';
                        if (!countryGroups[country]) countryGroups[country] = [];
                        countryGroups[country].push(poi);
                    });
                    
                    // Sort countries alphabetically and sort POIs within each country
                    var countryKeys = Object.keys(countryGroups).sort();
                    
                    countryKeys.forEach(function(country){
                        // Sort POIs within this country by name
                        var countryPois = countryGroups[country].slice().sort(function(a, b){
                            var nameA = (a.name || '').trim();
                            var nameB = (b.name || '').trim();
                            return nameA.localeCompare(nameB);
                        });
                        
                        // Create collapsible country subgroup (initially collapsed)
                        var countryWrapper = document.createElement('div');
                        countryWrapper.className = 'poi-country-group collapsed';
                        countryWrapper.style.marginTop = '12px';
                        countryWrapper.style.marginBottom = '12px';
                        countryWrapper.style.borderLeft = '4px solid #2b8af3';
                        countryWrapper.style.paddingLeft = '12px';
                        
                        // Country header with toggle
                        var countryHeader = document.createElement('div');
                        countryHeader.className = 'poi-country-header';
                        countryHeader.setAttribute('role', 'button');
                        countryHeader.setAttribute('tabindex', '0');
                        countryHeader.style.display = 'flex';
                        countryHeader.style.alignItems = 'center';
                        countryHeader.style.cursor = 'pointer';
                        countryHeader.style.userSelect = 'none';
                        countryHeader.style.paddingTop = '4px';
                        countryHeader.style.paddingBottom = '4px';
                        
                        var countryToggle = document.createElement('span');
                        countryToggle.style.display = 'inline-block';
                        countryToggle.style.marginRight = '8px';
                        countryToggle.style.fontSize = '12px';
                        countryToggle.textContent = '▶';
                        countryHeader.appendChild(countryToggle);
                        
                        var countryTitle = document.createElement('span');
                        countryTitle.style.fontSize = '14px';
                        countryTitle.style.fontWeight = '600';
                        countryTitle.style.color = '#2b8af3';
                        countryTitle.textContent = country + ' (' + countryPois.length + ')';
                        countryHeader.appendChild(countryTitle);
                        
                        countryWrapper.appendChild(countryHeader);
                        
                        // Container for POIs in this country (initially hidden)
                        var countryContent = document.createElement('div');
                        countryContent.className = 'poi-country-content collapsed';
                        countryContent.style.display = 'none';
                        countryContent.style.gridTemplateColumns = 'repeat(auto-fit, minmax(280px, 1fr))';
                        countryContent.style.gap = '12px';
                        countryContent.style.paddingTop = '8px';
                        countryContent.style.marginBottom = '8px';
                        
                        // Add POIs for this country
                        countryPois.forEach(function(poi){
                        try {
                            var item = document.createElement('div'); item.className = 'poi-card';
                            item.setAttribute('data-osm', poi.osm_id || '');
                            item.setAttribute('data-id', poi.id || '');  // Store API ID as well
                            item.setAttribute('data-lat', poi.latitude || '');
                            item.setAttribute('data-lon', poi.longitude || '');
                            if (poi.logo) {
                                var img = document.createElement('img'); img.className = 'poi-logo'; img.src = base + 'assets/icons/' + encodeURIComponent(poi.logo); img.alt = '';
                                img.onerror = function(){ try { this.style.display = 'none'; } catch(e){} };
                                item.appendChild(img);
                            }
                            var name = document.createElement('div'); name.className = 'poi-name';
                            try {
                                var nameLink = document.createElement('a');
                                nameLink.href = base + 'index.php/locations/view?id=' + encodeURIComponent(poi.id || '');
                                nameLink.target = '_blank'; nameLink.rel = 'noopener';
                                nameLink.textContent = poiDisplayName(poi);
                                nameLink.style.color = 'inherit'; nameLink.style.textDecoration = 'none';
                                name.appendChild(nameLink);
                            } catch (e) { name.textContent = poiDisplayName(poi); }
                            item.appendChild(name);
                            var meta = document.createElement('div'); meta.className = 'poi-meta';
                            var parts = [];
                            if (poi.type) parts.push(poi.type);
                            if (poi.city) parts.push(poi.city);
                            if (poi.country) parts.push(poi.country);
                            meta.textContent = parts.join(' · ');
                            item.appendChild(meta);
                            try {
                                var tags = (typeof poi.tags === 'string') ? parsePostgresHstore(poi.tags) : (poi.tags || {});
                                var phoneVal = poi.phone || tags.phone || tags['phone'] || '';
                                var websiteVal = poi.website || tags.website || tags['website'] || '';
                                var hoursVal = poi.opening_hours || tags.opening_hours || tags['opening_hours'] || '';
                                var streetVal = poi.addr_street || tags['addr:street'] || tags['addr_street'] || '';
                                var brandVal = poi.brand || poi.brand_wikidata || poi.brand_wikipedia || tags.brand || '';
                                var extra = document.createElement('div'); extra.className = 'poi-meta';
                                var extras = [];
                                if (phoneVal) extras.push('☎ ' + phoneVal);
                                if (websiteVal) extras.push('\u25A0 <a href="' + escapeHtml(websiteVal) + '" target="_blank" rel="noopener">Website</a>');
                                if (hoursVal) extras.push('Hours: ' + hoursVal);
                                if (streetVal) extras.push(escapeHtml(streetVal));
                                if (brandVal) extras.push(escapeHtml(brandVal));
                                if (extras.length) { extra.innerHTML = extras.join(' · '); item.appendChild(extra); }
                            } catch (e) {}
                            item.addEventListener('click', function(){
                                try {
                                    var osm = String(this.getAttribute('data-osm'));
                                    var lat = parseFloat(this.getAttribute('data-lat'));
                                    var lon = parseFloat(this.getAttribute('data-lon'));
                                    
                                    // Try OSM marker first
                                    if (osm) {
                                        var m = markerByOsm[osm];
                                        if (m && m.getLatLng) { 
                                            map.setView(m.getLatLng(), Math.max(map.getZoom(), 15)); 
                                            try { if (m.openPopup) m.openPopup(); } catch(e){} 
                                            return;
                                        }
                                    }
                                    
                                    // Fall back to latitude/longitude (for MySQL results)
                                    if (!isNaN(lat) && !isNaN(lon)) { 
                                        map.setView([lat, lon], 15); 
                                    }
                                    else if (poi.latitude && poi.longitude) { 
                                        map.setView([parseFloat(poi.latitude), parseFloat(poi.longitude)], 15); 
                                    }
                                } catch (e) { console.error('poi list click error', e); }
                            });
                            // Direkter Link zu Details wenn auf Card geklickt wird (aber nicht wenn auf Link geklickt wird)
                            item.addEventListener('click', function(e){
                                if (e.target.tagName !== 'A') {
                                    var detailsLink = this.querySelector('a[href*="/locations/view"]');
                                    if (detailsLink) {
                                        detailsLink.click();
                                    }
                                }
                            });
                            countryContent.appendChild(item);
                        } catch (e) {}
                        });  // End of countryPois.forEach
                        
                        // Add event listeners for country toggle
                        countryHeader.addEventListener('click', function(){
                            var isExpanded = countryWrapper.classList.contains('expanded');
                            if (isExpanded) {
                                countryWrapper.classList.remove('expanded');
                                countryContent.classList.add('collapsed');
                                countryContent.style.display = 'none';
                                countryToggle.textContent = '▶';
                            } else {
                                countryWrapper.classList.add('expanded');
                                countryContent.classList.remove('collapsed');
                                countryContent.style.display = 'grid';
                                countryToggle.textContent = '▼';
                            }
                        });
                        countryHeader.addEventListener('keydown', function(e){
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                countryHeader.click();
                            }
                        });
                        
                        countryWrapper.appendChild(countryContent);
                        inner.appendChild(countryWrapper);
                    });  // End of countryKeys.forEach
                    wrapper.appendChild(inner);
                    container.appendChild(wrapper);
                    // Add toggle handler for group header
                    header.addEventListener('click', function(){
                        try {
                            var grid = this.closest('.poi-group').querySelector('.poi-countries-container');
                            var isExpanded = this.classList.contains('expanded');
                            if (isExpanded) {
                                this.classList.remove('expanded');
                                grid.classList.add('collapsed');
                                this.querySelector('.poi-group-toggle').textContent = '▶';
                            } else {
                                this.classList.add('expanded');
                                grid.classList.remove('collapsed');
                                this.querySelector('.poi-group-toggle').textContent = '▼';
                            }
                        } catch (e) {}
                    });
                    // Keyboard support for group header
                    header.addEventListener('keydown', function(e){
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            this.click();
                        }
                    });
                } catch (e) {}
            });
        } catch (e) { console.error('renderPoiList error', e); }
    }

    // --- Collapsible groups for the POI list (legacy - now handled in renderPoiList) ---
    function _attachCollapseHandlers() {
        // New collapse logic is now handled directly in renderPoiList with event listeners
        // This function is kept for backward compatibility but does nothing
    }


        function toggleSelection(marker) {
            try {
                // Support markers that represent one or multiple osm ids (aggregation)
                var ids = [];
                if (marker && marker.options) {
                    if (marker.options.osm_ids && Array.isArray(marker.options.osm_ids)) ids = marker.options.osm_ids.map(String);
                    else if (marker.options.osm_id) ids = [String(marker.options.osm_id)];
                }
                if (!ids.length) return;
                ids.forEach(function(id){ markerByOsm[id] = marker; });
                // Toggle: if first id is selected, unselect all, otherwise select all
                var first = ids[0];
                if (selectedOsm.has(first)) {
                    ids.forEach(function(id){ selectedOsm.delete(id); });
                    setMarkerSelected(marker, false);
                } else {
                    ids.forEach(function(id){ selectedOsm.add(id); });
                    setMarkerSelected(marker, true);
                }
                updateSelectedUI();
            } catch (e) { console.error(e); }
        }

        // Mark or unmark a marker's selected visual state
        function setMarkerSelected(marker, isSelected) {
            try {
                if (!marker) return;
                marker._selected = !!isSelected;
                try {
                    var el = marker._icon || (marker.getElement && marker.getElement && marker.getElement());
                    if (el) {
                        if (marker._selected) el.classList.add('poi-selected');
                        else el.classList.remove('poi-selected');
                    }
                } catch (e) {}
            } catch (e) { /* ignore */ }
        }

        // Sync selected markers state into the UI (buttons, classes)
        function updateSelectedUI() {
            try {
                // apply selection class to known markers
                try {
                    Object.keys(markerByOsm).forEach(function(osm){
                        try {
                            var m = markerByOsm[osm];
                            if (!m) return;
                            setMarkerSelected(m, selectedOsm.has(osm));
                        } catch (e) {}
                    });
                } catch (e) {}

                // update import button state if present
                try {
                    var importBtn = document.getElementById('import-selected-pois');
                    if (importBtn) {
                        var cnt = (Array.from ? Array.from(selectedOsm).length : 0);
                        importBtn.disabled = (cnt === 0);
                        importBtn.textContent = cnt ? ('Import Selected (' + cnt + ')') : 'Import Selected';
                    }
                } catch (e) {}
            } catch (e) { console.error('updateSelectedUI error', e); }
        }

        // Add a simple map control to import all visible POIs
        var ImportControl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function (map) {
                var container = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                var a = L.DomUtil.create('a', '', container);
                a.href = '#';
                a.title = 'Import visible POIs into app database';
                a.innerHTML = '&#8682;'; // import-ish glyph
                L.DomEvent.on(a, 'click', L.DomEvent.stopPropagation)
                          .on(a, 'click', L.DomEvent.preventDefault)
                          .on(a, 'click', function () { doBatchImportVisible(); });
                return container;
            }
        });
        map.addControl(new ImportControl());

        function setStatus(msg, isError, loading) {
            var s = document.querySelector('.pois-status');
            var mapEl = document.querySelector('#pois-map');
            if (!s) {
                s = document.createElement('div');
                s.className = 'pois-status';
                if (mapEl) mapEl.appendChild(s);
                else document.body.insertBefore(s, document.body.firstChild);
            }
            s.textContent = msg || '';
            s.classList.toggle('loading', !!loading);
            s.style.color = isError ? '#a00' : '';
            s.style.display = msg ? '' : 'none';
            try {
                var spinner = document.getElementById('pois-loading-spinner');
                if (spinner) spinner.style.display = loading ? '' : 'none';
            } catch (e) {}
        }

        // --- Client-side POI category filtering helpers ---
        // Prefer a centralized `window.parsePostgresHstore` (provided by `utils.js`).
        var parsePostgresHstore = (window.parsePostgresHstore && typeof window.parsePostgresHstore === 'function') ?
            window.parsePostgresHstore :
            function(hstr) {
                if (!hstr) return {};
                var obj = {};
                try {
                    var hstoreRegex = /"([^\"]+)"\s*=>\s*"([^\"]*)"/g;
                    var match;
                    while ((match = hstoreRegex.exec(hstr)) !== null) {
                        obj[match[1]] = match[2];
                    }
                } catch (e) {}
                return obj;
            };

        // Use canonical predicate/color/icon definitions from PoiMapManager when available.
        // This removes duplicate in-file implementations and centralizes category logic.
        var CATEGORY_PREDICATES = (window.PoiMapManager && PoiMapManager.CATEGORY_PREDICATES) ? PoiMapManager.CATEGORY_PREDICATES : {};
        var CATEGORY_COLORS = (window.PoiMapManager && PoiMapManager.CATEGORY_COLORS) ? PoiMapManager.CATEGORY_COLORS : {};
        var CATEGORY_ICONS = (window.PoiMapManager && PoiMapManager.CATEGORY_ICONS) ? PoiMapManager.CATEGORY_ICONS : {};

        // Heuristic: decide whether a POI already has amenity-like information
        // Returns true when we consider the POI as known and therefore should NOT prompt
        function hasAmenityOrHeuristic(poi) {
            try {
                var tags = (typeof poi.tags === 'string') ? parsePostgresHstore(poi.tags) : (poi.tags || {});
                var name = poi.name || '';
                // First, reuse existing CATEGORY_PREDICATES: if any predicate matches, treat as known
                try {
                    for (var k in CATEGORY_PREDICATES) {
                        if (!Object.prototype.hasOwnProperty.call(CATEGORY_PREDICATES, k)) continue;
                        try {
                            if (CATEGORY_PREDICATES[k](tags, name)) return true;
                        } catch (e) {}
                    }
                } catch (e) {}
                // If the POI came from the app DB and already has a non-generic type, treat as known
                try { if (poi._source === 'app' && poi.type && String(poi.type).toLowerCase() !== 'poi') return true; } catch (e) {}
                // brand/operator heuristics as an extra fallback (gas, convenience, supermarkets)
                var brand = (tags.brand || tags.operator || '') + '';
                if (brand && /\b(shell|esso|chevron|husky|petro|petro[- ]?canada|mobil|gulf|sunoco|canco|co[- ]?op|7[- ]?eleven|circle[- ]k)\b/i.test(brand)) return true;
                if (brand && /\b(freshco|safeway|loblaws|sobeys|walmart|saveon|save[- ]on|superstore|real canadian superstore)\b/i.test(brand)) return true;
                // name-based heuristics as a final fallback
                if (name && /\b(hotel|motel|inn|lodge|guest[ _]?house|guesthouse|chalet|campground|camp site|camp|caravan|caravans|park|service station|gas station|petrol|fuel)\b/i.test(name)) return true;
            } catch (e) {}
            return false;
        }

            
        // icon cache for category divIcons
        var _iconCache = {};
        function ensureIconStyles() {
            try {
                if (document.getElementById('pois-js-icon-style')) return;
                var s = document.createElement('style'); s.id = 'pois-js-icon-style';
                s.textContent = '\n.poi-div-icon .poi-icon{ display:inline-block; width:18px; height:18px; border-radius:50%; box-shadow:0 1px 3px rgba(0,0,0,0.35); border:2px solid #fff; }\n' +
                                '.leaflet-marker-icon.poi-logo-icon{ width:32px; height:32px; max-width:64px; max-height:64px; }\n' +
                                '.leaflet-marker-icon.poi-logo-icon img{ width:32px; height:32px; }\n' +
                                '.poi-popup-img{ max-width:200px; max-height:200px; width:auto; height:auto; object-fit:cover; border-radius:4px; margin-right:8px; display:inline-block; vertical-align:middle; }\n' +
                                '\n/* Popup container and import button styles */\n' +
                                '.poi-popup-container{ font-family: system-ui,Segoe UI,Roboto,Arial,sans-serif; font-size:13px; color:#222; }\n' +
                                '.poi-popup-header{ margin-bottom:6px; }\n' +
                                '.poi-popup-header b{ display:block; font-size:1em; }\n' +
                                '.poi-popup-header small{ color:#666; font-weight:400; }\n' +
                                '.poi-popup-body{ display:flex; gap:8px; align-items:flex-start; }\n' +
                                '.poi-popup-body .poi-info{ flex:1; }\n' +
                                '.poi-popup-body .poi-field{ margin:3px 0; color:#333; }\n' +
                                '.poi-popup-footer{ margin-top:8px; text-align:right; }\n' +
                                '.poi-import-btn{ display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:4px; border:0; background:#2b8af3; color:#fff; cursor:pointer; }\n' +
                                '.poi-import-btn[disabled]{ opacity:0.6; cursor:default; }\n' +
                                '.poi-import-btn .spinner{ width:14px; height:14px; border:2px solid rgba(255,255,255,0.4); border-top-color:#fff; border-radius:50%; animation:spin 0.8s linear infinite; display:inline-block; }\n' +
                                '.poi-import-success{ background:#2ecc71; }\n' +
                                '.poi-import-btn.import-pulse{ box-shadow: 0 0 8px 3px rgba(43,138,243,0.55); transform: scale(1.03); animation: importPulse 1.2s ease; }\n' +
                                '@keyframes importPulse{ 0%{ transform: scale(1); } 50%{ transform: scale(1.06); } 100%{ transform: scale(1); } }\n';
                document.head.appendChild(s);
            } catch (e) {}
        }

        function makeCategoryIcon(cat) {
            ensureIconStyles();
            try {
                if (_iconCache[cat]) return _iconCache[cat];
                var color = CATEGORY_COLORS[cat] || '#2b8f2b';
                var html = '<span class="poi-icon" style="background:' + color + ';"></span>';
                var ic = L.divIcon({ className: 'poi-div-icon', html: html, iconSize: [18, 18], iconAnchor: [9, 18], popupAnchor: [0, -18] });
                _iconCache[cat] = ic;
                return ic;
            } catch (e) { return null; }
        }

        // Wrapper that prefers a centrally-provided `window.createSvgLetterIcon` helper.
        // Falls back to a minimal inline generator if the global helper is not present.
        var createSvgLetterIcon = function(letter, bg) {
            try {
                if (window.createSvgLetterIcon && typeof window.createSvgLetterIcon === 'function') {
                    return window.createSvgLetterIcon(letter, bg);
                }
            } catch (e) {}
            try {
                letter = (letter || '').toString().trim().substring(0,2).toUpperCase();
                bg = bg || '#2b8af3';
                var size = 64;
                var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '">'
                        + '<circle cx="' + (size/2) + '" cy="' + (size/2) + '" r="' + (size/2 - 2) + '" fill="' + bg + '" />'
                        + '<text x="50%" y="54%" font-family="Segoe UI,Roboto,Arial,sans-serif" font-size="28" fill="#ffffff" text-anchor="middle" alignment-baseline="middle">' + escapeHtml(letter) + '</text>'
                        + '</svg>';
                var url = 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
                if (typeof L !== 'undefined' && L && typeof L.icon === 'function') {
                    return L.icon({ iconUrl: url, iconSize: [32,32], iconAnchor: [16,32], popupAnchor: [0,-20], className: 'poi-logo-icon' });
                }
                return url;
            } catch (e) { return null; }
        };

        function getSelectedCategories() {
            var sel = [];
            // Prefer direct checkbox state if present
            try {
                var checks = document.querySelectorAll('input.poi-filter-checkbox:checked');
                if (checks && checks.length) {
                    checks.forEach(function(cb){ if (cb.value) sel.push(cb.value); });
                } else {
                    var buttons = document.querySelectorAll('.poi-filter-btn, .poi-filter-item');
                    buttons.forEach(function(btn){
                        if (btn.getAttribute && btn.getAttribute('aria-pressed') === 'true') {
                            var cat = btn.getAttribute('data-category');
                            if (cat) sel.push(cat);
                        } else {
                            // if it's a label with an input
                            try { var cb = btn.querySelector && btn.querySelector('input[type=checkbox]'); if (cb && cb.checked && cb.value) sel.push(cb.value); } catch(e){}
                        }
                    });
                }
            } catch (e) { /* ignore */ }
            // persist selection
            try { localStorage.setItem('poi_selected_categories', JSON.stringify(sel)); } catch (e) {}
            return sel;
        }
        // Expose globally so console and other code can call it
        window.getSelectedCategories = getSelectedCategories;

        function restoreSelectedCategories() {
            try {
                var raw = localStorage.getItem('poi_selected_categories');
                if (!raw) return;
                var arr = JSON.parse(raw);
                if (!Array.isArray(arr)) return;
                // set checkbox states first
                arr.forEach(function(cat){
                    try {
                        var cb = document.querySelector('input.poi-filter-checkbox[value="' + cat + '"]');
                        if (cb) { cb.checked = true; }
                        var btn = document.querySelector('.poi-filter-btn[data-category="' + cat + '"]');
                        if (btn) btn.setAttribute('aria-pressed', 'true');
                        var lbl = document.querySelector('.poi-filter-item[data-category="' + cat + '"]');
                        if (lbl) {
                            try { var lcb = lbl.querySelector('input[type=checkbox]'); if (lcb) lcb.checked = true; } catch(e){}
                        }
                    } catch(e){}
                });
            } catch (e) { /* ignore */ }
        }

        function poiMatchesCategories(poi, selected) {
            if (!selected || !selected.length) return true;
            var tags = {};
            if (typeof poi.tags === 'string') {
                tags = parsePostgresHstore(poi.tags);
            } else if (typeof poi.tags === 'object') {
                tags = poi.tags || {};
            }
            
            // If we have a pre-computed 'type' field from PostGIS API, use it to enhance tags
            if (poi.type) {
                var typeVal = String(poi.type).toLowerCase().trim();
                // Map the type back to OSM tag equivalents for predicate matching
                if (!tags.hotel && (typeVal.indexOf('hotel') !== -1)) tags.hotel = 'yes';
                if (!tags.tourism && (typeVal.indexOf('hotel') !== -1)) tags.tourism = 'hotel';
                if (!tags.shop && (typeVal.indexOf('cannabis') !== -1)) tags.shop = 'cannabis';
                if (!tags.shop && (typeVal.indexOf('pharmacy') !== -1)) tags.shop = 'pharmacy';
                if (!tags.amenity && (typeVal.indexOf('pharmacy') !== -1)) tags.amenity = 'pharmacy';
                if (!tags.amenity && (typeVal.indexOf('bank') !== -1)) tags.amenity = 'bank';
                if (!tags.amenity && (typeVal.indexOf('food') !== -1 || typeVal.indexOf('restaurant') !== -1)) tags.amenity = 'restaurant';
            }
            
            var name = poi.name || '';
            for (var i=0;i<selected.length;i++) {
                var cat = selected[i];
                var pred = CATEGORY_PREDICATES[cat];
                if (pred && pred(tags, name)) {
                    return true;
                }
            }
            return false;
        }

        // fuzzy name helpers: Levenshtein + normalized similarity
        var NAME_SIMILARITY_THRESHOLD = 0.82; // 0..1, higher => stricter
        function levenshtein(a, b) {
            if (a === b) return 0;
            var la = a.length, lb = b.length;
            if (la === 0) return lb;
            if (lb === 0) return la;
            var v0 = new Array(lb + 1), v1 = new Array(lb + 1);
            for (var j = 0; j <= lb; j++) v0[j] = j;
            for (var i = 0; i < la; i++) {
                v1[0] = i + 1;
                for (var j = 0; j < lb; j++) {
                    var cost = a.charAt(i) === b.charAt(j) ? 0 : 1;
                    v1[j + 1] = Math.min(v1[j] + 1, v0[j + 1] + 1, v0[j] + cost);
                }
                for (var j2 = 0; j2 <= lb; j2++) v0[j2] = v1[j2];
            }
            return v1[lb];
        }
        function nameSimilarity(a, b) {
            if (!a || !b) return 0;
            if (a === b) return 1;
            var d = levenshtein(a, b);
            var maxl = Math.max(a.length, b.length);
            if (maxl === 0) return 0;
            return 1 - (d / maxl);
        }
        function isSimilarName(a, b) {
            try { return nameSimilarity(a, b) >= NAME_SIMILARITY_THRESHOLD; } catch (e) { return a === b; }
        }


        function fetchAndPlot(opts) {
            opts = opts || {};
            var params = new URLSearchParams(window.location.search);
            var idParam = params.get('id');

            var base = (window.APP_BASE || '/');
            // ensure trailing slash
            if (base.charAt(base.length - 1) !== '/') base += '/';

            // If an id is provided in the page URL, fetch that single POI and highlight it
            if (idParam) {
                var url = base + 'api/locations?id=' + encodeURIComponent(idParam);
                setStatus((window.I18N && window.I18N.pois && window.I18N.pois.loading) || 'Loading POIs…', false, true);
                return fetch(url).then(function (res) {
                    if (!res.ok) throw new Error('Network response was not ok');
                    return res.json();
                }).then(function (json) {
                    clearPoiLayers();
                    var list = (json && Array.isArray(json.data)) ? json.data : [];
                    if (!list || list.length === 0) {
                        setStatus((window.I18N && window.I18N.pois && window.I18N.pois.none_found) || 'No POIs found.');
                        return;
                    }
                    setStatus('', false, false);
                    // create a highlighted icon (reuse default but can be customized)
                    var highlightIcon = L.icon({
                        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
                        iconSize: [25,41],
                        iconAnchor: [12,41],
                        popupAnchor: [1,-34],
                        className: 'highlight-poi'
                    });
                    list.forEach(function (poi) {
                        if (!poi.latitude || !poi.longitude) return;
                        var m = L.marker([parseFloat(poi.latitude), parseFloat(poi.longitude)], {icon: highlightIcon});
                        var popup = '<strong>' + escapeHtml(poi.name) + '</strong>';
                            if (poi.city) popup += '<br>' + escapeHtml(poi.city) + (poi.country ? ', ' + escapeHtml(poi.country) : '');
                            if (poi.type) popup += '<br><em>' + escapeHtml(poi.type) + '</em>';
                            if (poi.description) popup += '<p>' + escapeHtml(poi.description) + '</p>';
                            // show contact and hours (prefer top-level columns)
                            try {
                                var tags1 = (typeof poi.tags === 'string') ? parsePostgresHstore(poi.tags) : (poi.tags || {});
                                var phone1 = poi.phone || tags1.phone || '';
                                var website1 = poi.website || tags1.website || '';
                                var hours1 = poi.opening_hours || tags1.opening_hours || '';
                                var street1 = poi.addr_street || tags1['addr:street'] || '';
                                var brand1 = poi.brand || poi.brand_wikidata || poi.brand_wikipedia || tags1.brand || '';
                                if (phone1) popup += '<br>☎ ' + escapeHtml(phone1);
                                if (website1) popup += '<br><a href="' + escapeHtml(website1) + '" target="_blank" rel="noopener">Website</a>';
                                if (hours1) popup += '<br><span style="font-size:0.9em;color:#444">Hours: ' + escapeHtml(hours1) + '</span>';
                                if (street1) popup += '<br>' + escapeHtml(street1);
                                if (brand1) popup += '<br>' + escapeHtml(brand1);
                            } catch (e) {}
                        m.bindPopup(popup).openPopup();
                        markers.addLayer(m);
                        map.setView([parseFloat(poi.latitude), parseFloat(poi.longitude)], 16);
                        // Ensure the DOM icon receives a brief flash so users notice it
                        setTimeout(function () {
                            try {
                                if (m._icon) {
                                    m._icon.classList.add('flash-poi');
                                    // remove the class after animation completes so it can be retriggered later
                                    setTimeout(function () { try { m._icon.classList.remove('flash-poi'); } catch (e) {} }, 1200);
                                }
                            } catch (e) { /* ignore */ }
                        }, 50);
                    });
                }).catch(function (err) {
                    console.error('Error fetching POI by id', err);
                    setStatus((window.I18N && window.I18N.pois && window.I18N.pois.failed) || 'Failed to load POIs. See console for details.', true);
                }).finally(function () {
                    try { var lb2 = document.getElementById('load-pois-btn'); if (lb2) { lb2.disabled = false; lb2.textContent = hasLoadedOnce ? 'Reload POIs' : 'Load POIs'; } } catch (e) {}
                });
                return;
            }

            // hide the empty-result hint while loading
            try { var hint = document.getElementById('poi-empty-hint'); if (hint) hint.style.display = 'none'; } catch (e) {}

            var bounds = map.getBounds();
            // compute current query key and skip if identical to last query (unless forced)
            var typesNow = getSelectedCategories().join(',');
            var qNow = '';
            try { var si = document.getElementById('poi-search'); if (si) qNow = String(si.value || '').trim(); } catch(e) {}
            var queryKeyNow = [bounds.getSouth(), bounds.getWest(), bounds.getNorth(), bounds.getEast()].join(',') + '|' + typesNow + '|' + qNow;
            if (!opts.force && lastQueryKey === queryKeyNow) {
                // identical request already performed — skip
                return;
            }
            lastQueryKey = queryKeyNow;
            // The server-side search endpoint expects bbox as: lat1,lon1,lat2,lon2
            var bbox = [
                bounds.getSouth(), bounds.getWest(),
                bounds.getNorth(), bounds.getEast()
            ].join(',');

            // use 'limit' param (search.php expects 'limit'), and accept larger pages for maps
            // Use the application MySQL `locations` search endpoint so the map shows rows from the app DB
            var url = base + 'api/locations/search.php?bbox=' + encodeURIComponent(bbox) + '&limit=500';
            // If the user ticked the "Only my POIs" checkbox, include the `mine=1` flag
            try {
                var mineChk = document.getElementById('poi-only-mine');
                if (mineChk && mineChk.checked) {
                    url += '&mine=1';
                }
            } catch (e) {}
            // include free text search when provided
            try {
                var sInput = document.getElementById('poi-search');
                if (sInput) {
                    var qv = String(sInput.value || '').trim();
                    if (qv) url += '&q=' + encodeURIComponent(qv);
                }
            } catch (e) {}

            // show last request in debug panel and spinner
            try { if (typeof _updateDebugLastRequest === 'function') _updateDebugLastRequest(url); } catch (e) {}
            setStatus((window.I18N && window.I18N.pois && window.I18N.pois.loading) || 'Loading POIs…', false, true);

            // Build promises: always fetch app DB results; if campgrounds selected, also fetch PostGIS camp_site results
            var appPromise = fetch(url).then(function(res){ if (!res.ok) throw new Error('Network response was not ok'); return res.json(); });
            // also fetch the current user's MySQL POIs globally so they appear at all zoom levels
            var userPromise = fetch(base + 'api/locations/search.php?mine=1&limit=2000', { credentials: 'same-origin' }).then(function(res){ if (!res.ok) return {data:[]}; return res.json().catch(function(){ return {data:[]}; }); }).catch(function(){ return {data:[]}; });
            var postgisPromise = null;
            // Helper: fetch with timeout (rejects after timeout ms)
            function fetchWithTimeout(u, options, timeoutMs) {
                options = options || {};
                timeoutMs = (typeof timeoutMs === 'number' && timeoutMs > 0) ? timeoutMs : POIS_POSTGIS_TIMEOUT_MS;
                return new Promise(function(resolve, reject){
                    var aborted = false;
                    var timer = setTimeout(function(){ aborted = true; reject(new Error('timeout')); }, timeoutMs);
                    fetch(u, options).then(function(res){ if (aborted) return; clearTimeout(timer); resolve(res); }).catch(function(err){ if (aborted) return; clearTimeout(timer); reject(err); });
                });
            }
            try {
                // Dynamically determine which selected categories should trigger PostGIS queries.
                // Prefer `window.POI_FILTERS[cat]` (set server-side via viewPOIs.php). If not present, fall back
                // to reading the checkbox `data-tags` attribute which contains an array of tag patterns.
                var selected = (getSelectedCategories && typeof getSelectedCategories === 'function') ? getSelectedCategories() : [];
                var postgisTypes = [];
                function checkboxHasDataTags(cat) {
                    try {
                        var cb = document.querySelector('input.poi-filter-checkbox[value="' + cat + '"]');
                        if (!cb) return false;
                        if (cb.dataset && cb.dataset.tags) {
                            try { var a = JSON.parse(cb.dataset.tags); return Array.isArray(a) && a.length > 0; } catch(e) { return false; }
                        }
                    } catch(e) {}
                    return false;
                }
                selected.forEach(function(cat){
                    var include = false;
                    try {
                        if (window.POI_FILTERS && Object.prototype.hasOwnProperty.call(window.POI_FILTERS, cat)) {
                            var arr = window.POI_FILTERS[cat];
                            if (Array.isArray(arr) && arr.length) include = true;
                        }
                        if (!include && checkboxHasDataTags(cat)) include = true;
                    } catch(e) {}
                    if (include) postgisTypes.push(cat);
                });

                // Only query PostGIS when there are selected categories that map to OSM tag patterns.
                var zoom = (typeof map !== 'undefined' && map && typeof map.getZoom === 'function') ? map.getZoom() : null;
                var allowPostgis = (zoom === null) ? true : (zoom >= POIS_POSTGIS_MIN_ZOOM);
                var mineChk2 = null;
                try { mineChk2 = document.getElementById('poi-only-mine'); } catch (e) { mineChk2 = null; }
                var mineChecked = (mineChk2 && mineChk2.checked);

                var shouldQueryPostgis = !mineChecked && !opts.ignoreTypes && allowPostgis;
                // If no specific postgisTypes selected, query ALL available categories
                var queryTypes = (postgisTypes.length > 0) ? postgisTypes : ['campgrounds','provincial_parks','hotels','restaurants','shopping','supermarket','banks','food','fuel','dump_station','cannabis','attractions','fitness','laundry','parking','pharmacy','tobacco_vape','tourist_info','transportation','gas_stations'];
                if (shouldQueryPostgis) {
                    var postgisUrl = base + 'api/locations/search_overpass_v2.php?bbox=' + encodeURIComponent(bbox) + '&types=' + encodeURIComponent(queryTypes.join(',')) + '&limit=200';
                    poiDebug('PostGIS query: types=' + queryTypes.join(',') + ', zoom=' + zoom);
                    try {
                        var sInput2 = document.getElementById('poi-search');
                        if (sInput2) {
                            var qv2 = String(sInput2.value || '').trim();
                            if (qv2) { postgisUrl += '&q=' + encodeURIComponent(qv2); }
                        }
                    } catch (e) {}
                    // Use configurable timeout for PostGIS requests; gracefully degrade to app-only results on timeout/error.
                    postgisPromise = fetchWithTimeout(postgisUrl, {credentials:'same-origin'}, POIS_POSTGIS_TIMEOUT_MS)
                        .then(function(r){ 
                            if (!r || !r.ok) {
                                console.warn('PostGIS HTTP error:', r ? r.status : 'no response');
                                return null;
                            }
                            return r.json().catch(function(jsonErr){ console.warn('PostGIS JSON parse failed:', jsonErr); return null; });
                        })
                        .catch(function(fetchErr){
                            console.warn('PostGIS fetch failed:', fetchErr.message || fetchErr, 'timeout_ms:', POIS_POSTGIS_TIMEOUT_MS);
                            return null; // graceful fallback: return null so Promise.all continues with app-only results
                        });
                } else if (!allowPostgis && postgisTypes.length > 0) {
                    // skip heavy PostGIS fetch when zoom is low; the app DB will still show any imported rows
                    console.warn('Skipping PostGIS query because zoom', zoom, 'is below threshold', POIS_POSTGIS_MIN_ZOOM);
                }
            } catch (postgisErr) {
                console.warn('PostGIS setup error:', postgisErr);
                postgisPromise = null;
            }

            var _promises = [appPromise];
            if (postgisPromise) _promises.push(postgisPromise);
            _promises.push(userPromise);
            return Promise.all(_promises).then(function(results){
                var json = results[0] || {data:[]};
                var postJson = (postgisPromise ? (results[1] || {data:[]}) : null);
                var userJson = results[results.length - 1] || {data:[]};
                // clear existing markers and any auxiliary PostGIS/debug layers so new filters replace them cleanly
                clearPoiLayers();
                // standardized response shape from app DB: { page, per_page, data }
                var list = (json && Array.isArray(json.data)) ? json.data : [];
                // mark returned rows as coming from the app DB
                list.forEach(function(p){ if (!p._source) p._source = 'app'; });

                // (debug instrumentation removed)
                // merge postgis camp_site results when present, but always prefer app DB rows when duplicates (same osm_id) exist
                // Track if we have PostGIS data for immediate visibility layer creation
                var hasPostgisData = postJson && Array.isArray(postJson.data) && postJson.data.length > 0;
                if (postJson && Array.isArray(postJson.data)) {
                    poiDebug('DEBUG: postgis data received, count:', postJson.data.length);
                    postJson.data.forEach(function(pi){
                        if (!pi._source) pi._source = 'postgis';
                        // Assign stable fallback osm_id for PostGIS items without one (use name+lat+lon hash)
                        if (!pi.osm_id && pi.name && typeof pi.latitude !== 'undefined' && typeof pi.longitude !== 'undefined') {
                            try {
                                var hashStr = String(pi.name) + String(pi.latitude) + String(pi.longitude);
                                var hash = 0;
                                for (var ii=0; ii<hashStr.length; ii++) { hash=((hash<<5)-hash)+hashStr.charCodeAt(ii); hash=hash&hash; }
                                pi.osm_id = 'postgis_' + Math.abs(hash);
                            } catch(e) { pi.osm_id = 'postgis_' + Math.random().toString(36).substr(2,9); }
                        }
                    });
                    list = list.concat(postJson.data || []);
                    poiDebug('DEBUG: list after concat, count:', list.length);
                    try {
                        var byOsm = Object.create(null);
                        var noOsm = [];
                        list.forEach(function(it){
                            var k = (typeof it.osm_id !== 'undefined' && it.osm_id !== null && it.osm_id !== '') ? String(it.osm_id) : null;
                            if (!k) { noOsm.push(it); return; }
                            // If we've not seen this osm_id, store it. If seen, prefer the one from app DB (_source === 'app')
                            if (!byOsm[k]) byOsm[k] = it;
                            else {
                                try {
                                    var existing = byOsm[k];
                                    if (existing._source === 'postgis' && it._source === 'app') {
                                        byOsm[k] = it; // prefer app row
                                    }
                                    // otherwise keep existing (app preferred or both postgis)
                                } catch (e) {}
                            }
                        });
                        // rebuild list: app/postgis merged unique by osm_id, then append items without osm_id
                        list = Object.keys(byOsm).map(function(k){ return byOsm[k]; }).concat(noOsm);
                        poiDebug('DEBUG: list after dedupe, count:', list.length, 'with_osm:', Object.keys(byOsm).length, 'without_osm:', noOsm.length);

                        // Merge user (mysql) POIs and ensure user's POIs are authoritative and included globally
                        try {
                            if (userJson && Array.isArray(userJson.data) && userJson.data.length) {
                                userJson.data.forEach(function(u){ try { if (!u.source) u.source = 'mysql'; list.push(u); } catch(e){} });
                            }
                            // Deduplicate by best key: prefer app/user rows over postgis; key by id -> osm_id -> lat|lon|name
                            var byKey = Object.create(null);
                            list.forEach(function(it){
                                try {
                                    var key = null;
                                    if (typeof it.id !== 'undefined' && it.id !== null && it.id !== '') key = 'id:' + String(it.id);
                                    else if (typeof it.osm_id !== 'undefined' && it.osm_id !== null && it.osm_id !== '') key = 'osm:' + String(it.osm_id);
                                    else key = 'loc:' + String(it.latitude || '') + '|' + String(it.longitude || '') + '|' + String((it.name||'').toLowerCase());
                                    if (!byKey[key]) byKey[key] = it;
                                    else {
                                        var existing = byKey[key];
                                        var existingIsApp = (existing.source === 'mysql' || existing._source === 'app');
                                        var newIsApp = (it.source === 'mysql' || it._source === 'app');
                                        if (newIsApp && !existingIsApp) byKey[key] = it; // prefer user/app rows
                                    }
                                } catch(e) {}
                            });
                            list = Object.keys(byKey).map(function(k){ return byKey[k]; });
                        } catch(e) { console.warn('User merge/dedupe error', e); }
                    } catch (e) { console.warn('dedupe merge error', e); }
                }

                if (list.length === 0) {
                    setStatus((window.I18N && window.I18N.pois && window.I18N.pois.none_found) || 'No POIs found in the current view.');
                    try {
                        var hint = document.getElementById('poi-empty-hint');
                        if (hint) {
                            hint.style.display = '';
                            var btn = document.getElementById('show-all-pois');
                            if (btn) {
                                btn.onclick = function (ev) { ev.preventDefault(); fetchAndPlot({ignoreTypes: true, force: true}); };
                            }
                        }
                    } catch (e) {}
                    return;
                }
                setStatus('', false, false);

                // apply client-side category filters (restore happens once at init)
                var selectedCats = getSelectedCategories();
                // Apply client-side filters to the merged result list so checking e.g. 'banks'
                // only shows POIs that match the selected categories. If no category is
                // selected, show all results.
                var filtered = list.filter(function(poi){
                    try {
                        if (!selectedCats || !selectedCats.length) return true;
                        var matches = poiMatchesCategories(poi, selectedCats);
                        // Debug: log first 3 failures to understand the problem
                        if (!matches && selectedCats[0] === 'hotels' && window._debug_filter_failures < 3) {
                            try {
                                if (typeof window._debug_filter_failures === 'undefined') window._debug_filter_failures = 0;
                                var tags = typeof poi.tags === 'string' ? parsePostgresHstore(poi.tags) : (poi.tags || {});
                                poiDebug('DEBUG: Hotel not matched #' + window._debug_filter_failures + ':', {
                                    name: poi.name || 'NO_NAME',
                                    has_website: !!tags.website,
                                    has_phone: !!tags.phone,
                                    website_val: tags.website ? tags.website.substring(0, 50) : 'N/A',
                                    phone_val: tags.phone ? tags.phone.substring(0, 20) : 'N/A',
                                    brand: tags.brand || 'N/A',
                                    tags_keys: Object.keys(tags).slice(0, 10)
                                });
                                window._debug_filter_failures++;
                            } catch(e) {}
                        }
                        return matches;
                    } catch (e) { return true; }
                });
                poiDebug('DEBUG fetchAndPlot:', {total_list_count: list.length, selected_categories: selectedCats, filtered_count: filtered.length});

                // update per-category counts (based on full server result list)
                try {
                    var counts = {};
                    var cats = ['campgrounds','provincial_parks','restaurants','food','hotels','shopping','supermarket','gas_stations','banks','dump_station','attractions','fitness','laundry','parking','pharmacy','tobacco_vape','tourist_info','transportation','cannabis'];
                    cats.forEach(function(k){ counts[k]=0; });
                    list.forEach(function(poi){
                        cats.forEach(function(k){
                            try {
                                var tags = (typeof poi.tags === 'string') ? parsePostgresHstore(poi.tags) : (poi.tags||{});
                                // Enhance tags with pre-computed type if available (PostGIS)
                                if (poi.type) {
                                    var typeVal = String(poi.type).toLowerCase().trim();
                                    if (!tags.hotel && (typeVal.indexOf('hotel') !== -1)) tags.hotel = 'yes';
                                    if (!tags.tourism && (typeVal.indexOf('hotel') !== -1)) tags.tourism = 'hotel';
                                    if (!tags.shop && (typeVal.indexOf('cannabis') !== -1)) tags.shop = 'cannabis';
                                    if (!tags.shop && (typeVal.indexOf('pharmacy') !== -1)) tags.shop = 'pharmacy';
                                    if (!tags.amenity && (typeVal.indexOf('pharmacy') !== -1)) tags.amenity = 'pharmacy';
                                    if (!tags.amenity && (typeVal.indexOf('bank') !== -1)) tags.amenity = 'bank';
                                    if (!tags.amenity && (typeVal.indexOf('food') !== -1 || typeVal.indexOf('restaurant') !== -1)) tags.amenity = 'restaurant';
                                }
                                if (CATEGORY_PREDICATES[k](tags, poi.name||'')) counts[k]++;
                            } catch(e){}
                        });
                    });
                    document.querySelectorAll('.poi-count').forEach(function(el){
                        var c = el.getAttribute('data-cat');
                        if (c) {
                            var cnt = (typeof counts[c] !== 'undefined') ? counts[c] : 0;
                            el.textContent = cnt;
                            try {
                                el.className = 'poi-count poi-count-' + c;
                            } catch (e) {}
                        }
                    });
                    // (debug UI elements removed)
                } catch (e) { /* ignore count update errors */ }


                // Aggregate very close POIs by grid cell then fuzzy-cluster by name so we show one marker for near-duplicates
                try {
                    // aggregation debug counters
                    var agg_stats = { filtered_count: (filtered && filtered.length) ? filtered.length : 0, cells: 0, clusters: 0, markers_added: 0, sample_clusters: [], sample_markers: [] };
                    // Build per-cell lists and then cluster within each cell by normalized name similarity.
                    var cells = {};
                    var grid = 2222; // ~50 m grouping
                    var SIM_THRESHOLD = (typeof NAME_SIMILARITY_THRESHOLD !== 'undefined') ? NAME_SIMILARITY_THRESHOLD : 0.80;

                    function normalizeNameLocal(name) {
                        var n = String(name || '').toLowerCase();
                        n = n.replace(/[^a-z0-9\\u00C0-\\u017F]+/g, ' ');
                        n = n.replace(/\\b(branch|store|outlet|location|station|site|unit|the|a)\\b/g, ' ');
                        n = n.replace(/\\s+#?\\d+\\s*$/, '');
                        n = n.replace(/\\s+/g, ' ').trim();
                        return n;
                    }

                    // Populate cells
                    filtered.forEach(function(poi){
                        try {
                            // accept numeric-like latitude/longitude including '0' and strings; skip only when missing or not numeric
                            var latRaw = (typeof poi.latitude !== 'undefined') ? poi.latitude : null;
                            var lonRaw = (typeof poi.longitude !== 'undefined') ? poi.longitude : null;
                            if (latRaw === null || lonRaw === null) return;
                            var lat = parseFloat(latRaw);
                            var lon = parseFloat(lonRaw);
                            if (!isFinite(lat) || !isFinite(lon)) return;
                            var name = String(poiDisplayName(poi)).trim();
                            var norm = normalizeNameLocal(name);
                            var cellKey = (Math.round(lat * grid)/grid) + '|' + (Math.round(lon * grid)/grid);
                            if (!cells[cellKey]) cells[cellKey] = [];
                            cells[cellKey].push({ poi: poi, lat: lat, lon: lon, name: name, norm: norm });
                            try { if (poi && poi.osm_id) { poiByOsm[String(poi.osm_id)] = poi; } } catch (e) {}
                        } catch(e) {}
                    });

                    try { agg_stats.cells = Object.keys(cells).length; } catch (e) { agg_stats.cells = 0; }

                    // For each cell, cluster by fuzzy name similarity and emit markers
                    Object.keys(cells).forEach(function(cellKey){
                        try {
                            var list = cells[cellKey];
                            var clusters = [];
                            list.forEach(function(item){
                                try {
                                    var placed = false;
                                    for (var ci = 0; ci < clusters.length; ci++) {
                                        var c = clusters[ci];
                                        // use normalized names for comparison and the existing isSimilarName helper
                                        if (isSimilarName(c.norm || (c.name||'').toLowerCase(), item.norm)) {
                                            c.items.push(item.poi);
                                            c.lat = (c.lat * (c.items.length - 1) + item.lat) / c.items.length;
                                            c.lon = (c.lon * (c.items.length - 1) + item.lon) / c.items.length;
                                            placed = true;
                                            break;
                                        }
                                    }
                                    if (!placed) {
                                        clusters.push({ items: [item.poi], lat: item.lat, lon: item.lon, name: item.name, norm: item.norm });
                                    }
                                } catch(e) {}
                            });

                            try { agg_stats.clusters += clusters.length; } catch (e) {}

                            clusters.forEach(function(entry){
                                try {
                                    var entryCats = [];
                                    entry.items.forEach(function(poi){
                                        try {
                                            var tags = (typeof poi.tags === 'string') ? parsePostgresHstore(poi.tags) : (poi.tags||{});
                                            Object.keys(CATEGORY_PREDICATES).forEach(function(cat){ try { if (CATEGORY_PREDICATES[cat](tags, poi.name||'')) { if (entryCats.indexOf(cat) === -1) entryCats.push(cat); } } catch(e){} });
                                        } catch(e){}
                                    });
                                    var primaryCat = (entryCats.length ? entryCats[0] : null);
                                    // determine best logo candidate: prefer app row with existing logo file
                                    var logoForEntry = null;
                                    var logoMissingFlag = false;
                                    try {
                                        var logoObj = null;
                                        // 1) prefer app rows where logo_missing === false
                                        for (var ii = 0; ii < entry.items.length; ii++) {
                                            var p = entry.items[ii];
                                            if (p && p.logo && (p._source === 'app') && (p.logo_missing === false)) { logoObj = p; break; }
                                        }
                                        // 2) any row with logo_missing === false
                                        if (!logoObj) {
                                            for (var jj = 0; jj < entry.items.length; jj++) { var p2 = entry.items[jj]; if (p2 && p2.logo && p2.logo_missing === false) { logoObj = p2; break; } }
                                        }
                                        // 3) fallback to an app row that has a logo but file missing (we'll generate placeholder)
                                        if (!logoObj) {
                                            for (var kk = 0; kk < entry.items.length; kk++) { var p3 = entry.items[kk]; if (p3 && p3.logo && p3._source === 'app') { logoObj = p3; logoMissingFlag = !!p3.logo_missing; break; } }
                                        }
                                        // 4) final fallback: any logo value
                                        if (!logoObj) {
                                            for (var ll = 0; ll < entry.items.length; ll++) { var p4 = entry.items[ll]; if (p4 && p4.logo) { logoObj = p4; logoMissingFlag = !!p4.logo_missing; break; } }
                                        }
                                        if (logoObj) { logoForEntry = logoObj.logo; if (typeof logoObj.logo_missing !== 'undefined') logoMissingFlag = !!logoObj.logo_missing; }
                                    } catch(e) {}
                                    var icon = primaryCat ? makeCategoryIcon(primaryCat) : makeCategoryIcon('campgrounds');
                                    // If this cluster contains only PostGIS-sourced items (not app DB),
                                    // prefer to keep the category color when a primary category exists.
                                    try {
                                        var hasAnyApp = entry.items && entry.items.some(function(it){ return it && it._source === 'app'; });
                                        var hasAnyPostgis = entry.items && entry.items.some(function(it){ return it && it._source === 'postgis'; });
                                        if (hasAnyPostgis && !hasAnyApp) {
                                            if (primaryCat) {
                                                var catColor = CATEGORY_COLORS[primaryCat] || '#2b8f2b';
                                                var pfHtml = '<span class="poi-icon" style="background:' + catColor + ';width:24px;height:24px;display:inline-block;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.4);"></span>';
                                                icon = L.divIcon({ className: 'poi-div-icon postgis-fallback', html: pfHtml, iconSize: [24,24], iconAnchor: [12,24], popupAnchor: [0,-20] });
                                            } else {
                                                var pfHtml = '<span class="poi-icon" style="background:#ff6600;width:24px;height:24px;display:inline-block;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.4);"></span>';
                                                icon = L.divIcon({ className: 'poi-div-icon postgis-fallback', html: pfHtml, iconSize: [24,24], iconAnchor: [12,24], popupAnchor: [0,-20] });
                                            }
                                        }
                                    } catch(e) {
                                        var pfHtml = '<span class="poi-icon" style="background:#ff6600;width:24px;height:24px;display:inline-block;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.4);"></span>';
                                        icon = L.divIcon({ className: 'poi-div-icon postgis-fallback', html: pfHtml, iconSize: [24,24], iconAnchor: [12,24], popupAnchor: [0,-20] });
                                    }

                                    // capture a small sample of cluster data for debugging (limit to 10)
                                    try {
                                        if (agg_stats.sample_clusters.length < 10) {
                                            agg_stats.sample_clusters.push({ lat: entry.lat, lon: entry.lon, count: entry.items.length, name: entry.name || '', osm_ids: entry.items.map(function(i){ return i && i.osm_id; }).filter(Boolean).slice(0,3) });
                                        }
                                    } catch(e) {}

                                    // Create a standard Marker for clustering so popups and icon styling work reliably.
                                    // PostGIS-only clusters will still get a separate visible circle marker layer below.
                                    var m;
                                    try {
                                        m = L.marker([entry.lat, entry.lon], { icon: icon });
                                    } catch (e) {
                                        // Fallback to a simple marker icon if marker creation fails
                                        try { m = L.marker([entry.lat, entry.lon]); } catch (e2) { m = null; }
                                    }
                                    // If an application logo exists, try to load a server-generated marker thumbnail and swap the icon asynchronously.
                                    if (logoForEntry) {
                                        try {
                                            if (!logoMissingFlag) {
                                                var markerThumb = base + 'icons/thumbs/marker-' + encodeURIComponent(logoForEntry);
                                                var img = new Image();
                                                img.onload = function() {
                                                    try {
                                                        var ico = L.icon({ iconUrl: markerThumb, iconSize: [32,32], iconAnchor: [16,32], popupAnchor: [0,-20], className: 'poi-logo-icon' });
                                                        m.setIcon(ico);
                                                    } catch (e) {}
                                                };
                                                img.onerror = function() {
                                                    // fallback to original icon path if thumbnail missing
                                                    var orig = base + 'assets/icons/' + encodeURIComponent(logoForEntry);
                                                    var img2 = new Image();
                                                    img2.onload = function() { try { var ico2 = L.icon({ iconUrl: orig, iconSize: [32,32], iconAnchor: [16,32], popupAnchor: [0,-20], className: 'poi-logo-icon' }); m.setIcon(ico2); } catch(e) {} };
                                                    img2.onerror = function() { /* leave default category icon */ };
                                                    img2.src = orig;
                                                };
                                                img.src = markerThumb;
                                            } else {
                                                // Logo exists in DB but file missing — generate a visible SVG placeholder so users see a distinct marker
                                                try {
                                                    var sampleName = entry.name || (entry.items && entry.items[0] && (entry.items[0].name || entry.items[0].type)) || '';
                                                    var letter = (sampleName || '').trim().charAt(0) || 'P';
                                                    var color = (primaryCat && CATEGORY_COLORS[primaryCat]) ? CATEGORY_COLORS[primaryCat] : '#2b8af3';
                                                    var svgIcon = createSvgLetterIcon(letter, color);
                                                    if (svgIcon) m.setIcon(svgIcon);
                                                } catch(e) {}
                                            }
                                        } catch(e) {}
                                    }
                                    try { m.options = m.options || {}; m.options.osm_ids = entry.items.map(function(i){ return i.osm_id; }).filter(Boolean); } catch(e){}

                                    m.on('click', function(ev){
                                        try {
                                            var oev = ev && ev.originalEvent ? ev.originalEvent : {};
                                            if (oev.ctrlKey || oev.metaKey) {
                                                L.DomEvent.stopPropagation(ev);
                                                L.DomEvent.preventDefault(ev);
                                                toggleSelection(m);
                                                try { if (m.closePopup) m.closePopup(); } catch(e){}
                                            }
                                        } catch(e){}
                                    });

                                    // build popup
                                    var popup = '<div style="min-width:180px">';
                                    // Use entry name or if empty, use the first POI's display name
                                    var popupTitle = entry.name && String(entry.name).trim() ? entry.name : (entry.items.length > 0 ? poiDisplayName(entry.items[0]) : 'POI');
                                    popup += '<h4 style="margin:0 0 .25rem 0">' + escapeHtml(popupTitle) + '</h4>';
                                    if (entry.items.length > 1) popup += '<div style="font-size:0.9em;color:#444;margin-bottom:6px">' + String(entry.items.length) + ' nearby entries</div>';
                                    entry.items.forEach(function(poi){
                                        try {
                                            var tags = (typeof poi.tags === 'string') ? parsePostgresHstore(poi.tags) : (poi.tags||{});
                                            var cats = [];
                                            Object.keys(CATEGORY_PREDICATES).forEach(function(cat){ try { if (CATEGORY_PREDICATES[cat](tags, poi.name||'')) cats.push(cat); } catch(e){} });
                                            // show logo in popup with max dimensions 200x200
                                            var imgHtml = '';
                                            if (poi && poi.logo) {
                                                try {
                                                    // If server marked the logo as missing, render an inline SVG placeholder
                                                    if (poi.logo_missing) {
                                                        try {
                                                            var phLetter = (poi.name || poi.type || '').toString().trim().charAt(0) || 'P';
                                                            var phColor = (cats && cats.length && CATEGORY_COLORS[cats[0]]) ? CATEGORY_COLORS[cats[0]] : '#2b8af3';
                                                            var svg = createSvgLetterIcon(phLetter, phColor);
                                                            if (svg && svg.options && svg.options.iconUrl) {
                                                                imgHtml = '<img src="' + svg.options.iconUrl + '" class="poi-popup-img" />';
                                                            } else {
                                                                imgHtml = '';
                                                            }
                                                        } catch(e) { imgHtml = ''; }
                                                    } else {
                                                        // prefer server-generated popup thumbnail; fall back to original icon if thumb missing
                                                        var popupThumb = base + 'icons/thumbs/popup-' + encodeURIComponent(poi.logo);
                                                        var origIcon = base + 'assets/icons/' + encodeURIComponent(poi.logo);
                                                        imgHtml = '<img src="' + popupThumb + '" onerror="this.onerror=null;this.src=\'' + origIcon + '\'" class="poi-popup-img" />';
                                                    }
                                                } catch (e) { imgHtml = '<img src="' + base + 'assets/icons/' + escapeHtml(poi.logo) + '" class="poi-popup-img" />'; }
                                            }
                                            // Build a user-friendly popup card
                                            var card = '<div class="poi-popup-container">';
                                            card += '<div class="poi-popup-header"><b>' + escapeHtml(poiDisplayName(poi)) + '</b><small class="text-muted d-block">OSM: ' + escapeHtml((poi.osm_type ? (poi.osm_type + ' ') : '') + (poi.osm_id || '')) + '</small></div>';
                                            // body: image + info
                                            card += '<div class="poi-popup-body">';
                                            card += imgHtml || '';
                                            card += '<div class="poi-info">';
                                            if (poi.type) card += '<div class="poi-field"><strong>' + escapeHtml(poi.type) + '</strong></div>';
                                            // contact / hours / address
                                            var phoneVal = poi.phone || tags.phone || tags['phone'] || '';
                                            var websiteVal = poi.website || tags.website || tags['website'] || '';
                                            var hoursVal = poi.opening_hours || tags.opening_hours || tags['opening_hours'] || '';
                                            var streetVal = poi.addr_street || tags['addr:street'] || tags['addr_street'] || '';
                                            var housenum = poi.addr_housenumber || tags['addr:housenumber'] || tags['addr_housenumber'] || '';
                                            var postcode = poi.postcode || tags.postcode || tags['addr:postcode'] || '';
                                            var cityVal = poi.city || tags['addr:city'] || tags.city || '';
                                            var stateVal = poi.state || tags['addr:state'] || tags.state || '';
                                            var countryVal = poi.country || tags.country || '';
                                            var emailVal = poi.email || tags.email || tags['contact:email'] || tags['email'] || '';
                                            if (phoneVal) card += '<div class="poi-field">☎ <a href="tel:' + escapeHtml(phoneVal) + '">' + escapeHtml(phoneVal) + '</a></div>';
                                            if (emailVal) card += '<div class="poi-field">✉ <a href="mailto:' + escapeHtml(emailVal) + '">' + escapeHtml(emailVal) + '</a></div>';
                                            if (websiteVal) card += '<div class="poi-field">🔗 <a href="' + escapeHtml(websiteVal) + '" target="_blank" rel="noopener">Website</a></div>';
                                            if (hoursVal) card += '<div class="poi-field">⏱ ' + escapeHtml(hoursVal) + '</div>';
                                            var addrParts = [];
                                            if (housenum) addrParts.push(housenum);
                                            if (streetVal) addrParts.push(streetVal);
                                            var fullStreet = addrParts.join(' ');
                                            var locality = [];
                                            if (postcode) locality.push(postcode);
                                            if (cityVal) locality.push(cityVal);
                                            if (stateVal) locality.push(stateVal);
                                            var localityStr = locality.join(' ');
                                            if (fullStreet) card += '<div class="poi-field">' + escapeHtml(fullStreet) + '</div>';
                                            if (localityStr) card += '<div class="poi-field">' + escapeHtml(localityStr) + (countryVal ? (', ' + escapeHtml(countryVal)) : '') + '</div>';
                                            card += '</div></div>';
                                            // footer: import or view
                                            card += '<div class="poi-popup-footer">';
                                            if (poi._source === 'app') {
                                                var viewUrl = base + 'index.php/locations/view?id=' + encodeURIComponent(poi.id || poi.osm_id || '');
                                                card += '<a class="btn btn-sm" href="' + viewUrl + '" target="_blank" rel="noopener">Open</a>';
                                            } else {
                                                // show import button with data attributes
                                                card += '<button class="poi-import-btn" data-osm-id="' + escapeHtml(poi.osm_id || '') + '" data-osm-type="' + escapeHtml(poi.osm_type || '') + '"><span class="btn-label">Import</span></button>';
                                            }
                                            card += '</div></div>';
                                            popup += card;
                                        } catch(e){}
                                    });
                                    popup += '</div>';
                                    m.bindPopup(popup);
                                    m.on('popupopen', function () {
                                        try {
                                            // bind legacy import links and new import buttons
                                            var links = document.querySelectorAll('.import-poi[data-osm], .poi-import-btn[data-osm-id]');
                                            links.forEach(function(btn){
                                                try {
                                                    if (btn._bound) return; btn._bound = true;
                                                    if (btn.classList && btn.classList.contains('poi-import-btn')) {
                                                        btn.addEventListener('click', function(ev){
                                                            try { ev.preventDefault(); var osm = btn.getAttribute('data-osm-id'); if (!osm) osm = btn.getAttribute('data-osm'); btn.disabled = true; // show spinner
                                                                var sp = document.createElement('span'); sp.className = 'spinner'; btn.insertBefore(sp, btn.firstChild);
                                                                doImportPoi(osm, btn);
                                                            } catch(e) { console.error(e); }
                                                        });
                                                    } else {
                                                        btn.addEventListener('click', function(ev){ ev.preventDefault(); var osm = btn.getAttribute('data-osm'); doImportPoi(osm, btn); });
                                                    }
                                                } catch(e){}
                                            });
                                        } catch (e) { console.error(e); }
                                    });
                                    // capture a sample of added markers (limit to 20)
                                    try {
                                        if (agg_stats.sample_markers.length < 20) {
                                            agg_stats.sample_markers.push({ lat: entry.lat, lon: entry.lon, count: entry.items.length, osm_ids: entry.items.map(function(i){ return i && i.osm_id; }).filter(Boolean).slice(0,5) });
                                        }
                                    } catch(e) {}

                                    markers.addLayer(m);
                                    try { agg_stats.markers_added++; } catch (e) {}
                                    try {
                                        if (m && typeof m.setZIndexOffset === 'function') m.setZIndexOffset(1000);
                                        if (m && typeof m.bringToFront === 'function') m.bringToFront();
                                    } catch(e) {}
                                    
                                    // For PostGIS-only clusters, also add to a visible separate layer so they're guaranteed visible
                                    try {
                                        var hasAnyPostgis = entry.items && entry.items.length > 0 && entry.items.some(function(it){ try { return it && it._source === 'postgis'; } catch(e) { return false; } });
                                        var hasAnyApp = entry.items && entry.items.length > 0 && entry.items.some(function(it){ try { return it && it._source === 'app'; } catch(e) { return false; } });
                                        var isPostgisOnlyCluster = hasAnyPostgis && !hasAnyApp;
                                        // PostGIS-only cluster debug layer removed - markers now render normally via MarkerClusterGroup
                                    } catch(e) {}
                                    
                                    // map each osm_id to the created marker so the POI list can focus/open it
                                    try {
                                        if (m && m.options && m.options.osm_ids && Array.isArray(m.options.osm_ids)) {
                                            m.options.osm_ids.forEach(function(id){ try { markerByOsm[String(id)] = m; } catch(e){} });
                                        }
                                    } catch (e) {}

                                    try {
                                        // Add a persistent PostGIS overlay marker only for PostGIS-only clusters
                                        var hasPostgis = false, hasAnyApp = false, isPostgisOnlyCluster = false;
                                        try { hasPostgis = Array.isArray(entry.items) && entry.items.some(function(it){ return it && it._source === 'postgis'; }); } catch(e) { hasPostgis = false; }
                                        try { hasAnyApp = Array.isArray(entry.items) && entry.items.some(function(it){ return it && it._source === 'app'; }); } catch(e) { hasAnyApp = false; }
                                        try { isPostgisOnlyCluster = hasPostgis && !hasAnyApp; } catch(e) { isPostgisOnlyCluster = hasPostgis && !hasAnyApp; }
                                        if (isPostgisOnlyCluster) {
                                            try {
                                                if (!window._poi_postgis_layer) window._poi_postgis_layer = L.layerGroup();
                                                if (map && window._poi_postgis_layer && !map.hasLayer(window._poi_postgis_layer)) map.addLayer(window._poi_postgis_layer);
                                                    var dotColor = (primaryCat && CATEGORY_COLORS[primaryCat]) ? CATEGORY_COLORS[primaryCat] : '#ff6600';
                                                    var pdot = L.circleMarker([entry.lat, entry.lon], { radius:6, color: dotColor, fillColor: dotColor, fillOpacity:0.65, weight:1 });
                                                pdot.addTo(window._poi_postgis_layer);
                                                try { if (pdot && typeof pdot.bringToFront === 'function') pdot.bringToFront(); } catch(e) {}
                                            } catch(e) {}
                                        }
                                    } catch(e) {}
                                } catch(e) {}
                            });
                        } catch(e) {}
                    });
                    // (aggregation debug removed)

                    // FORCE: Ensure PostGIS visible layer is on the map if we have PostGIS data
                    if (hasPostgisData && agg_stats.markers_added > 0) {
                        try {
                            if (!window._poi_postgis_visible_layer) {
                                window._poi_postgis_visible_layer = L.layerGroup();
                            }
                            if (map && !map.hasLayer(window._poi_postgis_visible_layer)) {
                                map.addLayer(window._poi_postgis_visible_layer);
                            }
                        } catch(e) { try { console.error('Failed to ensure PostGIS layer visibility:', e); } catch(e2){} }
                    }

                    // If requested, render a temporary debug overlay of PostGIS clusters (bright red circles)
                    try {
                        if (window._poi_debug_show_postgis) {
                            try { if (window._poi_debug_layer && map && map.hasLayer && map.hasLayer(window._poi_debug_layer)) map.removeLayer(window._poi_debug_layer); } catch(e){}
                            window._poi_debug_layer = L.layerGroup();
                            (agg_stats.sample_clusters || []).forEach(function(sc){
                                try {
                                    var cm = L.circleMarker([sc.lat, sc.lon], { radius: 8, color: '#ff3333', fillColor: '#ff9999', fillOpacity: 0.95, weight: 1 });
                                    try { cm.bindPopup('debug cluster: ' + (sc.count||0)); } catch(e){}
                                    cm.addTo(window._poi_debug_layer);
                                } catch(e){}
                            });
                            try { if (window._poi_debug_layer && map && map.addLayer) map.addLayer(window._poi_debug_layer); } catch(e){}
                        }
                    } catch(e) {}
                } catch (e) {
                    console.error('Aggregation error', e);
                }
                
            }).catch(function (err) {
                console.error('Error fetching POIs', err);
                setStatus((window.I18N && window.I18N.pois && window.I18N.pois.failed) || 'Failed to load POIs. See console for details.', true);
            }).finally(function () {
                try { var lb3 = document.getElementById('load-pois-btn'); if (lb3) { lb3.disabled = false; lb3.textContent = hasLoadedOnce ? 'Reload POIs' : 'Load POIs'; } } catch (e) {}
            });
        }

        // Import single OSM feature into application DB via AJAX and request backfill
        function doImportPoi(osm, btn) {
            try {
                if (!osm) return;
                var el = btn || document.querySelector('.import-poi[data-osm="' + osm + '"]');
                if (el) el.disabled = true;
                var base = (window.APP_BASE || '/'); if (base.charAt(base.length-1) !== '/') base += '/';
                var url = base + 'index.php/locations/import';
                var body = 'osm_id=' + encodeURIComponent(String(osm)) + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '');
                setStatus('Importing OSM ' + osm + '…', false, true);
                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                }).then(function(res){ return res.json(); }).then(function(json){
                    if (!json || !json.ok) {
                        setStatus('Import failed: ' + (json && json.error ? json.error : 'Unknown error'), true, false);
                        if (el) el.disabled = false;
                        return;
                    }
                    setStatus('Imported (id=' + (json.id || '') + '). Backfill: ' + JSON.stringify(json.backfill || {}), false, false);
                    // Refresh POI list and markers to reflect new import
                    try { fetchAndPlot({reload:true}); } catch (e) { /* ignore */ }
                    try {
                        if (el) {
                            // show brief pulse on the import button and remove spinner
                            try { el.classList.add('import-pulse'); } catch(e){}
                            setTimeout(function(){ try{ el.classList.remove('import-pulse'); } catch(e){} }, 1400);
                            try { var sp2 = el.querySelector && el.querySelector('.spinner'); if (sp2 && sp2.parentNode) sp2.parentNode.removeChild(sp2); } catch(e){}
                        }
                    } catch(e){}
                }).catch(function(err){
                    console.error('Import request failed', err);
                    setStatus('Import request failed (see console)', true, false);
                    if (el) el.disabled = false;
                });
            } catch (e) { console.error(e); }
        }

        // Reset filters button - clear persisted selection and deactivate all buttons, then reload
        try {
            var resetBtn = document.getElementById('reset-poi-filters');
                if (resetBtn) resetBtn.addEventListener('click', function (ev) {
                ev.preventDefault();
                try { localStorage.removeItem('poi_selected_categories'); } catch (e) {}
                try {
                    var buttons = document.querySelectorAll('.poi-filter-btn, .poi-filter-item');
                    buttons.forEach(function(btn){ 
                        try {
                            if (btn.setAttribute) btn.setAttribute('aria-pressed', 'false');
                            var checkbox = btn.querySelector && btn.querySelector('input[type=checkbox]');
                            if (checkbox) checkbox.checked = false;
                        } catch(e){}
                    });
                } catch (e) {}
                try { updateSelectedUI(); } catch (e) {}
                    // force refetch ignoring types to show everything
                    hasLoadedOnce = true;
                    var lb = document.getElementById('load-pois-btn'); if (lb) { lb.disabled = true; lb.textContent = 'Loading…'; }
                    var p = fetchAndPlot({force:true, ignoreTypes:true});
                if (p && p.finally) p.finally(function () { try { if (lb) { lb.disabled = false; lb.textContent = 'Reload POIs'; } } catch (e) {} });
            });
        } catch (e) {}

        // debug helpers removed; no-op placeholder
        function _updateDebugLastRequest(u) { /* no-op */ }

        // Auto-apply filters when buttons are clicked: toggle active state and trigger fetch
        try {
            var filterButtons = document.querySelectorAll('.poi-filter-btn, .poi-filter-item');
            filterButtons.forEach(function(btn){
                try {
                    btn.addEventListener('click', function (ev) {
                        // allow native checkbox to toggle for labels; prevent default for buttons
                        try {
                            var isLabel = btn.classList && btn.classList.contains('poi-filter-item');
                            if (!isLabel) ev.preventDefault();
                            // toggle active state
                            var isCurrentlyActive = btn.getAttribute && btn.getAttribute('aria-pressed') === 'true';
                            if (btn.setAttribute) btn.setAttribute('aria-pressed', isCurrentlyActive ? 'false' : 'true');
                            // keep checkbox in sync
                            var checkbox = btn.querySelector && btn.querySelector('input[type=checkbox]');
                            if (checkbox) {
                                // if label, clicking will already toggle checkbox; ensure state consistency
                                if (isLabel) {
                                    // small timeout to read updated checked state
                                    setTimeout(function(){
                                        try { hasLoadedOnce = true; if (window._poiFilterDebounce) clearTimeout(window._poiFilterDebounce); window._poiFilterDebounce = setTimeout(function(){ try { fetchAndPlot({force:true}); } catch(e){} }, 120); } catch(e){}
                                    }, 10);
                                    return;
                                } else {
                                    checkbox.checked = !isCurrentlyActive;
                                }
                            }
                            hasLoadedOnce = true;
                            if (window._poiFilterDebounce) clearTimeout(window._poiFilterDebounce);
                            window._poiFilterDebounce = setTimeout(function(){ try { fetchAndPlot({force:true}); } catch(e){} }, 120);
                        } catch(e){}
                    });
                } catch (e) {}
            });
        } catch (e) {}


        function doImportPoi(osm_id, btn) {
            if (!osm_id) return;
            // If this POI appears ambiguous (no amenity/tourism/shop/etc), prompt admin first
            try {
                var p = poiByOsm[String(osm_id)];
                if (p) {
                    if (!hasAmenityOrHeuristic(p)) {
                        // show single-item prompt
                        showAmenityPrompt([{osm: String(osm_id), name: p.name || ('OSM ' + osm_id)}], function(res){
                            if (res === null) return; // cancelled
                            // if user provided a selection, call self with override
                            var key = Object.keys(res || {})[0];
                            var overrides = {};
                            if (key) overrides[key] = res[key];
                            doImportPoiWithOverrides(osm_id, btn, res);
                        });
                        return;
                    }
                }
            } catch (e) {}
            var base = (window.APP_BASE || '/');
            if (base.charAt(base.length - 1) !== '/') base += '/';
            var importUrl = base + 'api/locations/import_from_overpass_fast.php';
            try { poiDebug('doImportPoi()', { osm_id: osm_id, importUrl: importUrl, csrf: !!window.CSRF_TOKEN }); } catch (e) {}
            // disable button while working
            btn.textContent = 'Importing…'; btn.style.pointerEvents = 'none';
            // Skip admin lookup for performance (can be done via backfill job later)
            var body = 'osm_id=' + encodeURIComponent(osm_id) + '&skip_admin=1';
            try { if (window.CSRF_TOKEN) body += '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN); } catch (e) {}
            fetch(importUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    if (json && json.ok) {
                        // The API returns `results` array for each osm_id. Prefer explicit id if provided.
                        var res = (json.results && json.results.length) ? json.results[0] : null;
                        var newId = (json.id || (res && res.id) || '');
                        btn.textContent = newId ? ('Imported (id ' + newId + ')') : 'Imported';
                        try { if (btn) { btn.classList.add('import-pulse'); setTimeout(function(){ try{ btn.classList.remove('import-pulse'); } catch(e){} }, 1400); var sp = btn.querySelector && btn.querySelector('.spinner'); if (sp && sp.parentNode) sp.parentNode.removeChild(sp); } } catch(e){}
                        var statusMsg = 'Imported POI' + (newId ? (' id ' + newId) : '');
                        // If the API provided backfill information, show it for debugging
                        if (res && res.backfill) {
                            var bf = res.backfill;
                            statusMsg += ' — ' + (bf.city ? bf.city + ', ' : '') + (bf.state ? bf.state + ', ' : '') + (bf.country ? bf.country : '');
                        }
                        setStatus(statusMsg);
                        // Refresh map and flash the newly-imported marker when it appears
                        try {
                            fetchAndPlot({force:true}).then(function(){
                                try {
                                    var targetOsm = String(osm_id || osm || '');
                                    var m = markerByOsm[targetOsm] || null;
                                    if (!m && newId) {
                                        markers.getLayers().forEach(function(layer){ try { if (!m && layer && layer.options && layer.options.osm_ids && Array.isArray(layer.options.osm_ids) && layer.options.osm_ids.indexOf(targetOsm) !== -1) m = layer; } catch(e){} });
                                    }
                                    if (m) {
                                        try { map.setView(m.getLatLng(), Math.max(map.getZoom(), 15)); } catch(e){}
                                        try { if (m.openPopup) m.openPopup(); } catch(e){}
                                        try { if (m._icon) { m._icon.classList.add('flash-poi'); setTimeout(function(){ try{ m._icon.classList.remove('flash-poi'); } catch(e){} }, 1400); } } catch(e){}
                                    }
                                } catch(e){}
                            }).catch(function(){/* ignore */});
                        } catch (e) {}
                    } else {
                        btn.textContent = 'Import failed';
                        setStatus('Import failed: ' + (json && json.error ? json.error : 'unknown'), true);
                    }
                }).catch(function (err) {
                    console.error('Import error', err);
                    if (err && err.message && err.message.indexOf('Forbidden') !== -1) setStatus('Import forbidden (403). Check server permissions or CSRF token.', true);
                    else setStatus('Import error. See console.', true);
                    btn.textContent = 'Import error';
                }).finally(function () { try { btn.style.pointerEvents = ''; } catch (e) {} });
        }

        // helper to import a single POI with optional overrides mapping (from showAmenityPrompt)
        function doImportPoiWithOverrides(osm_id, btn, overrides) {
            if (!osm_id) return;
            var base = (window.APP_BASE || '/');
            if (base.charAt(base.length - 1) !== '/') base += '/';
            var importUrl = base + 'api/locations/import_from_overpass_fast.php';
            try { poiDebug('doImportPoi()', { osm_id: osm_id, importUrl: importUrl, csrf: !!window.CSRF_TOKEN, overrides: overrides }); } catch (e) {}
            // disable button while working
            if (btn) { btn.textContent = 'Importing…'; btn.style.pointerEvents = 'none'; }
            var body = 'osm_id=' + encodeURIComponent(osm_id) + '&skip_admin=1';
            try { if (window.CSRF_TOKEN) body += '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN); } catch (e) {}
            try { if (overrides && typeof overrides === 'object') body += '&overrides=' + encodeURIComponent(JSON.stringify(overrides)); } catch (e) {}
            fetch(importUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
                .then(function (res) { return res.text(); })
                .then(function (txt) {
                    try {
                        var json = JSON.parse(txt);
                        if (json && json.ok) {
                            if (btn) {
                                btn.textContent = 'Imported';
                                try { btn.classList.add('import-pulse'); setTimeout(function(){ try{ btn.classList.remove('import-pulse'); } catch(e){} }, 1400); var sp2 = btn.querySelector && btn.querySelector('.spinner'); if (sp2 && sp2.parentNode) sp2.parentNode.removeChild(sp2); } catch(e){}
                            }
                            setStatus('Imported POI id ' + (json.id||''));
                            try {
                                fetchAndPlot({force:true}).then(function(){ try { var m = markerByOsm[String(osm_id)]; if (m && m._icon) { m._icon.classList.add('flash-poi'); setTimeout(function(){ try{ m._icon.classList.remove('flash-poi'); } catch(e){} }, 1400); if (m.openPopup) m.openPopup(); } } catch(e){} });
                            } catch(e){}
                        } else { if (btn) btn.textContent = 'Import failed'; setStatus('Import failed', true); }
                    } catch (e) { if (btn) btn.textContent = 'Import error'; setStatus('Import error', true); }
                }).catch(function(err){ console.error('Import error', err); if (btn) btn.textContent = 'Import error'; setStatus('Import error', true); }).finally(function(){ try { if (btn) btn.style.pointerEvents = ''; } catch(e){} });
        }

        // Collect visible markers and POST a batch import request
        function doBatchImportVisible() {
            var visible = [];
            markers.getLayers().forEach(function (layer) {
                try {
                    if (!map.getBounds().contains(layer.getLatLng())) return;
                    // collect single or multiple osm ids from marker
                    if (layer.options && layer.options.osm_ids && Array.isArray(layer.options.osm_ids)) {
                        visible = visible.concat(layer.options.osm_ids);
                    } else if (layer.options && layer.options.osm_id) {
                        visible.push(layer.options.osm_id);
                    }
                } catch (e) { /* ignore non-marker layers */ }
            });
            visible = Array.from(new Set(visible));
            if (!visible.length) {
                setStatus('No visible POIs with osm_id to import. Zoom in or click markers to see details.');
                return;
            }

            // Detect ambiguous POIs (no amenity/tourism/shop/leisure/service/cuisine/fuel keys present)
            var ambiguous = [];
            try {
                visible.forEach(function(osm){
                    try {
                        var p = poiByOsm[String(osm)];
                        if (!p) return;
                        if (!hasAmenityOrHeuristic(p)) ambiguous.push({ osm: String(osm), name: p.name || ('OSM ' + osm) });
                    } catch (e) {}
                });
            } catch (e) { ambiguous = []; }

            var proceedWithOverrides = function(overrides) {
                if (!confirm('Import ' + visible.length + ' visible POIs into the application database?')) return;
                setStatus('Importing ' + visible.length + ' POIs…', false, true);
                var base = (window.APP_BASE || '/');
                if (base.charAt(base.length - 1) !== '/') base += '/';
                var importUrl = base + 'api/locations/import_from_overpass_fast.php';
                try { poiDebug('doBatchImportVisible()', { count: visible.length, importUrl: importUrl, csrf: !!window.CSRF_TOKEN, overrides: overrides }); } catch (e) {}
                var body = 'osm_ids=' + encodeURIComponent(JSON.stringify(visible)) + '&skip_admin=1';
                try { if (window.CSRF_TOKEN) body += '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN); } catch (e) {}
                try { if (overrides && typeof overrides === 'object') body += '&overrides=' + encodeURIComponent(JSON.stringify(overrides)); } catch (e) {}
                fetch(importUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
                    .then(function (res) {
                        try { poiDebug('batch import fetch response:', res.status, res.statusText); } catch (e) {}
                        if (res.status === 403) return res.text().then(function(txt){ poiDebug('batch import body (403):', txt); throw new Error('Forbidden (403): ' + (txt || '(no body)')); });
                        return res.text().then(function(txt){ try { var p = JSON.parse(txt); poiDebug('batch import json:', p); return p; } catch(e){ poiDebug('batch import raw:', txt); return { ok: false, raw: txt }; } });
                    })
                    .then(function (json) {
                        if (!json || !json.ok) {
                            setStatus('Batch import failed: ' + (json && json.error ? json.error : 'unknown'), true);
                            return;
                        }
                        var inserted = json.results.filter(function (r) { return r.ok && r.action === 'inserted'; }).length;
                        var updated = json.results.filter(function (r) { return r.ok && r.action === 'updated'; }).length;
                        var skipped = json.results.filter(function (r) { return r.ok && r.action === 'skipped'; }).length;
                        var failed = json.results.filter(function (r) { return !r.ok; }).length;
                        setStatus('Import complete — inserted: ' + inserted + ', updated: ' + updated + ', skipped: ' + skipped + ', failed: ' + failed);
                    }).catch(function (err) {
                        console.error('Batch import error', err);
                        if (err && err.message && err.message.indexOf('Forbidden') !== -1) setStatus('Batch import forbidden (403). Check server permissions or CSRF token.', true);
                        else setStatus('Batch import error. See console.', true);
                    }).finally(function () { try { fetchAndPlot({force:true}); } catch(e){} });
            };

            if (ambiguous.length) {
                try { showAmenityPrompt(ambiguous, function(result){ if (result === null) { setStatus('Import cancelled by user.'); return; } proceedWithOverrides(result); }); } catch (e) { proceedWithOverrides(null); }
                return;
            }

            // no ambiguous items -> proceed normally
            proceedWithOverrides(null);
        }

        // Debounce viewport changes (only trigger after initial load)
        var debounceTimeout = null;
        var hasLoadedOnce = false; // set to true after user triggers initial load or auto-load
        map.on('moveend', function () {
            clearTimeout(debounceTimeout);
            debounceTimeout = setTimeout(function () {
                if (hasLoadedOnce) fetchAndPlot();
            }, 300);
        });

        // wire the Apply button to re-run fetch with current filters
        try {
            var applyBtn = document.getElementById('apply-poi-filter');
            if (applyBtn) applyBtn.addEventListener('click', function (ev) { ev.preventDefault(); fetchAndPlot(); });
            var importSelBtn = document.getElementById('import-selected-pois');
            if (importSelBtn) importSelBtn.addEventListener('click', function (ev) { ev.preventDefault(); doImportSelected(); });
            // wire search input and button
            var searchInput = document.getElementById('poi-search');
            var searchBtn = document.getElementById('poi-search-btn');
            if (searchBtn) searchBtn.addEventListener('click', function (ev) { ev.preventDefault(); fetchAndPlot({force:true}); });
            if (searchInput) searchInput.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); fetchAndPlot({force:true}); } });
        } catch (e) { /* ignore */ }

        // Also update the full, bbox-agnostic list when the search control is used
        try {
            if (searchBtn) searchBtn.addEventListener('click', function (ev) { ev.preventDefault(); fetchAndRenderFullPoiList(); });
            if (searchInput) searchInput.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); fetchAndRenderFullPoiList(); } });
        } catch (e) {}

    // Initial load: manual by default to avoid automatic repeated searches.
    updateSelectedUI();
    // restore any previously persisted filter selections once on init
    try { restoreSelectedCategories(); updateSelectedUI(); } catch (e) {}

    // render small color swatches and icons inside each filter button
    function renderFilterSwatches() {
        try {
            var buttons = document.querySelectorAll('.poi-filter-btn');
            buttons.forEach(function(btn){
                try {
                    var cat = btn.getAttribute('data-category');
                    if (!cat) return;
                    // Prefer showing a small icon if available, otherwise fall back to the color swatch
                    var base = (window.APP_BASE || '/'); if (base.charAt(base.length - 1) !== '/') base += '/';
                    var iconFile = CATEGORY_ICONS && CATEGORY_ICONS[cat] ? CATEGORY_ICONS[cat] : null;
                    if (iconFile && !btn.querySelector('.filter-icon')) {
                        var img = document.createElement('img');
                        img.className = 'filter-icon';
                        img.style.width = '20px'; img.style.height = '20px'; img.style.marginRight = '4px'; img.style.verticalAlign = 'middle';
                        img.src = base + 'assets/icons/' + encodeURIComponent(iconFile);
                        // if image fails to load, remove it silently (allow fallback)
                        img.onerror = function(){ try { this.parentNode && this.parentNode.removeChild(this); } catch(e){} };
                        btn.insertBefore(img, btn.querySelector('.poi-count'));
                    }
                } catch (e) {}
            });
        } catch (e) {}
    }
    try { renderFilterSwatches(); } catch (e) {}

    // Fetch the full (bbox-agnostic) POI list from the app DB and render it under the map
    function fetchAndRenderFullPoiList(opts) {
        opts = opts || {};
        try {
            var base = (window.APP_BASE || '/'); if (base.charAt(base.length - 1) !== '/') base += '/';
            var url = base + 'api/locations/search.php?limit=1000';
            try {
                var mineFull = document.getElementById('poi-only-mine');
                if (mineFull && mineFull.checked) url += '&mine=1';
            } catch (e) {}
            // include free-text search when present
            try { var si = document.getElementById('poi-search'); if (si) { var qv = String(si.value || '').trim(); if (qv) url += '&q=' + encodeURIComponent(qv); } } catch (e) {}
            setStatus('Loading full POI list…', false, true);
            return fetch(url, { credentials: 'same-origin' }).then(function(res){ if (!res.ok) throw new Error('Network response was not ok'); return res.json(); }).then(function(json){
                try {
                    var list = (json && Array.isArray(json.data)) ? json.data : [];
                    // mark source
                    list.forEach(function(p){ if (!p._source) p._source = 'app'; });
                    renderPoiList(list);
                    try { if (typeof _attachCollapseHandlers === 'function') _attachCollapseHandlers(); } catch (e) {}
                    setStatus('', false, false);
                } catch (e) { console.error('fetchAndRenderFullPoiList render error', e); setStatus('Failed to render full POI list', true); }
            }).catch(function(err){ console.error('fetchAndRenderFullPoiList error', err); setStatus('Failed to load POI list', true); });
        } catch (e) { console.error('fetchAndRenderFullPoiList outer error', e); setStatus('Failed to load POI list', true); }
    }

        // Show a simple modal prompting admin to select an amenity/category for ambiguous POIs
        function showAmenityPrompt(items, callback) {
            // items: [{osm: '123', name: 'Foo'}, ...]
            try {
                if (!Array.isArray(items) || items.length === 0) { callback(null); return; }
                // create overlay
                var existing = document.getElementById('poi-amenity-modal');
                if (existing) existing.parentNode.removeChild(existing);
                var overlay = document.createElement('div'); overlay.id = 'poi-amenity-modal';
                overlay.style.position = 'fixed'; overlay.style.left = '0'; overlay.style.top = '0'; overlay.style.right = '0'; overlay.style.bottom = '0';
                overlay.style.background = 'rgba(0,0,0,0.45)'; overlay.style.zIndex = 10000; overlay.style.display = 'flex'; overlay.style.alignItems = 'center'; overlay.style.justifyContent = 'center';

                var box = document.createElement('div'); box.style.width = '680px'; box.style.maxHeight = '80%'; box.style.overflow = 'auto'; box.style.background = '#fff'; box.style.borderRadius = '6px'; box.style.padding = '16px'; box.style.boxShadow = '0 8px 30px rgba(0,0,0,0.4)';
                var title = document.createElement('h3'); title.textContent = 'Ambiguous POI types — please choose an amenity'; title.style.marginTop = '0'; box.appendChild(title);
                var help = document.createElement('div'); help.style.marginBottom = '8px'; help.style.color = '#333'; help.textContent = 'Some POIs did not include an amenity/tourism tag. Choose the best match below (you can apply a selection to all using the dropdown).'; box.appendChild(help);

                var bulkWrap = document.createElement('div'); bulkWrap.style.marginBottom = '8px';
                var bulkLabel = document.createElement('label'); bulkLabel.textContent = 'Apply to all: '; bulkLabel.style.marginRight = '8px';
                bulkWrap.appendChild(bulkLabel);
                var bulkSelect = document.createElement('select');
                // extend ambiguous-POI choices with Shopping and Banks
                var opts = [['','(no change)'], ['campgrounds','Campground'], ['provincial_parks','Park'], ['hotels','Hotel'], ['food','Food'], ['shopping','Shopping'], ['banks','Banks'], ['fuel','Gas Station'], ['dump_station','Dump Station']];
                opts.forEach(function(o){ var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; bulkSelect.appendChild(op); });
                bulkSelect.addEventListener('change', function(){ var v = bulkSelect.value; var sel = box.querySelectorAll('select.item-cat'); sel.forEach(function(s){ if (v === '') return; s.value = v; }); });
                bulkWrap.appendChild(bulkSelect);
                box.appendChild(bulkWrap);

                var list = document.createElement('div'); list.style.marginBottom = '12px';
                items.forEach(function(it){
                    var row = document.createElement('div'); row.style.display = 'flex'; row.style.alignItems = 'center'; row.style.marginBottom = '6px';
                    var name = document.createElement('div'); name.style.flex = '1'; name.style.marginRight = '8px'; name.textContent = (it.name || ('OSM ' + it.osm)); row.appendChild(name);
                    var sel = document.createElement('select'); sel.className = 'item-cat'; sel.style.minWidth = '160px';
                    opts.forEach(function(o){ var op = document.createElement('option'); op.value = o[0]; op.textContent = o[1]; sel.appendChild(op); });
                    // add a hidden data attribute for osm
                    sel.setAttribute('data-osm', it.osm);
                    row.appendChild(sel);
                    list.appendChild(row);
                });
                box.appendChild(list);

                var footer = document.createElement('div'); footer.style.textAlign = 'right';
                var cancelBtn = document.createElement('button'); cancelBtn.textContent = 'Cancel'; cancelBtn.style.marginRight = '8px';
                var okBtn = document.createElement('button'); okBtn.textContent = 'Continue'; okBtn.style.background = '#2b8af3'; okBtn.style.color = '#fff'; okBtn.style.border = 'none'; okBtn.style.padding = '6px 10px'; okBtn.style.borderRadius = '4px';
                footer.appendChild(cancelBtn); footer.appendChild(okBtn); box.appendChild(footer);

                cancelBtn.addEventListener('click', function(){ try { overlay.parentNode.removeChild(overlay); } catch(e){}; callback(null); });
                okBtn.addEventListener('click', function(){
                    var sel = box.querySelectorAll('select.item-cat');
                    var overrides = {};
                    sel.forEach(function(s){ try { var v = s.value; var osm = s.getAttribute('data-osm'); if (v && osm) overrides[osm] = v; } catch(e){} });
                    try { overlay.parentNode.removeChild(overlay); } catch(e){}
                    callback(overrides);
                });

                overlay.appendChild(box);
                document.body.appendChild(overlay);
            } catch (e) { console.error('showAmenityPrompt error', e); callback(null); }
        }

        // Auto-load MySQL locations on page start so app DB rows are visible immediately
        // If the newer ESM `PV_POI_MANAGER` is active, let it own auto-load and bindings.
        try {
            if (typeof window !== 'undefined' && window.PV_POI_MANAGER) {
                // ESM manager will handle auto-load
            } else {
                hasLoadedOnce = true;
                var lb = document.getElementById('load-pois-btn');
                if (lb) { lb.disabled = true; lb.textContent = 'Loading…'; }
                var p = fetchAndPlot({force:true});
                // Also fetch the full (bbox-agnostic) POI list for the grid below the map
                try { fetchAndRenderFullPoiList(); } catch (e) {}
                if (p && p.finally) {
                    p.finally(function () { try { if (lb) { lb.disabled = false; lb.textContent = 'Reload POIs'; } } catch (e) {} });
                } else {
                    if (lb) { lb.disabled = false; lb.textContent = 'Reload POIs'; }
                }
            }
        } catch (e) {
            try { var lb2 = document.getElementById('load-pois-btn'); if (lb2) { lb2.disabled = false; lb2.textContent = 'Reload POIs'; } } catch (e2) {}
        }

        // Wire load button and auto-load checkbox (persist preference)
        try {
            var loadBtn = document.getElementById('load-pois-btn');
            if (loadBtn) {
                loadBtn.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    try { loadBtn.disabled = true; loadBtn.textContent = 'Loading…'; } catch (e) {}
                    hasLoadedOnce = true;
                    var p2 = fetchAndPlot({force:true});
                    if (p2 && p2.finally) {
                        p2.finally(function () { try { loadBtn.disabled = false; loadBtn.textContent = 'Reload POIs'; } catch (e) {} });
                    } else {
                        setTimeout(function () { try { loadBtn.disabled = false; loadBtn.textContent = 'Reload POIs'; } catch (e) {} }, 800);
                    }
                    try { fetchAndRenderFullPoiList(); } catch (e) {}
                });
            }
            var autoChk = document.getElementById('poi-autoload');
            if (autoChk) {
                autoChk.addEventListener('change', function(){ try { localStorage.setItem('poi_autoload', autoChk.checked ? '1' : '0'); } catch(e){} });
            }
        } catch (e) { /* ignore wiring errors */ }

        // Import currently-selected markers (from selectedOsm set)
        function doImportSelected() {
            var selected = Array.from(selectedOsm);
            if (!selected.length) {
                alert('No markers selected. Hold Ctrl (or Cmd) and click markers to select them.');
                return;
            }
            if (!confirm('Import ' + selected.length + ' selected POIs into the application database?')) return;
            setStatus('Importing ' + selected.length + ' POIs…', false, true);
            var base = (window.APP_BASE || '/');
            if (base.charAt(base.length - 1) !== '/') base += '/';
            var importUrl = base + 'api/locations/import_from_overpass_fast.php';
            try { poiDebug('doImportSelected()', { count: selected.length, importUrl: importUrl, csrf: !!window.CSRF_TOKEN }); } catch (e) {}
            var body = 'osm_ids=' + encodeURIComponent(JSON.stringify(selected));
            if (window.CSRF_TOKEN) body += '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN);
            fetch(importUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
                .then(function (res) {
                    try { poiDebug('import selected fetch response:', res.status, res.statusText); } catch (e) {}
                    if (res.status === 403) return res.text().then(function(txt){ poiDebug('import selected body (403):', txt); throw new Error('Forbidden (403): ' + (txt || '(no body)')); });
                    return res.text().then(function(txt){ try { var p = JSON.parse(txt); poiDebug('import selected json:', p); return p; } catch(e){ poiDebug('import selected raw:', txt); return { ok: false, raw: txt }; } });
                })
                .then(function (json) {
                    if (!json || !json.ok) {
                        setStatus('Import selected failed: ' + (json && json.error ? json.error : 'unknown'), true);
                        return;
                    }
                    setStatus('Import selected complete.');
                    // clear selection UI
                    selected.forEach(function(id){ try { selectedOsm.delete(id); if (markerByOsm[id]) setMarkerSelected(markerByOsm[id], false); } catch(e){} });
                    updateSelectedUI();
                }).catch(function (err) {
                    console.error('Import selected error', err);
                    if (err && err.message && err.message.indexOf('Forbidden') !== -1) setStatus('Import selected forbidden (403). Check server permissions or CSRF token.', true);
                    else setStatus('Import selected error. See console.', true);
                }).finally(function () { try { fetchAndPlot({force:true}); } catch(e){} });
        }

        // Expose for debugging
        window._poisMap = { map: map, markers: markers };
    }

    var escapeHtml = (window.escapeHtml && typeof window.escapeHtml === 'function') ? window.escapeHtml : function(str) {
        // NOTE: This function is also defined in src/assets/js/utils.js
        // and src/helpers/utils.php for centralized maintenance.
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    // wait for DOM
    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('pois-map')) return;
        if (typeof L === 'undefined') {
            console.error('Leaflet is not loaded');
            return;
        }
        initMap();
    });
})();
