/* poi-entry.js - ESM bootstrapping for POI frontend
   - imports ESM-enabled PoiMapManager and PoiTiles
   - applies server-side POI_FILTERS (exposed as window.POI_FILTERS)
   - initializes instances and preserves backward compatibility by setting globals
*/
import PoiMapManager from './PoiMapManager.js';
import PoiTiles from './PoiTiles.js';
import initPoiFilters from './poi-filters.js';
import loadPoiSearch from './poi-search-loader.js';

function applyServerFilters() {
    try {
        if (typeof window !== 'undefined' && window.POI_FILTERS && Object.keys(window.POI_FILTERS).length) {
            const built = PoiMapManager._buildPredicatesFromMap(window.POI_FILTERS);
            // Merge built predicates with existing ones: preserve local predicate logic
            for (const cat of Object.keys(built)) {
                const serverPred = built[cat];
                const localPred = PoiMapManager.CATEGORY_PREDICATES[cat];
                if (typeof localPred === 'function') {
                    // Compose: local OR server
                    PoiMapManager.CATEGORY_PREDICATES[cat] = (tags, name, poiType) => {
                        try { if (localPred(tags, name, poiType)) return true; } catch (e) {}
                        try { return !!serverPred(tags, name, poiType); } catch (e) { return false; }
                    };
                } else {
                    PoiMapManager.CATEGORY_PREDICATES[cat] = serverPred;
                }
            }
            console.info('poi-entry: applied server POI_FILTERS (merged with local predicates)');
        }
    } catch (e) {
        console.warn('poi-entry: failed to apply server POI_FILTERS', e);
    }
}

const __pv_poi_manager = new PoiMapManager();
let __pv_poi_tiles = null;

document.addEventListener('DOMContentLoaded', () => {
    try {
        // Ensure legacy scripts can still access the class
        window.PoiMapManager = PoiMapManager;

        // Expose the running manager instance for other modules (tile clicks etc.)
        window.PV_POI_MANAGER = __pv_poi_manager;

        applyServerFilters();

        __pv_poi_manager.initMap();
        // Ensure search input/button are present before UI binding
        try { loadPoiSearch('#poi-filter'); } catch (e) {}
        __pv_poi_manager.bindUIEvents();
        // Render filters from server config and restore saved selections
        try { initPoiFilters(); } catch (e) { if (window.DEBUG) console.warn('initPoiFilters failed', e); }
        try { __pv_poi_manager.renderFilterSwatches(); } catch(e) {}
        // Ensure initial page load does not restore stale filter selections
        try {
            try { localStorage.removeItem('poi_selected_categories'); } catch (e) {}
            __pv_poi_manager.restoreSelectedCategories();
        } catch(e) {}

        // Initialize My-POIs tiles if container present
        const el = document.getElementById('my-pois-tiles');
        if (el) {
            __pv_poi_tiles = new PoiTiles('my-pois-tiles');
            __pv_poi_tiles.init().catch(e => console.warn('PoiTiles init failed', e));
        }

        // Always auto-load the user's MySQL POIs when logged in (checkbox removed)
        // Do not force `hasLoadedOnce` or trigger an immediate fetch here —
        // the map's `moveend` handler will trigger `fetchAndPlot` with the
        // correct bbox after initialisation, preventing duplicate requests.
        try {
            if (window.CURRENT_USER_ID) {
                __pv_poi_manager.fetchAndRenderFullPoiList();
            }
        } catch (e) {}

        // Also ensure that MySQL POIs are loaded on initial page visit so
        // users see application-stored POIs immediately without needing
        // to press the "Reset Filters" button.
        try {
            __pv_poi_manager.fetchAndPlot({ force: true, onlyMysql: true });
        } catch (e) {}
    } catch (e) {
        console.error('poi-entry failed to initialise', e);
    }
});
