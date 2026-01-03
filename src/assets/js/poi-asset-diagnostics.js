/**
 * src/assets/js/poi-asset-diagnostics.js
 * Asset check diagnostics - only runs in DEBUG mode.
 * Verifies that key CSS/JS URLs are loaded and accessible.
 */

(() => {
    'use strict';

    if (!window.DEBUG) return; // asset diagnostics only in DEBUG

    // List of asset URLs to check (generated from PHP)
    // These are passed via data attributes or should be computed here.
    const getAssetUrls = () => {
        const base = window.APP_BASE || '';
        return [
            base + '/assets/vendor/leaflet/leaflet.css',
            base + '/assets/vendor/leaflet.markercluster/MarkerCluster.css',
            base + '/assets/vendor/leaflet.markercluster/MarkerCluster.Default.css',
            base + '/assets/vendor/leaflet/leaflet.js',
            base + '/assets/vendor/leaflet.markercluster/leaflet.markercluster.js',
            base + '/assets/js/PoiMapManager.js',
        ];
    };

    async function checkAsset(url) {
        try {
            const res = await fetch(url, { cache: 'no-store' });
            console.log('[ASSET CHECK]', url, res.status, res.ok ? 'OK' : 'NOT OK');
            return { url, status: res.status, ok: res.ok };
        } catch (e) {
            console.warn('[ASSET CHECK] fetch failed for', url, e && e.message ? e.message : e);
            return { url, error: String(e) };
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const urls = getAssetUrls();
        Promise.all(urls.map(u => checkAsset(u)))
            .then(results => {
                console.group('Asset check results');
                results.forEach(r => console.log(r));
                try {
                    console.group('Loaded styleSheets');
                    for (const ss of document.styleSheets) {
                        console.log(ss.href, ss.ownerNode && ss.ownerNode.tagName, ss.disabled ? 'disabled' : 'enabled');
                    }
                    console.groupEnd();
                } catch (e) {
                    if (window.DEBUG) console.warn('Could not enumerate document.styleSheets', e);
                }
                console.groupEnd();
            })
            .catch(e => console.warn('Asset checks failed', e));
    });
})();
