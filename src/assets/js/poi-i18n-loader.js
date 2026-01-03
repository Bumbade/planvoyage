/**
 * src/assets/js/poi-i18n-loader.js
 * Loads localized filter group titles for client-side renderer.
 * This data is fetched via JSON from poi-config.php API endpoint.
 */

(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        try {
            // Initialize I18N namespace if not already done
            window.I18N = window.I18N || {};

            // Fetch i18n data from the poi-config endpoint (which embeds translations)
            // This is already loaded via <script src="poi-config.php"> in the HTML,
            // so we ensure the filter_groups object exists.
            // If poi-config.php hasn't loaded yet, set defaults.
            if (!window.I18N.filter_groups) {
                window.I18N.filter_groups = {
                    'tourism': 'Tourismus',
                    'gastronomy': 'Gastronomie',
                    'mobility': 'Infrastruktur',
                    'services': 'Dienstleistungen',
                    'sport': 'Sport',
                    'specialty': 'Spezialhandel',
                };
                if (window.DEBUG) {
                    console.log('I18N filter_groups initialized with defaults (awaiting poi-config.php)');
                }
            } else {
                if (window.DEBUG) {
                    console.log('I18N filter_groups already set by poi-config.php');
                }
            }
        } catch (e) {
            if (window.DEBUG) console.warn('Could not initialize I18N filter groups', e);
        }
    });
})();
