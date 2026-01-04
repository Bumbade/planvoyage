/* poi-tiles-only.js - Loads only PoiTiles without map functionality
   Used on pages that display My POIs list without a map (e.g., poi-list.php)
*/
import PoiTiles from './PoiTiles.js';

document.addEventListener('DOMContentLoaded', () => {
    try {
        if (window.DEBUG) console.log('poi-tiles-only: Initializing...');
        
        // Initialize My-POIs tiles if container present
        const el = document.getElementById('my-pois-tiles');
        if (el && window.CURRENT_USER_ID) {
            if (window.DEBUG) console.log('poi-tiles-only: Found my-pois-tiles container, initializing PoiTiles');
            const poiTiles = new PoiTiles('my-pois-tiles');
            poiTiles.init().catch(e => console.warn('PoiTiles init failed', e));
        } else {
            if (window.DEBUG) {
                if (!el) console.log('poi-tiles-only: No my-pois-tiles container found');
                if (!window.CURRENT_USER_ID) console.log('poi-tiles-only: No user logged in');
            }
        }
    } catch (e) {
        console.error('poi-tiles-only failed to initialise', e);
    }
});
