/**
 * src/assets/js/poi-globals.js
 * Exposes shared globals and configuration for POI features.
 * Loaded BEFORE poi-entry.js to ensure window.* variables exist.
 * 
 * Note: APP_BASE and API_BASE should be set in the HTML view BEFORE this script loads.
 * This script provides fallbacks and ensures POI-specific globals are initialized.
 */

(() => {
    'use strict';

    // Fallback APP_BASE if not set by view
    if (typeof window.APP_BASE === 'undefined' || !window.APP_BASE) {
        window.APP_BASE = '/src';
        console.warn('APP_BASE not set in view, using fallback: /src');
    }

    // Fallback API_BASE if not set by view
    if (typeof window.API_BASE === 'undefined' || !window.API_BASE) {
        window.API_BASE = window.APP_BASE.replace(/\/src$/, '') || '/';
        if (typeof window.DEBUG !== 'undefined' && window.DEBUG) {
            console.log('DEBUG: API_BASE auto-fallback set to: ' + window.API_BASE);
        }
    }

    // Debug flag (set by view PHP)
    if (typeof window.DEBUG === 'undefined') {
        window.DEBUG = false;
    }
    if (typeof window.POI_DEBUG === 'undefined') {
        window.POI_DEBUG = window.DEBUG;
    }
    if (window.POI_DEBUG) {
        console.log('POI_DEBUG enabled');
    }

    // Alias for console convenience
    if (typeof window.APP_DEBUG === 'undefined') {
        window.APP_DEBUG = window.DEBUG;
    }

    // Ensure ICONS_BASE is set for asset resolution
    if (typeof window.ICONS_BASE === 'undefined') {
        try {
            window.ICONS_BASE = (window.APP_BASE || '') + '/assets/icons/';
            if (window.DEBUG) console.log('DEBUG: window.ICONS_BASE set to: ' + window.ICONS_BASE);
        } catch (e) {
            if (window.DEBUG) console.warn('Could not set ICONS_BASE', e);
        }
    }
})();
