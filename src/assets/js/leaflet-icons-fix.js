/**
 * src/assets/js/leaflet-icons-fix.js
 * Ensures Leaflet uses CDN images if local images are missing.
 * Called early to prevent 404s.
 */

(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        try {
            if (typeof L === 'undefined' || !L || !L.Icon || !L.Icon.Default) return;

            const cdnPath = 'https://unpkg.com/leaflet@1.9.4/dist/images/';
            L.Icon.Default.mergeOptions({
                iconUrl: cdnPath + 'marker-icon.png',
                iconRetinaUrl: cdnPath + 'marker-icon-2x.png',
                shadowUrl: cdnPath + 'marker-shadow.png',
            });

            if (window.DEBUG) {
                console.log('Leaflet icons: using CDN images from', cdnPath);
            }
        } catch (e) {
            if (window.DEBUG) console.warn('Could not set Leaflet default icon URLs', e);
        }
    });
})();
