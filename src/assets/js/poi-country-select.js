/**
 * src/assets/js/poi-country-select.js
 * Handles country selection, map centering, and POI loading.
 * Depends on: window.API_BASE, window.DEBUG, window.PV_POI_MANAGER
 */

(() => {
    'use strict';

    function populateCountries(sel, list) {
        try {
            list.sort((a, b) => a.name.localeCompare(b.name));
            for (const c of list) {
                const o = document.createElement('option');
                o.value = c.cca2 || c.cca3 || c.name || '';
                o.textContent = c.name || o.value;
                if (Array.isArray(c.latlng) && c.latlng.length >= 2) {
                    o.dataset.lat = String(c.latlng[0]);
                    o.dataset.lng = String(c.latlng[1]);
                }
                if (c.area !== undefined && c.area !== null) {
                    o.dataset.area = String(c.area);
                }
                sel.appendChild(o);
            }
        } catch (e) {
            console.warn('populateCountries failed', e);
        }
    }

    function estimateZoomFromArea(area) {
        if (!area || Number.isNaN(area)) return 5;
        if (area > 3000000) return 3;
        if (area > 1000000) return 4;
        if (area > 300000) return 5;
        if (area > 100000) return 6;
        if (area > 20000) return 7;
        if (area > 5000) return 8;
        return 9;
    }

    document.addEventListener('DOMContentLoaded', () => {
        const sel = document.getElementById('poi-country-select');
        if (!sel) return;

        // Load country list from backend API (cached, 1 hour TTL)
        // Note: window.API_BASE points to /api or /src/api depending on app config
        // For now, construct full path to src/api/countries.php
        const appBase = window.APP_BASE || '';
        const countriesUrl = appBase.replace(/\/src$/, '') + '/src/api/countries.php';
        if (window.DEBUG) console.log('Loading countries from: ' + countriesUrl);
        
        fetch(countriesUrl)
            .then(r => (r.ok ? r.json() : Promise.reject(r.status)))
            .then(js => populateCountries(sel, js || []))
            .catch(e => {
                console.warn('Could not load country list', e);
                // Fallback: minimal curated list when backend API fails
                const fallback = [
                    { name: 'Germany', cca2: 'DE', latlng: [51, 9], area: 357022 },
                    { name: 'United States', cca2: 'US', latlng: [38, -97], area: 9525067 },
                    { name: 'United Kingdom', cca2: 'GB', latlng: [54, -2], area: 244376 },
                    { name: 'France', cca2: 'FR', latlng: [46, 2], area: 643801 },
                    { name: 'Canada', cca2: 'CA', latlng: [60, -95], area: 9984670 },
                ];
                try {
                    populateCountries(sel, fallback);
                } catch (e2) {
                    console.warn('populateCountries fallback failed', e2);
                }
            });

        sel.addEventListener('change', async () => {
            const opt = sel.selectedOptions && sel.selectedOptions[0];
            if (!opt) return;
            const lat = opt.dataset.lat,
                lng = opt.dataset.lng;
            if (!lat || !lng) return;

            try {
                const area = opt.dataset && opt.dataset.area ? parseFloat(opt.dataset.area) : null;
                const z = estimateZoomFromArea(area);

                // Prefer the running manager instance exposed by poi-entry.js
                if (window.PV_POI_MANAGER && window.PV_POI_MANAGER.map) {
                    window.PV_POI_MANAGER.map.setView([parseFloat(lat), parseFloat(lng)], z, { animate: true });
                    try {
                        window.PV_POI_MANAGER.fetchAndPlot({ force: true });
                    } catch (e) {
                        if (window.DEBUG) console.warn('fetchAndPlot failed after country select', e);
                    }
                } else {
                    // If manager not ready yet, wait for it
                    document.addEventListener(
                        'PV_POI_MANAGER_READY',
                        () => {
                            try {
                                window.PV_POI_MANAGER.map.setView([parseFloat(lat), parseFloat(lng)], z, { animate: true });
                                window.PV_POI_MANAGER.fetchAndPlot({ force: true });
                            } catch (e) {
                                if (window.DEBUG) console.warn('Country select failed', e);
                            }
                        },
                        { once: true }
                    );
                }
            } catch (e) {
                console.warn('Country select handler failed', e);
            }
        });
    });
})();
