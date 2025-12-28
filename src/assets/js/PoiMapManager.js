import { escapeHtml, createSvgLetterIcon, parsePostgresHstore } from './utils.js';

// Expose selected utilities to legacy non-module scripts that expect
// `window.escapeHtml` and `window.createSvgLetterIcon` to exist.
try {
    if (typeof window !== 'undefined') {
        window.escapeHtml = escapeHtml;
        window.createSvgLetterIcon = createSvgLetterIcon;
    }
} catch (e) {}

try {
    if (typeof window !== 'undefined') {
        window.parsePostgresHstore = parsePostgresHstore;
    }
} catch (e) {}

export default class PoiMapManager {
    constructor(options) {
        this.options = Object.assign({
            mapId: 'pois-map',
            // Default to whole-world view on first load
            mapCenter: [0.0, 0.0],
            mapZoom: 2,
            overpassMinZoom: 10,
            overpassTimeoutMs: 15000,
            poiAggGrid: 10000
        }, options || {});

        this.map = null;
        this.markers = null;
        this.markerByOsm = {};
        this.poiByOsm = {};
        this._filteredOutMarkers = {};
        this._iconCache = {};
        this.lastQueryKey = null;
        this.debounceTimeout = null;
        this.hasLoadedOnce = false;
        this.selectedOsm = new Set();
        this._initDone = false;
        // No heavy work in constructor: network and async logic live in `fetchAndPlot()`
        // which is called by the bootstrapping code once the map is initialised.
    }

    static CATEGORY_PREDICATES = {
        hotel: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && (tags.tourism === 'hotel' || tags.amenity === 'hotel')) return true;
                if (name && /\b(hotel|motel|inn|lodge|guest house|guesthouse)\b/i.test(name)) return true;
            } catch (e) {}
            return false;
        },
        attraction: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && tags.tourism && ['attraction','theme_park','museum','viewpoint','zoo'].includes(tags.tourism)) return true;
                if (tags && tags.waterway && tags.waterway === 'waterfall') return true;
                if (tags && tags.leisure && tags.leisure === 'nature_reserve') return true;
                if (name && /\b(attraction|museum|viewpoint|zoo|waterfall|theme\s*park)\b/i.test(name)) return true;
            } catch (e) {}
            return false;
        },
        tourist_info: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && (tags.tourism === 'information' || tags.amenity === 'information' || tags.amenity === 'tourist_information')) return true;
            } catch (e) {}
            return false;
        },
        food: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && (tags.amenity === 'restaurant' || tags.amenity === 'biergarten' || tags.amenity === 'fast_food' || tags.amenity === 'food_court' || tags.amenity === 'cafe')) return true;
                if (tags && tags.shop && tags.shop === 'bakery') return true;
            } catch (e) {}
            return false;
        },
        nightlife: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && (tags.amenity === 'bar' || tags.amenity === 'pub' || tags.amenity === 'nightclub')) return true;
            } catch (e) {}
            return false;
        },
        fuel: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && (tags.amenity === 'fuel' || tags.amenity === 'charging_station')) return true;
            } catch (e) {}
            return false;
        },
        parking: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && (tags.amenity === 'parking' || tags.amenity === 'parking_entrance' || tags.amenity === 'parking_space')) return true;
            } catch (e) {}
            return false;
        },
        bank: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && (tags.amenity === 'bank' || tags.amenity === 'atm')) return true;
            } catch (e) {}
            return false;
        },
        healthcare: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && (tags.amenity === 'hospital' || tags.amenity === 'pharmacy')) return true;
            } catch (e) {}
            return false;
        },
        fitness: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && tags.leisure && ['sports_hall','sports_centre','fitness_station','fitness_centre'].includes(tags.leisure)) return true;
                if (tags && tags.amenity && /gym|fitness|sports_center|sports_centre/i.test(tags.amenity)) return true;
            } catch (e) {}
            return false;
        },
        laundry: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && tags.shop && tags.shop === 'laundry') return true;
            } catch (e) {}
            return false;
        },
        supermarket: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && tags.shop && (tags.shop === 'supermarket' || tags.shop === 'mall' || tags.shop === 'department_store')) return true;
            } catch (e) {}
            return false;
        },
        tobacco: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && tags.shop) {
                    const s = String(tags.shop).toLowerCase();
                    if (s === 'tobacco' || s === 'e-cigarette' || s === 'ecigarette' || s === 'e_cigarette') return true;
                }
            } catch (e) {}
            return false;
        },
        cannabis: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && tags.shop && tags.shop === 'cannabis') return true;
            } catch (e) {}
            return false;
        },
        transport: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && (tags.public_transport === 'stop_position' || tags.public_transport === 'station')) return true;
                if (tags && tags.railway && tags.railway === 'station') return true;
            } catch (e) {}
            return false;
        },
        dump_station: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && tags.amenity && tags.amenity === 'sanitary_dump_station') return true;
            } catch (e) {}
            return false;
        },
        campgrounds: (tags, name) => {
            try {
                if (!tags && !name) return false;
                if (tags && (tags.tourism === 'caravan_site' || tags.tourism === 'camp_site')) return true;
            } catch (e) {}
            return false;
        }
    };

    static CATEGORY_COLORS = {
        campgrounds: '#2c8c2c',
        provincial_parks: '#2b8af3',
        shopping: '#2980b9',
        cafes: '#c66f00',
        fast_food: '#d35400',
        banks: '#27ae60',
        cannabis: '#5b8e23',
        hotels: '#8a2bcb',
        hotel: '#8a2bcb',
        attraction: '#f39c12',
        nightlife: '#f39c12',
        fuel: '#e74c3c',
        bank: '#27ae60',
        healthcare: '#27ae60',
        tobacco: '#34495e',
        transport: '#1abc9c',
        food: '#e67e22',
        restaurants: '#e67e22',
        gas_stations: '#e74c3c',
        dump_station: '#7f8c8d',
        attractions: '#f39c12',
        fitness: '#16a085',
        laundry: '#3498db',
        parking: '#95a5a6',
        pharmacy: '#27ae60',
        tobacco_vape: '#34495e',
        tourist_info: '#9b59b6',
        transportation: '#1abc9c',
        supermarket: '#f1c40f'
    };

    static CATEGORY_ICONS = {
        campgrounds: 'campground.png',
        provincial_parks: 'national_park.png',
        cafes: 'cafe.png',
        fast_food: 'fast_food.png',
        shopping: 'shopping.png',
        banks: 'bank.png',
        cannabis: 'Cannabis.png',
        hotels: 'hotel.png',
        hotel: 'hotel.png',
        attraction: 'Attractions.png',
        nightlife: 'Attractions.png',
        fuel: 'gas_station.png',
        bank: 'bank.png',
        healthcare: 'Pharmacy.png',
        tobacco: 'TabacoVape.png',
        transport: 'Transportation.png',
        food: 'food.png',
        restaurants: 'restaurant.png',
        gas_stations: 'gas_station.png',
        dump_station: 'dump_station.png',
        attractions: 'Attractions.png',
        fitness: 'Fitness.png',
        laundry: 'Laundry.png',
        parking: 'Parking.png',
        pharmacy: 'Pharmacy.png',
        tobacco_vape: 'TabacoVape.png',
        tourist_info: 'TouristInfo.png',
        transportation: 'Transportation.png',
        supermarket: 'supermarket.png'
    };

    // Return a canonical key for a POI to deduplicate across different source shapes.
    // Format: <TYPE>:<ID> where TYPE is one-letter (A=app/mysql, N=node, W=way, R=relation)
    static getCanonicalKey(poi) {
        try {
            if (!poi) return null;
            const id = (poi.osm_id ?? poi.id ?? poi._id ?? poi.location_id ?? null);
            if (!id && id !== 0) return null;
            let t = null;
            if (poi.osm_type && typeof poi.osm_type === 'string' && poi.osm_type.length) {
                t = String(poi.osm_type).charAt(0).toUpperCase();
            } else if (poi.geom_type && typeof poi.geom_type === 'string' && poi.geom_type.length) {
                t = String(poi.geom_type).charAt(0).toUpperCase();
            } else if (poi.source === 'mysql' || poi._is_app === true || (poi.user_id && Number(poi.user_id) > 0)) {
                t = 'A';
            } else {
                t = 'N';
            }
            return `${t}:${String(id)}`;
        } catch (e) { return null; }
    }

    // Return array of canonical variants useful for lookups: canonical, plain id, and prefixed no-colon form.
    static canonicalVariants(poi) {
        try {
            const arr = [];
            const canon = PoiMapManager.getCanonicalKey(poi);
            if (canon) arr.push(canon);
            const id = (poi.osm_id ?? poi.id ?? poi._id ?? poi.location_id ?? null);
            if (id !== null && id !== undefined) {
                const sid = String(id);
                arr.push(sid);
                // also include prefixed form without colon for backward compat
                let t = (poi.osm_type && String(poi.osm_type).length) ? String(poi.osm_type).charAt(0).toUpperCase() : (poi.source === 'mysql' ? 'A' : 'N');
                arr.push(`${t}${sid}`);
            }
            // ensure unique
            return Array.from(new Set(arr));
        } catch (e) { return []; }
    }

    static POI_PALETTE = ['#e74c3c','#2ecc71','#3498db','#9b59b6','#f1c40f','#e67e22','#1abc9c','#34495e','#27ae60','#8a2bcb','#95a5a6'];

    static POI_LETTER_MAP = {
        hotel: 'H',
        attraction: 'A',
        tourist_info: 'T',
        food: 'F',
        nightlife: 'N',
        fuel: 'G',
        parking: 'P',
        bank: 'B',
        healthcare: 'H',
        fitness: 'S',
        laundry: 'L',
        supermarket: 'S',
        tobacco: 'T',
        cannabis: 'C',
        transport: 'M',
        dump_station: 'D',
        campgrounds: 'C'
    };

    static _colorForOsm(osmId) {
        try {
            const s = String(osmId || '0');
            let h = 0;
            for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
            const idx = h % PoiMapManager.POI_PALETTE.length;
            return PoiMapManager.POI_PALETTE[idx];
        } catch (e) { return '#888'; }
    }

    // Build API candidate URLs from APP_BASE variants (with/without /src, index.php)
    static makeApiCandidates(relPath, queryString) {
        // Prefer server-calculated API base when available
        const raw = String(window.API_BASE || window.APP_BASE || '/');
        let b = raw || '/';
        if (b.charAt(b.length - 1) !== '/') b += '/';

        // Derive a root base without a trailing '/src' to avoid duplicating 'src' when
        // constructing candidates. Example: if API_BASE is '/app/src', rootBase becomes '/app/'.
        const rootBase = b.replace(/\/src\/?$/i, '/');
        const withTrailing = (base) => (base.charAt(base.length - 1) === '/' ? base : base + '/');

        // Preferred ordering: (1) root + src/index.php/, (2) root + index.php/, (3) root + src/, (4) root
        const orderedBases = [
            withTrailing(rootBase) + 'src/index.php/',
            withTrailing(rootBase) + 'index.php/',
            withTrailing(rootBase) + 'src/',
            withTrailing(rootBase)
        ];

        // Deduplicate while preserving order
        const seen = new Set();
        const out = [];
        for (let baseCandidate of orderedBases) {
            // Normalize protocol double-slashes but preserve scheme
            let urlBase = baseCandidate;
            const parts = baseCandidate.split('://');
            if (parts.length > 1) {
                const scheme = parts.shift();
                const rest = parts.join('://');
                const restClean = rest.replace(/\/\/+/g, '/').replace(/\/\/+$/,'/');
                urlBase = scheme + '://' + restClean;
            } else {
                urlBase = baseCandidate.replace(/\/\/+/g, '/');
            }
            if (urlBase.charAt(urlBase.length - 1) !== '/') urlBase += '/';
            const url = urlBase + relPath + (queryString ? ('?' + queryString) : '');
            if (!seen.has(url)) {
                seen.add(url);
                out.push(url);
            }
        }
        return out;
    }

    // Helper methods
    // escapeHtml imported from utils.js

    static parseHstore(hstr) {
        if (!hstr) return {};
        const obj = {};
        try {
            const trimmed = String(hstr).trim();
            if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || trimmed.startsWith('[')) {
                try {
                    const parsed = JSON.parse(trimmed);
                    if (parsed && typeof parsed === 'object') return parsed;
                } catch (e) {}
            }
        } catch (e) {}

        const hstoreRegex = /"([^\"]+)"\s*=>\s*"([^\"]*)"/g;
        let match;
        while ((match = hstoreRegex.exec(hstr)) !== null) {
            obj[match[1]] = match[2];
        }
        return obj;
    }

    // Ensure CSRF token is available for scripted imports. Caches token in instance and sets window.CSRF_TOKEN.
    async _ensureCsrfToken() {
        if (this._csrfToken) return this._csrfToken;
        let base = window.APP_BASE || '/';
        if (base.charAt(base.length - 1) !== '/') base += '/';
        const url = `${base}index.php/api/session/csrf`;
        try {
            const resp = await fetch(url, { method: 'GET', credentials: 'same-origin' });
            if (!resp.ok) return null;
            const j = await resp.json();
            if (j && j.success && j.csrf_token) {
                window.CSRF_TOKEN = j.csrf_token;
                this._csrfToken = j.csrf_token;
                return this._csrfToken;
            }
        } catch (e) {
            // ignore
        }
        return null;
    }

    static poiDisplayName(poi) {
        try {
            if (!poi) return '';
            if (poi.name && String(poi.name).trim()) return String(poi.name).trim();

            let tags = {};
            let tagsRaw = '';
            if (typeof poi.tags === 'string' && poi.tags.trim()) {
                tagsRaw = poi.tags;
                tags = PoiMapManager.parseHstore(poi.tags) || {};
            } else if (typeof poi.tags === 'object' && poi.tags) {
                tags = poi.tags;
            }
            if (!tags || typeof tags !== 'object') tags = {};

            try {
                const userLang = (navigator && navigator.language) ? String(navigator.language).split('-')[0] : null;
                if (userLang) {
                    const nameKey = `name:${userLang}`;
                    if (tags[nameKey] && String(tags[nameKey]).trim()) return String(tags[nameKey]).trim();
                    const altKey = `alt_name:${userLang}`;
                    if (tags[altKey] && String(tags[altKey]).trim()) return String(tags[altKey]).trim();
                }
            } catch (e) {}

            if (tags['name'] && String(tags['name']).trim()) return String(tags['name']).trim();
            if (tags['alt_name'] && String(tags['alt_name']).trim()) return String(tags['alt_name']).trim();

            if (!Object.keys(tags).length && tagsRaw) {
                const nameMatch = tagsRaw.match(/"name"\s*=>\s*"([^"]+)"/);
                if (nameMatch && nameMatch[1]) return nameMatch[1];
            }

            const candidates = ['brand', 'operator', 'ref', 'tourism', 'shop', 'amenity'];
            for (const k of candidates) {
                if (tags[k] && String(tags[k]).trim()) {
                    const val = String(tags[k]).trim();
                    if (val && !['yes', 'no', 'true', 'false'].includes(val)) {
                        return val;
                    }
                }
            }

            const _prettifyTagLabel = (v) => {
                if (!v) return '';
                let str = String(v).replace(/_/g, ' ');
                return str.replace(/\b\w/g, ch => ch.toUpperCase());
            };

            if (tags.camp_site && String(tags.camp_site).trim()) {
                return `Campground (${_prettifyTagLabel(tags.camp_site)})`;
            }
            if (tags.sanitary_dump_station === 'yes' || String(tags.sanitary_dump_station) === 'true') {
                return 'Dump Station';
            }
            if (tags.brand && String(tags.brand).trim()) {
                return String(tags.brand).trim();
            }

            const secondaryKeys = ['amenity', 'shop', 'tourism', 'leisure', 'building', 'cuisine', 'website'];
            for (const sk of secondaryKeys) {
                const skVal = tags[sk] ? String(tags[sk]).trim() : '';
                if (skVal && !['yes', 'no', 'true', 'false'].includes(skVal)) {
                    if (sk === 'website') continue;
                    if (sk === 'cuisine') return `${_prettifyTagLabel(skVal)} (Restaurant)`;
                    if (['tourism', 'amenity'].includes(sk)) return _prettifyTagLabel(skVal);
                    return skVal;
                }
            }

            if (tags['addr:street']) return String(tags['addr:street']).trim();
            if (poi.type && String(poi.type).trim()) return String(poi.type).trim();
            if (tags.website || tags.phone) return 'POI (Location)';
            return `OSM ${poi.osm_id || ''}`;
        } catch (e) {
            return (poi && poi.osm_id) ? `OSM ${poi.osm_id}` : '';
        }
    }

    _getCategoryForPoi(poi) {
        if (!poi) return null;
        let tags = (typeof poi.tags === 'object' && poi.tags) ? poi.tags : PoiMapManager.parseHstore(poi.tags);
        // If the Overpass endpoint returned amenity/tourism/shop/leisure etc as top-level
        // fields (rather than a single `tags` object), include them as a fallback so
        // CATEGORY_PREDICATES can evaluate those values.
        try {
            if ((!tags || Object.keys(tags).length === 0) && poi) {
                const fallback = {};
                if (typeof poi.amenity !== 'undefined' && poi.amenity !== '') fallback.amenity = poi.amenity;
                if (typeof poi.tourism !== 'undefined' && poi.tourism !== '') fallback.tourism = poi.tourism;
                if (typeof poi.shop !== 'undefined' && poi.shop !== '') fallback.shop = poi.shop;
                if (typeof poi.leisure !== 'undefined' && poi.leisure !== '') fallback.leisure = poi.leisure;
                if (typeof poi.brand !== 'undefined' && poi.brand !== '') fallback.brand = poi.brand;
                if (typeof poi.operator !== 'undefined' && poi.operator !== '') fallback.operator = poi.operator;
                if (Object.keys(fallback).length) tags = Object.assign({}, fallback, tags || {});
            }
        } catch (e) {}

        const name = poi.name || (tags ? tags.name : '');
        try { if (window.POI_DEBUG && console && console.debug) console.debug('_getCategoryForPoi tags', { osm_id: poi?.osm_id, tags, name }); } catch (e) {}

        for (const category in PoiMapManager.CATEGORY_PREDICATES) {
            if (PoiMapManager.CATEGORY_PREDICATES[category](tags, name)) {
                return category;
            }
        }
        return null;
    }

    // Determine whether a POI originates from the application MySQL `locations` table.
    // MySQL rows include an `id` field (app PK). Overpass results use `osm_id`.
    _isAppPoi(poi) {
        if (!poi) return false;
        // Prefer an explicit source marker set during normalization for MySQL rows
        if (String(poi.source) === 'mysql') return true;
        // Allow explicit flag (future-proof)
        if (poi._is_app === true) return true;
        // Fallback: do not treat raw Overpass 'id' as app id (Overpass responses include 'id').
        return false;
    }

    _getIconForPoi(poi) {
        // Only assign custom icons for POIs that come from the application DB (MySQL locations).
        // Overpass results should keep the Leaflet default marker.
        if (!this._isAppPoi(poi)) return null;

        const category = this._getCategoryForPoi(poi);
        try { if (window.POI_DEBUG && console && console.debug) console.debug('getIconForPoi called (app poi)', { app_id: poi?.id, osm_id: poi?.osm_id, logo: poi?.logo, category }); } catch (e) {}
        // Prefer explicit logo set on the POI (comes from MySQL `locations.logo`) when available
        // This lets imported locations show their assigned icon filenames.
        // Ensure APP_BASE has trailing slash
        let base = window.APP_BASE || '/';
        if (base.charAt(base.length - 1) !== '/') base += '/';

        

        // If POI contains a `logo` filename, prefer that
        if (poi && poi.logo) {
            const logoFile = String(poi.logo).trim();
            if (logoFile) {
                const key = `logo:${logoFile}`;
                if (this._iconCache[key]) return this._iconCache[key];
                const icon = L.icon({
                    iconUrl: `${base}assets/icons/${encodeURIComponent(logoFile)}`,
                    iconSize: [32, 32],
                    iconAnchor: [16, 32],
                    popupAnchor: [0, -32]
                });
                try { if (window.POI_DEBUG && console && console.debug) console.debug('getIconForPoi -> logo icon created', { osm_id: poi?.osm_id, logoFile, iconUrl: icon.options && icon.options.iconUrl }); } catch (e) {}
                this._iconCache[key] = icon;
                return icon;
            }
        }

        if (!category) return null;

        if (this._iconCache[category]) {
            return this._iconCache[category];
        }

        const iconFile = PoiMapManager.CATEGORY_ICONS[category];
        if (!iconFile) return null;

        const icon = L.icon({
            iconUrl: `${base}assets/icons/${iconFile}`,
            iconSize: [32, 32],
            iconAnchor: [16, 32],
            popupAnchor: [0, -32]
        });
        try { if (window.POI_DEBUG && console && console.debug) console.debug('getIconForPoi -> category icon created', { category, iconFile, iconUrl: icon.options && icon.options.iconUrl }); } catch (e) {}
        this._iconCache[category] = icon;
        return icon;
    }

    // Return a simple colored marker icon (DivIcon) for non-app/Overpass POIs.
    _getColoredMarkerIcon(poi) {
        try {
            if (!poi) return null;
            const category = this._getCategoryForPoi(poi) || '';
            const color = PoiMapManager.CATEGORY_COLORS[category] || PoiMapManager._colorForOsm(poi?.osm_id);
            const key = `colored:${category || 'osm'}:${color}`;
            if (this._iconCache[key]) return this._iconCache[key];
            const size = 20;
            const label = (category && PoiMapManager.POI_LETTER_MAP[category]) ? PoiMapManager.POI_LETTER_MAP[category] : ((category && String(category).length) ? String(category).charAt(0).toUpperCase() : '');
            const fontSize = Math.max(10, Math.round(size / 2.6));
            // Use semantic classes for sizing and CSS variables for dynamic color/font.
            // Most visual styles are defined in `features.css` under `.pv-marker`.
            let sizeClass = 'pv-marker--md';
            if (size <= 20) sizeClass = 'pv-marker--sm';
            else if (size >= 32) sizeClass = 'pv-marker--lg';
            const html = `<div class="pv-marker ${sizeClass}" style="--pv-marker-bg:${color}; --pv-marker-font:${fontSize}px">${label}</div>`;
            const icon = L.divIcon({ html: html, className: 'pv-div-icon', iconSize: [size, size], iconAnchor: [Math.round(size/2), size], popupAnchor: [0, -size] });
            this._iconCache[key] = icon;
            return icon;
        } catch (e) {
            return null;
        }
    }

    // Try candidate icon URLs and resolve the first that successfully loads.
    _candidateIconUrlsFor(logoFile) {
        const urls = [];
        let base = window.APP_BASE || '/';
        if (base.charAt(base.length - 1) !== '/') base += '/';

        // If consumer views expose an explicit icons base (server-generated), prefer it
        if (window.ICONS_BASE && typeof window.ICONS_BASE === 'string') {
            const b = window.ICONS_BASE;
            urls.push(b.charAt(b.length - 1) === '/' ? b + encodeURIComponent(logoFile) : b + '/' + encodeURIComponent(logoFile));
        }

        // Consolidated: <APP_BASE>/assets/icons/<file>
        urls.push(`${base}assets/icons/${encodeURIComponent(logoFile)}`);

        // If filename has no extension, try common icon extensions as fallbacks
        const hasExt = /\.[a-z0-9]+$/i.test(logoFile);
        if (!hasExt) {
            const exts = ['.svg', '.png', '-logo.svg', '-logo.png'];
            for (const e of exts) {
                urls.push(`${base}assets/icons/${encodeURIComponent(logoFile + e)}`);
            }
        }

        return urls;
    }

    _testImageUrl(url, timeoutMs = 2500) {
        return new Promise(resolve => {
            try {
                const img = new Image();
                let done = false;
                const onSuccess = () => { if (!done) { done = true; resolve(true); } };
                const onFail = () => { if (!done) { done = true; resolve(false); } };
                img.onload = onSuccess;
                img.onerror = onFail;
                img.src = url;
                setTimeout(() => { if (!done) { done = true; resolve(false); } }, timeoutMs);
            } catch (e) { resolve(false); }
        });
    }

    async _ensureMarkerIcon(poi, marker) {
        try {
            // If marker already has an icon, nothing to do
            if (marker && marker.options && marker.options.icon) return;

            // Only attempt to resolve/apply icons for application (MySQL) POIs.
            if (!this._isAppPoi(poi)) {
                try { if (window.POI_DEBUG && console && console.debug) console.debug('ensureMarkerIcon: skipping non-app POI', { osm_id: poi?.osm_id, app_id: poi?.id }); } catch (e) {}
                return;
            }

            // If poi has an explicit logo, try candidate URLs
            if (poi && poi.logo) {
                const logoFile = String(poi.logo).trim();
                if (logoFile) {
                    const cacheKey = `logo:${logoFile}`;
                    if (this._iconCache[cacheKey]) {
                        marker.setIcon(this._iconCache[cacheKey]);
                        return;
                    }
                    const candidates = this._candidateIconUrlsFor(logoFile);
                    for (const c of candidates) {
                        const ok = await this._testImageUrl(c);
                        if (ok) {
                            const icon = L.icon({ iconUrl: c, iconSize: [32,32], iconAnchor: [16,32], popupAnchor: [0,-32] });
                            this._iconCache[cacheKey] = icon;
                            marker.setIcon(icon);
                            try { if (window.POI_DEBUG && console && console.debug) console.debug('resolved icon url', { osm_id: poi?.osm_id, logoFile, url: c }); } catch (e) {}
                            return;
                        }
                    }
                }
            }

            // If no explicit logo or candidates failed, try category-based icon
            const category = this._getCategoryForPoi(poi);
            if (category) {
                const catKey = category;
                if (this._iconCache[catKey]) {
                    marker.setIcon(this._iconCache[catKey]);
                    return;
                }
                const iconFile = PoiMapManager.CATEGORY_ICONS[category];
                if (iconFile) {
                    // Try same candidate resolution but with iconFile
                    const candidates = this._candidateIconUrlsFor(iconFile);
                    for (const c of candidates) {
                        const ok = await this._testImageUrl(c);
                        if (ok) {
                            const icon = L.icon({ iconUrl: c, iconSize: [32,32], iconAnchor: [16,32], popupAnchor: [0,-32] });
                            this._iconCache[catKey] = icon;
                            marker.setIcon(icon);
                            try { if (window.POI_DEBUG && console && console.debug) console.debug('resolved category icon url', { category, iconFile, url: c }); } catch (e) {}
                            return;
                        }
                    }
                }
            }
            // Final fallback: try a generic poi.png in project icons folder
            const generic = 'poi.png';
            const genericCandidates = this._candidateIconUrlsFor(generic);
            for (const c of genericCandidates) {
                const ok = await this._testImageUrl(c);
                if (ok) {
                    const icon = L.icon({ iconUrl: c, iconSize: [32,32], iconAnchor: [16,32], popupAnchor: [0,-32] });
                    this._iconCache['generic:poi'] = icon;
                    marker.setIcon(icon);
                    try { if (window.POI_DEBUG && console && console.debug) console.debug('resolved generic poi icon', { url: c }); } catch (e) {}
                    return;
                }
            }
        } catch (e) {
            console.warn('Icon resolution failed', e);
        }
    }

    /**
     * Add or update a single application POI on the map after import.
     * If a marker exists for the POI's osm key, update its popup/icon; otherwise create it.
     */
    async _addOrUpdateAppPoi(poi) {
        try {
            if (!poi) return;
            const lat = Number(poi.lat || poi.latitude || poi.latitude_raw || 0);
            const lon = Number(poi.lon || poi.longitude || poi.longitude_raw || 0);
            const osmKey = PoiMapManager.getCanonicalKey(poi) || `${poi.osm_type || 'N'}${poi.osm_id}`;
            // Ensure maps of markers/pois exist
            this.markerByOsm = this.markerByOsm || {};
            this.poiByOsm = this.poiByOsm || {};

            const existing = this.markerByOsm[osmKey];
            // Update stored POI (store under canonical key if available)
            if (osmKey) this.poiByOsm[osmKey] = poi;

            if (existing) {
                // Update popup content
                try {
                    const displayName = PoiMapManager.poiDisplayName(poi);
                    const popupContent = PoiPopupTemplate.createPopupHtmlString(poi, displayName);
                    existing.setPopupContent(popupContent);
                } catch (e) {}
                // Update category metadata
                try { existing.options._pv_category = this._getCategoryForPoi(poi); } catch (e) {}
                // Prefer explicit logo/icon immediately and pulse the marker to highlight import
                try {
                    const icon = this._getIconForPoi(poi);
                    if (icon) {
                        existing.setIcon(icon);
                        this._pulseMarker(existing);
                    } else {
                        await this._ensureMarkerIcon(poi, existing);
                        this._pulseMarker(existing);
                    }
                } catch (e) {}
                return;
            }

            // If no existing marker, create one
            if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;
            const cat = this._getCategoryForPoi(poi);
            const icon = this._getIconForPoi(poi);
            const markerOptions = {
                osm_id: poi.osm_id,
                osm_type: poi.osm_type,
                _pv_category: cat,
                _pv_source: 'mysql'
            };
            if (icon) markerOptions.icon = icon;
            const marker = L.marker([lat, lon], markerOptions);
            try {
                const displayName = PoiMapManager.poiDisplayName(poi);
                const popupContent = PoiPopupTemplate.createPopupHtmlString(poi, displayName);
                // Allow previous popups to close when a new one opens (default Leaflet behavior)
                marker.bindPopup(popupContent, { closeOnClick: true, autoClose: true, autoPan: false, keepInView: false });
            } catch (e) {}
            marker.on('click', () => { this.toggleSelection(marker); try { marker.openPopup(); } catch (e) {} });
            // Register marker under multiple canonical variants for robust lookups
            try {
                const variants = PoiMapManager.canonicalVariants(poi);
                variants.forEach(v => { if (v) this.markerByOsm[v] = marker; });
            } catch (e) {
                this.markerByOsm[osmKey] = marker;
            }
            this.markers.addLayer(marker);
            try { await this._ensureMarkerIcon(poi, marker); } catch (e) {}
        } catch (e) { try { console.debug('_addOrUpdateAppPoi failed', e); } catch (ex) {} }
    }

    _pulseMarker(marker) {
        try {
            if (!marker) return;
            // Leaflet marker element is available via getElement() or _icon
            const el = (typeof marker.getElement === 'function') ? marker.getElement() : (marker && marker._icon ? marker._icon : null);
            if (!el) return;
            el.classList.add('pv-import-pulse');
            // remove class after animation ends (use timeout slightly longer than animation)
            setTimeout(() => { try { el.classList.remove('pv-import-pulse'); } catch (e) {} }, 1100);
        } catch (e) {}
    }

    initMap() {
        this.map = L.map(this.options.mapId).setView(this.options.mapCenter, this.options.mapZoom);
        // Prevent map-level clicks from closing popups globally for this map.
        try { this.map.options.closePopupOnClick = false; } catch (e) {}
        // Ensure Leaflet default marker icons resolve even if local image files are missing.
        try {
            // Prefer CDN-hosted images to avoid missing local vendor images.
            const leafletCdnBase = 'https://unpkg.com/leaflet@1.9.4/dist/images/';
            L.Icon.Default.mergeOptions({
                iconUrl: `${leafletCdnBase}marker-icon.png`,
                iconRetinaUrl: `${leafletCdnBase}marker-icon-2x.png`,
                shadowUrl: `${leafletCdnBase}marker-shadow.png`
            });
            // Prevent Leaflet from prefixing a local imagePath to our absolute URLs
            try { L.Icon.Default.imagePath = ''; } catch (e) {}
            try { if (L.Icon && L.Icon.Default && L.Icon.Default.prototype && typeof L.Icon.Default.prototype.options !== 'undefined') L.Icon.Default.prototype.options.imagePath = ''; } catch (e) {}

            // Inject CSS override so the leaflet default icons resolve from CDN
            try {
                // Scope injected default-icon rules to avoid overriding our cluster divIcons
                const css = `\n.leaflet-default-icon-path{ background-image: url(${leafletCdnBase}marker-icon.png); }\n.leaflet-marker-icon:not(.pv-cluster-icon) { background-image: url(${leafletCdnBase}marker-icon.png); }\n.leaflet-marker-shadow { background-image: url(${leafletCdnBase}marker-shadow.png); }\n`;
                const s = document.createElement('style');
                s.type = 'text/css';
                s.appendChild(document.createTextNode(css));
                document.head.appendChild(s);
            } catch (e) {}
        } catch (e) {
            // swallow if Leaflet not yet available
            console.warn('Could not set Leaflet default icon URLs', e);
        }

        // Add small CSS for our custom markers and clusters
        try {
            const css2 = `
            .pv-div-icon{background:transparent}
            .pv-marker{line-height:1}
            .pv-cluster{display:flex;align-items:center;justify-content:center}
            .pv-cluster-icon{border-radius:50%;overflow:visible}
            `;
            const s2 = document.createElement('style');
            s2.type = 'text/css';
            // add import pulse animation CSS
            const pulseCss = `
            .pv-import-pulse{animation:pv-import-pulse 0.9s ease both}
            @keyframes pv-import-pulse{0%{transform:scale(0.85);box-shadow:0 0 0 0 rgba(0,0,0,0.0)}30%{transform:scale(1.12);box-shadow:0 6px 18px rgba(0,0,0,0.18)}100%{transform:scale(1);box-shadow:0 0 0 0 rgba(0,0,0,0)}}
            `;
            s2.appendChild(document.createTextNode(css2 + '\n' + pulseCss));
            document.head.appendChild(s2);
        } catch (e) {}

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(this.map);

        // Simplified cluster rendering: minimal logic, semantic classes and CSS-only sizing.
        this.markers = L.markerClusterGroup({
            // cluster behavior improvements
            disableClusteringAtZoom: 14,
            maxClusterRadius: 50,
            spiderfyOnMaxZoom: true,
            // disable animated cluster expand/zoom to avoid briefly showing
            // individual markers before clusters re-evaluate
            animate: false,
            animateAddingMarkers: false,
            iconCreateFunction: (cluster) => {
                try {
                    const childMarkers = cluster.getAllChildMarkers();
                    const counts = {};
                    for (const m of childMarkers) {
                        const cat = (m.options && m.options._pv_category) ? m.options._pv_category : null;
                        const color = (cat && PoiMapManager.CATEGORY_COLORS[cat]) ? PoiMapManager.CATEGORY_COLORS[cat] : PoiMapManager._colorForOsm(m.options && m.options.osm_id ? m.options.osm_id : '0');
                        counts[color] = (counts[color] || 0) + 1;
                    }
                    let topColor = '#888'; let topCount = 0;
                    for (const c in counts) { if (counts[c] > topCount) { topCount = counts[c]; topColor = c; } }
                    const total = childMarkers.length || 0;
                    // size class: small (<10), medium (10-49), large (>=50)
                    let sizeClass = 'small';
                    if (total >= 50) sizeClass = 'large';
                    else if (total >= 10) sizeClass = 'medium';
                    // Use CSS variable for background color; prefer CSS to control visuals.
                    const html = `<div class="pv-cluster-simple" style="--pv-bg:${topColor}"><span class="pv-cluster-count">${total}</span></div>`;
                    // Use a fixed iconSize so Leaflet doesn't fall back to default marker rendering.
                    return L.divIcon({ html: html, className: `marker-cluster marker-cluster-${sizeClass} pv-cluster-icon pv-cluster-simple`, iconSize: [36, 36], iconAnchor: [18, 18] });
                } catch (e) {
                    return L.divIcon({ html: `<div class="pv-cluster-simple" style="--pv-bg:#888"><span class="pv-cluster-count">?</span></div>`, className: 'marker-cluster marker-cluster-small pv-cluster-icon pv-cluster-simple', iconSize: [36,36], iconAnchor: [18,18] });
                }
            }
        });
        this.map.addLayer(this.markers);

        this.createSpinner();
        this.addImportControl();
        this.addZoomControl();
        this.addLegend();

        // Track popup state so we can avoid refreshing markers while a popup is open
        this._popupOpenFlag = false;
        this.map.on('popupopen', () => { this._popupOpenFlag = true; });
        this.map.on('popupclose', () => { this._popupOpenFlag = false; });

        this.map.on('moveend', () => {
            clearTimeout(this.debounceTimeout);
            this.debounceTimeout = setTimeout(() => {
                // If a popup is currently open, skip the automatic refresh to avoid
                // clearing/recreating markers which closes popups and can hide icons.
                if (this._popupOpenFlag) return;
                if (this.hasLoadedOnce) this.fetchAndPlot();
            }, 300);
        });
    }

    createSpinner() {
        // Create an overlay covering the map with a centered spinner.
        if (document.getElementById('pois-loading-overlay')) return;
        const mapEl = document.getElementById(this.options.mapId);
        if (!mapEl) return;
        mapEl.style.position = mapEl.style.position || 'relative';

        const overlay = document.createElement('div');
        overlay.id = 'pois-loading-overlay';
        overlay.style.cssText = 'display:none; position:absolute; left:0; top:0; right:0; bottom:0; background: rgba(0,0,0,0.35); z-index: 9998; align-items:center; justify-content:center;';
        overlay.setAttribute('aria-hidden', 'true');

        const box = document.createElement('div');
        box.className = 'poi-spinner-box';
        box.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:8px;padding:12px;border-radius:8px; background: rgba(255,255,255,0.06);';

        const spinnerSpan = document.createElement('span');
        spinnerSpan.className = 'spinner';
        spinnerSpan.style.cssText = 'width:32px;height:32px;border:3px solid rgba(255,255,255,0.6);border-top-color:rgba(255,255,255,1);border-radius:50%;animation:pv-spin 0.8s linear infinite;';
        box.appendChild(spinnerSpan);

        const textSpan = document.createElement('div');
        textSpan.className = 'spinner-text';
        textSpan.style.cssText = 'color:#fff;font-size:14px;';
        const loadingText = (window.I18N?.pois?.loading) || 'Loading POIs…';
        textSpan.textContent = loadingText;
        box.appendChild(textSpan);

        overlay.appendChild(box);
        // center box vertically/horizontally
        overlay.style.display = 'none';
        overlay.style.alignItems = 'center';
        overlay.style.justifyContent = 'center';

        mapEl.appendChild(overlay);
    }

    showLoadingOverlay(message) {
        try {
            const ov = document.getElementById('pois-loading-overlay');
            if (!ov) return;
            if (typeof message === 'string' && message.length) {
                const txt = ov.querySelector('.spinner-text');
                if (txt) txt.textContent = message;
            }
            ov.style.display = 'flex';
            ov.setAttribute('aria-hidden', 'false');
        } catch (e) {}
    }

    hideLoadingOverlay() {
        try {
            const ov = document.getElementById('pois-loading-overlay');
            if (!ov) return;
            ov.style.display = 'none';
            ov.setAttribute('aria-hidden', 'true');
            const txt = ov.querySelector('.spinner-text');
            if (txt) txt.textContent = (window.I18N?.pois?.loading) || 'Loading POIs…';
        } catch (e) {}
    }

    addLegend() {
        try {
            const mapEl = document.getElementById(this.options.mapId);
            if (!mapEl) return;
            if (document.getElementById('pv-poi-legend')) return;
            const legend = document.createElement('div');
            legend.id = 'pv-poi-legend';
            legend.className = 'pv-legend';
            legend.style.cssText = 'position:absolute; right:10px; bottom:10px; background:rgba(255,255,255,0.95); padding:8px; border-radius:6px; box-shadow:0 1px 4px rgba(0,0,0,0.2); font-size:12px; z-index:1000; max-width:260px;';
            const title = (window.I18N && window.I18N.pois && window.I18N.pois.legend_title) ? window.I18N.pois.legend_title : 'Legend';
            const titleEl = document.createElement('div');
            titleEl.style.cssText = 'font-weight:700;margin-bottom:6px';
            titleEl.textContent = title;
            legend.appendChild(titleEl);
            const list = document.createElement('div');
            list.style.cssText = 'display:flex;flex-direction:column;gap:4px;';
            // Build entries from POI_LETTER_MAP
            for (const cat in PoiMapManager.POI_LETTER_MAP) {
                try {
                    const letter = PoiMapManager.POI_LETTER_MAP[cat] || (cat ? String(cat).charAt(0).toUpperCase() : '?');
                    const color = PoiMapManager.CATEGORY_COLORS[cat] || PoiMapManager._colorForOsm(cat);
                    const labelText = (window.I18N && window.I18N.pois && window.I18N.pois.types && window.I18N.pois.types[cat]) ? window.I18N.pois.types[cat] : (cat.charAt(0).toUpperCase() + cat.slice(1));
                    const item = document.createElement('div');
                    item.style.cssText = 'display:flex;align-items:center;';

                    // Compact colored swatch with letter (no image)
                    const swatch = document.createElement('div');
                    swatch.style.cssText = `width:14px;height:14px;border-radius:50%;background:${color};display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:10px;margin-right:8px;border:1px solid rgba(0,0,0,0.08)`;
                    swatch.textContent = letter;
                    item.appendChild(swatch);

                    const txt = document.createElement('div');
                    txt.textContent = labelText;
                    item.appendChild(txt);
                    list.appendChild(item);
                } catch (e) { continue; }
            }
            legend.appendChild(list);
            mapEl.appendChild(legend);
        } catch (e) { console.warn('addLegend failed', e); }
    }

    addImportControl() {
        const ImportControl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: (map) => {
                const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control');
                const a = L.DomUtil.create('a', '', container);
                a.href = '#';
                a.title = (window.I18N?.pois?.import_visible_title) || 'Import visible POIs into app database';
                a.innerHTML = '&#8682;'; // import-ish glyph
                L.DomEvent.on(a, 'click', L.DomEvent.stopPropagation)
                    .on(a, 'click', L.DomEvent.preventDefault)
                    .on(a, 'click', () => this.doBatchImportVisible());
                return container;
            }
        });
        this.map.addControl(new ImportControl());
    }

    addZoomControl(){
        try{
            const ZoomControl = L.Control.extend({
                options: { position: 'topright' },
                onAdd: (map) => {
                    const container = L.DomUtil.create('div', 'leaflet-bar poi-zoom-control');
                        container.style.padding = '6px 8px';
                        container.style.fontSize = '12px';
                        container.style.borderRadius = '4px';
                        container.style.background = 'rgba(255,255,255,0.95)';
                        container.style.boxShadow = '0 1px 4px rgba(0,0,0,0.15)';
                        container.innerHTML = `<div class="poi-zoom-main">Zoom: ${map.getZoom()}</div><div class="poi-zoom-hint" style="font-size:11px;color:#444"></div>`;
                    // prevent map interactions when clicking the control
                    L.DomEvent.disableClickPropagation(container);
                    this._zoomContainer = container;
                        this._overpassHintEl = container.querySelector('.poi-zoom-hint');
                    return container;
                }
            });
            this.map.addControl(new ZoomControl());
            this.map.on('zoomend', ()=>{
                try{
                    if(this._zoomContainer) {
                        const main = this._zoomContainer.querySelector('.poi-zoom-main');
                        if(main) main.textContent = 'Zoom: ' + this.map.getZoom();
                    }
                    if (this._overpassHintEl) {
                        const z = this.map.getZoom();
                        if (z >= (this.options.overpassMinZoom || 0)) {
                            this._overpassHintEl.textContent = 'Overpass enabled';
                        } else {
                            const uid = window.CURRENT_USER_ID ? ' (you see only your MySQL POIs)' : '';
                            this._overpassHintEl.textContent = 'Overpass disabled below zoom ' + (this.options.overpassMinZoom || 0) + uid;
                        }
                    }
                }catch(e){}
            });
            // Initial hint update
            try {
                if (this._overpassHintEl) {
                    const z0 = this.map.getZoom();
                    if (z0 >= (this.options.overpassMinZoom || 0)) {
                        this._overpassHintEl.textContent = 'Overpass enabled';
                    } else {
                        const uid = window.CURRENT_USER_ID ? ' (you see only your MySQL POIs)' : '';
                        this._overpassHintEl.textContent = 'Overpass disabled below zoom ' + (this.options.overpassMinZoom || 0) + uid;
                    }
                }
            } catch (e) {}
        }catch(e){ console.warn('addZoomControl failed', e); }
    }

    // Center the map on a POI and open a lightweight popup if necessary
    centerOnPoi(poi, options={}){
        try{
            if(!this.map) return;
            const lat = poi.latitude || poi.lat || poi.lat || poi.latitude || null;
            const lon = poi.longitude || poi.lon || poi.lng || null;
            if(!lat || !lon) return;
            const latNum = parseFloat(lat);
            const lonNum = parseFloat(lon);
            if(Number.isNaN(latNum) || Number.isNaN(lonNum)) return;
            // decide target zoom: default to current or options.zoom; caller can set 15
            const targetZoom = (options && options.zoom && Number.isFinite(options.zoom)) ? options.zoom : this.map.getZoom();
            this.map.setView([latNum, lonNum], targetZoom, { animate: true });

            // Try to find an existing marker and open its popup, otherwise open a temporary popup
            let opened = false;
            try{
                // look for osm id or internal id keys in markerByOsm
                let candidateKeys = [];
                try { candidateKeys = PoiMapManager.canonicalVariants(poi); } catch (e) { candidateKeys = []; }
                try {
                    if (poi.osm_id) candidateKeys.push(String(poi.osm_id));
                    if (poi.osm) candidateKeys.push(String(poi.osm));
                    if (poi.id) candidateKeys.push(String(poi.id));
                    if (poi.osm_ids && Array.isArray(poi.osm_ids)) candidateKeys.push(...poi.osm_ids.map(String));
                } catch (e) {}
                for(const k of candidateKeys){
                    const m = this.markerByOsm[k];
                    if(m){
                        if(m.getPopup) m.openPopup();
                        else if(m._popup) m._popup.openOn(this.map);
                        opened = true; break;
                    }
                }
            }catch(e){}

            if(!opened){
                // Build full popup HTML using PoiPopupTemplate if available
                try{
                    const displayName = (poi.display_name || poi.name || poi.osm_name || ('POI ' + (poi.id||'')));
                    const popupHtml = (typeof window.PoiPopupTemplate !== 'undefined' && window.PoiPopupTemplate && typeof window.PoiPopupTemplate.createPopupHtmlString === 'function')
                        ? window.PoiPopupTemplate.createPopupHtmlString(poi, displayName)
                        : (typeof PoiPopupTemplate !== 'undefined' && typeof PoiPopupTemplate.createPopupHtmlString === 'function')
                            ? PoiPopupTemplate.createPopupHtmlString(poi, displayName)
                            : `<div class="poi-popup"><strong>${(displayName)}</strong></div>`;

                    // If a marker exists at that location, bind and open its popup for consistency
                    let markerOpened = false;
                    try{
                        let candidateKeys = [];
                        try { candidateKeys = PoiMapManager.canonicalVariants(poi); } catch (e) { candidateKeys = []; }
                        try {
                            if(poi.osm_id) candidateKeys.push(String(poi.osm_id));
                            if(poi.osm) candidateKeys.push(String(poi.osm));
                            if(poi.id) candidateKeys.push(String(poi.id));
                            if(poi.osm_ids && Array.isArray(poi.osm_ids)) candidateKeys.push(...poi.osm_ids.map(String));
                        } catch (e) {}
                        for(const k of candidateKeys){
                            const m = this.markerByOsm[k];
                            if(m){
                                if(typeof m.bindPopup === 'function') m.bindPopup(popupHtml, {maxWidth:300});
                                if(typeof m.openPopup === 'function') m.openPopup();
                                markerOpened = true; break;
                            }
                        }
                    }catch(e){}

                    if(!markerOpened){
                        L.popup({autoClose:true,closeOnClick:true, maxWidth:300})
                            .setLatLng([latNum, lonNum])
                            .setContent(popupHtml)
                            .openOn(this.map);
                    }
                }catch(e){
                    // fallback simple popup
                    L.popup({autoClose:true,closeOnClick:true})
                        .setLatLng([latNum, lonNum])
                        .setContent(`<div class="poi-popup"><strong>${(poi.display_name||poi.name||'POI')}</strong></div>`)
                        .openOn(this.map);
                }
            }
        }catch(e){ console.warn('centerOnPoi failed', e); }
    }

    setStatus(msg, isError = false, loading = false) {
        let s = document.querySelector('.pois-status');
        if (!s) {
            s = document.createElement('div');
            s.className = 'pois-status';
            const mapEl = document.getElementById(this.options.mapId);
            if (mapEl) mapEl.appendChild(s);
            else document.body.insertBefore(s, document.body.firstChild);
        }
        s.textContent = msg || '';
        s.classList.toggle('loading', !!loading);
        s.style.color = isError ? '#a00' : '';
        s.style.display = msg ? '' : 'none';
        const spinner = document.getElementById('pois-loading-spinner');
        if (spinner) spinner.style.display = loading ? '' : 'none';
    }

    clearPoiLayers() {
        this.markers?.clearLayers();
        if (window._poi_overpass_visible_layer) window._poi_overpass_visible_layer.clearLayers();
        if (window._poi_overpass_layer) window._poi_overpass_layer.clearLayers();
        if (window._poi_debug_layer) window._poi_debug_layer.clearLayers();
        this.markerByOsm = {};
        this.poiByOsm = {};
        this.selectedOsm.clear();
        this.updateSelectedUI();
    }

    // Resolve a POI record from a marker key by trying canonical and legacy forms.
    _getPoiForKey(key) {
        try {
            if (!key) return null;
            if (this.poiByOsm && this.poiByOsm[key]) return this.poiByOsm[key];
            // if key looks like a canonical form (TYPE:ID)
            if (String(key).includes(':')) {
                const k = String(key);
                if (this.poiByOsm[k]) return this.poiByOsm[k];
                // try removing colon variant
                const parts = k.split(':');
                if (parts.length === 2) {
                    const alt = parts[0] + parts[1];
                    if (this.poiByOsm[alt]) return this.poiByOsm[alt];
                }
            }
            // try numeric id variants
            const sid = String(key).replace(/[^0-9]/g, '');
            if (sid && sid.length) {
                const types = ['A','N','W','R'];
                for (const t of types) {
                    const cand = `${t}:${sid}`;
                    if (this.poiByOsm[cand]) return this.poiByOsm[cand];
                }
            }
        } catch (e) {}
        return null;
    }

    // Client-side filter helpers: hide/show markers that are not from the application DB
    _applyOnlyMineFilter() {
        try {
            this._filteredOutMarkers = this._filteredOutMarkers || {};
            Object.keys(this.markerByOsm).forEach(osmKey => {
                const poi = this._getPoiForKey(osmKey);
                const marker = this.markerByOsm[osmKey];
                if (!marker) return;
                // Keep markers that are app POIs (have `id`), hide others
                if (!poi || !this._isAppPoi(poi)) {
                    // Remove from cluster layer but keep reference for restoration
                    try { this.markers.removeLayer(marker); } catch (e) {}
                    this._filteredOutMarkers[osmKey] = marker;
                }
            });
        } catch (e) { console.warn('applyOnlyMineFilter failed', e); }
    }

    _clearOnlyMineFilter() {
        try {
            if (!this._filteredOutMarkers) return;
            Object.keys(this._filteredOutMarkers).forEach(osmKey => {
                const marker = this._filteredOutMarkers[osmKey];
                if (marker) this.markers.addLayer(marker);
            });
            this._filteredOutMarkers = {};
        } catch (e) { console.warn('clearOnlyMineFilter failed', e); }
    }

    updateSelectedUI() {
        // Update marker styles
        Object.keys(this.markerByOsm).forEach(osm => {
            const m = this.markerByOsm[osm];
            if (m) this.setMarkerSelected(m, this.selectedOsm.has(osm));
        });

        // Global delegated capture listener as fallback to detect clicks on dynamically
        // created import buttons that for some reason don't trigger the attached handler.
        try {
            document.addEventListener('click', function (ev) {
                try {
                    var tgt = ev.target && ev.target.closest ? ev.target.closest('.poi-import-btn') : null;
                    if (!tgt) return;
                    try { console.debug && console.debug('delegated import click', { href: tgt.href || null, osmId: tgt.dataset && tgt.dataset.osmId, osmType: tgt.dataset && tgt.dataset.osmType, pvBound: tgt.dataset && tgt.dataset.pvBound }); } catch (e) {}
                    // If the button wasn't bound by our popupopen handler, call the manager directly
                    if ((!tgt.dataset || !tgt.dataset.pvBound) && window.PV_POI_MANAGER && typeof window.PV_POI_MANAGER.doImportPoi === 'function') {
                        try {
                            ev.preventDefault(); ev.stopPropagation();
                        } catch (e) {}
                        try { window.PV_POI_MANAGER.doImportPoi(tgt.dataset.osmType, tgt.dataset.osmId); } catch (e) { console.error('Fallback import call failed', e); }
                    }
                } catch (e) {}
            }, true);
        } catch (e) {}
        // Update import button
        const importBtn = document.getElementById('import-selected-pois');
        if (importBtn) {
            const count = this.selectedOsm.size;
            importBtn.disabled = (count === 0);
            const importLabel = (window.I18N?.pois?.import_selected) || 'Import Selected';
            importBtn.textContent = count ? `${importLabel} (${count})` : importLabel;
        }
    }

    setMarkerSelected(marker, isSelected) {
        if (!marker) return;
        marker._selected = !!isSelected;
        const el = marker._icon || (marker.getElement && marker.getElement());
        if (el) {
            el.classList.toggle('poi-selected', !!isSelected);
        }
    }

    toggleSelection(marker) {
        const ids = [];
        if (marker?.options) {
            if (Array.isArray(marker.options.osm_ids)) ids.push(...marker.options.osm_ids.map(String));
            else if (marker.options.osm_id) ids.push(String(marker.options.osm_id));
        }
        if (!ids.length) return;

        ids.forEach(id => { this.markerByOsm[id] = marker; });

        const first = ids[0];
        const isSelected = this.selectedOsm.has(first);

        ids.forEach(id => {
            if (isSelected) this.selectedOsm.delete(id);
            else this.selectedOsm.add(id);
        });

        this.setMarkerSelected(marker, !isSelected);
        this.updateSelectedUI();
    }

    async fetchAndPlot(opts = {}) {
        if (!this.map) return;
        opts = opts || {};

        const bounds = this.map.getBounds();
        const zoom = this.map.getZoom();
        const search = document.getElementById('poi-search')?.value || '';
        // Default to showing the logged-in user's MySQL POIs when the checkbox is not present.
        const onlyMineEl = document.getElementById('poi-only-mine');
        let onlyMine = (onlyMineEl ? !!onlyMineEl.checked : !!window.CURRENT_USER_ID);
        // Allow callers to force a overpass lookup (e.g. Load POIs button)
        if (opts && opts.forceOverpass) onlyMine = false;

        let types = this.getSelectedCategories();
        // Map frontend category keys to server-expected `types` keys
        let serverTypes = [];
        try {
            const serverTypeMap = {
                hotel: 'hotels',
                attraction: 'attractions',
                tourist_info: 'tourist_info',
                food: 'food',
                nightlife: 'nightlife',
                fuel: 'fuel',
                parking: 'parking',
                bank: 'bank',
                healthcare: 'pharmacy',
                fitness: 'fitness',
                laundry: 'laundry',
                supermarket: 'supermarket',
                tobacco: 'tobacco',
                cannabis: 'cannabis',
                transport: 'transport',
                dump_station: 'dump_station',
                campgrounds: 'campgrounds'
            };
            if (Array.isArray(types) && types.length) serverTypes = types.map(t => serverTypeMap[t] || t);
        } catch (e) { serverTypes = types; }
        if (opts.ignoreTypes) {
            types = [];
        }
        if (opts.onlyMysql) {
            // force MySQL-only: clear types and ensure onlyMine mode
            types = [];
            onlyMine = true;
            if (onlyMineEl) try { onlyMineEl.checked = true; } catch (e) {}
        }

        // If no filters selected and no search term, prefer showing the
        // current user's MySQL POIs only (avoids slow Overpass lookups).
        try {
            const noFilters = !Array.isArray(types) || types.length === 0;
            const noSearch = !search || String(search).trim() === '';
            if (!opts.forceOverpass && noFilters && noSearch) {
                // Default to showing only the user's MySQL POIs when there are no
                // active filters or search terms to avoid slow Overpass lookups.
                onlyMine = true;
                // Reflect default in the UI checkbox when present so state is clear
                try {
                    if (onlyMineEl) {
                        try { onlyMineEl.checked = true; } catch (e) {}
                    }
                } catch (e) {}
                if (window.POI_DEBUG && console && console.debug) console.debug('fetchAndPlot: no filters/search -> defaulting to onlyMine');
            }
        } catch (e) {}

        const queryKey = JSON.stringify({
            bounds: [bounds.toBBoxString()],
            zoom,
            search,
            types,
            onlyMine
        });

        if (queryKey === this.lastQueryKey && !opts.force) {
            return;
        }
        this.lastQueryKey = queryKey;

        this.setStatus((window.I18N?.pois?.loading) || 'Loading POIs…', false, true);
        // show overlay while loading
        try { this.createSpinner(); this.showLoadingOverlay((window.I18N?.pois?.loading) || 'Loading POIs…'); } catch (e) {}

        // If the user requested "only mine" and we already have markers loaded,
        // apply the client-side filter and skip any network fetches (prevents large bbox errors).
        if (onlyMine) {
            const hasExistingMarkers = this.markerByOsm && Object.keys(this.markerByOsm).length > 0;
            if (hasExistingMarkers) {
                // Ensure any previously filtered markers are cleared/restored appropriately
                this._clearOnlyMineFilter(); // restore to canonical state before re-applying
                this._applyOnlyMineFilter();
                const shown = this.markers ? (this.markers.getLayers ? this.markers.getLayers().length : Object.keys(this.markerByOsm).length) : 0;
                this.setStatus(shown ? `${shown} POIs shown (only mine).` : 'No My POIs found in current view.', false, false);
                try { this.hideLoadingOverlay(); } catch (e) {}
                return;
            }
        }

        // Preserve currently selected POIs across the refresh so popups/icons
        // remain visible for user-selected items even when clusters refresh
        const preservedSelection = new Set(this.selectedOsm);
        // NOTE: do not clear existing layers here — clearing before the
        // network fetch can cause all markers to disappear if the fetch
        // fails or returns no items. Layers are cleared later, just before
        // new markers are added, to ensure a stable UI during loading.

        // Ensure APP_BASE has trailing slash
        let base = window.APP_BASE || '/';
        if (base.charAt(base.length - 1) !== '/') base += '/';

        // Try multiple API endpoints in order. Some servers may not route
        // extensionless paths to the front controller, so fall back to
        // index.php-prefixed or explicit php script when necessary.
        // Primary: prefer an absolute explicit path to the v2 overpass script to avoid
        // falling back to legacy endpoints or relying on relative base resolution.
        // Keep a couple of v2 variants for robustness, but remove legacy `search_overpass` fallbacks.
        // Overpass endpoint candidates will be built after the query string is prepared
        // (we must not reference `queryStr` before it's created).

        try {
            let data = [];
            // If the user requested only-my POIs, always fetch MySQL application POIs
            // for the current bbox and skip any overpass lookups entirely.
            if (onlyMine) {
                try {
                    // Build bbox in MySQL-search expected order: south,west,north,east
                    const bboxParam = [bounds.getSouth(), bounds.getWest(), bounds.getNorth(), bounds.getEast()].join(',');
                    const params = new URLSearchParams({
                        bbox: bboxParam,
                        limit: 2000,
                        mine: 1,
                        // send selected types as CSV so server can filter MySQL results
                        types: (types && types.length) ? types.join(',') : '',
                        search: search || ''
                    });
                    
                    const urls = PoiMapManager.makeApiCandidates('api/locations/search.php', params.toString());
                    let resp = null;
                    for (const u of urls) {
                        try {
                            resp = await fetch(u, { credentials: 'same-origin', signal: AbortSignal.timeout(10000) });
                            if (resp && resp.ok) break;
                            try { if (window.POI_DEBUG && console && console.debug) console.debug('MySQL candidate response', { url: u, status: resp && resp.status }); } catch (e) {}
                            resp = null;
                        } catch (e) { resp = null; continue; }
                    }
                    if (resp) {
                        const j = await resp.json();
                        const rows = Array.isArray(j.data) ? j.data : (Array.isArray(j) ? j : []);
                        // Normalize rows to match overpass shape expected later
                        const normalized = rows.map(p => {
                            const poi = Object.assign({}, p);
                            if (typeof poi.latitude !== 'undefined' && typeof poi.longitude !== 'undefined') {
                                poi.lat = parseFloat(poi.latitude);
                                poi.lon = parseFloat(poi.longitude);
                            }
                            // Mark as mysql source and ensure identifying fields for keys
                            poi.source = 'mysql';
                            if (typeof poi.id !== 'undefined' && poi.id !== null) {
                                poi.osm_type = 'A';
                                poi.osm_id = poi.id;
                            }
                            if (!poi.name && (poi.brand || poi.operator)) poi.name = poi.brand || poi.operator;
                            return poi;
                        });
                        data = normalized;
                    } else {
                        // No response or empty -> empty result set (skip overpass)
                        data = [];
                    }
                } catch (e) {
                    console.warn('MySQL-only fetch failed', e);
                    data = [];
                }
                // Bypass overpass entirely when onlyMine flag is active
            } else {
                // original overpass path continues below
            }
                // Use overpass for all non-MySQL queries.
                // Also allow forcing overpass lookup when caller passes `opts.force` (e.g. Load POIs button).
                if (!onlyMine) {
                const params = new URLSearchParams({
                    action: 'query',
                    // Use explicit lat,lon order: south,west,north,east so server parses correctly
                    bbox: [bounds.getSouth(), bounds.getWest(), bounds.getNorth(), bounds.getEast()].join(','),
                    zoom: zoom,
                    min_zoom: this.options.overpassMinZoom,
                    limit: 2000,
                    agg_grid: this.options.poiAggGrid,
                    search: search,
                    types: (serverTypes && serverTypes.length) ? serverTypes.join(',') : '',
                    only_mine: onlyMine ? '1' : '0'
                });

                const queryStr = params.toString();
                let response = null;
                let lastError = null;

                    // Build Overpass API URL candidates now that query string is prepared
                    let overpassApiCandidates = PoiMapManager.makeApiCandidates('api/locations/search_overpass_v2.php', queryStr);
                    // Some server setups require the `src/index.php/` prefix — ensure we include that variant
                    try {
                        const rel = 'api/locations/search_overpass_v2.php';
                        if (window.API_BASE && !String(window.API_BASE).includes('/src')) {
                            let base = String(window.API_BASE);
                            if (base.charAt(base.length - 1) !== '/') base += '/';
                            const candidate1 = base + 'src/index.php/' + rel + (queryStr ? ('?' + queryStr) : '');
                            const candidate2 = base + 'src/' + rel + (queryStr ? ('?' + queryStr) : '');
                            overpassApiCandidates.push(candidate1);
                            overpassApiCandidates.push(candidate2);
                        }
                    } catch (e) {}
                    if (window.POI_DEBUG && console && console.debug) console.debug('Overpass candidates:', overpassApiCandidates);

                // Try Overpass candidates and take the first successful response
                for (const candidate of overpassApiCandidates) {
                    try {
                        if (window.POI_DEBUG && console && console.debug) console.debug('Overpass candidate ->', candidate);
                        const resp = await fetch(candidate, { credentials: 'same-origin', signal: AbortSignal.timeout(this.options.overpassTimeoutMs) });
                        if (resp && resp.ok) {
                            response = resp;
                            if (window.POI_DEBUG && console && console.debug) console.debug('Overpass candidate succeeded', { url: candidate, status: resp.status });
                            break;
                        }
                        // Log non-OK responses for diagnostics
                        try {
                            let bodyText = '';
                            try { bodyText = await resp.text(); } catch (e) { bodyText = ''; }
                            if (window.POI_DEBUG && console && console.debug) console.debug('Overpass candidate non-ok', { url: candidate, status: resp && resp.status, body: (bodyText && bodyText.slice) ? bodyText.slice(0,200) : bodyText });
                        } catch (e) {}
                        // otherwise continue to next candidate
                    } catch (e) {
                        lastError = e;
                        if (window.POI_DEBUG && console && console.debug) console.debug('Overpass candidate error', { url: candidate, err: e && (e.message || e) });
                        continue;
                    }
                }

                if (!response) {
                    try {
                        console.warn('No Overpass response from candidates. lastError:', lastError);
                        if (window.POI_DEBUG && console && console.debug) console.debug('Overpass candidates tried:', overpassApiCandidates);
                    } catch (e) {}
                }

                // If no overpass response, fall back to MySQL spatial search
                let rows = [];
                if (!response) {
                    try {
                        if (window.POI_DEBUG && console && console.debug) console.debug('Falling back to MySQL spatial search');
                        const bboxParam = [bounds.getSouth(), bounds.getWest(), bounds.getNorth(), bounds.getEast()].join(',');
                        const params2 = new URLSearchParams({ bbox: bboxParam, limit: 2000, mine: onlyMine ? 1 : 0, types: (serverTypes && serverTypes.length) ? serverTypes.join(',') : '' });
                        const urls = PoiMapManager.makeApiCandidates('api/locations/search.php', params2.toString());
                        let resp = null;
                        for (const u of urls) {
                            try {
                                resp = await fetch(u, { credentials: 'same-origin', signal: AbortSignal.timeout(10000) });
                                if (resp && resp.ok) break;
                                resp = null;
                            } catch (e) { resp = null; continue; }
                        }
                        if (resp && resp.ok) {
                            const j = await resp.json();
                            rows = Array.isArray(j.data) ? j.data : (Array.isArray(j) ? j : []);
                        } else {
                            rows = [];
                        }
                    } catch (e) {
                        console.warn('MySQL fallback failed', e);
                        rows = [];
                    }
                } else {
                    try {
                        const postJson = await response.json();
                        rows = Array.isArray(postJson) ? postJson : (postJson && Array.isArray(postJson.data) ? postJson.data : []);
                    } catch (e) {
                        // parsing failed - treat as empty and allow downstream handling
                        console.warn('Failed to parse Overpass response', e);
                        rows = [];
                    }
                }

                // Normalize each row to expected fields used by the frontend
                rows = rows.map(p => {
                    const poi = Object.assign({}, p);
                    if (typeof poi.latitude !== 'undefined' && typeof poi.longitude !== 'undefined') {
                        poi.lat = parseFloat(poi.latitude);
                        poi.lon = parseFloat(poi.longitude);
                    }
                    if (!poi.name && (poi.brand || poi.operator)) poi.name = poi.brand || poi.operator;
                    if (!poi.osm_type) {
                        if (poi.geom_type && typeof poi.geom_type === 'string' && poi.geom_type.length) poi.osm_type = poi.geom_type.charAt(0).toUpperCase();
                        else poi.osm_type = 'N';
                    }
                    if (typeof poi.osm_id === 'undefined' || poi.osm_id === null) poi.osm_id = (poi.id || poi._id || null);
                    return poi;
                });
                data = rows;

            }

            // Always merge logged-in user's MySQL POIs into the result set so
            // the user's app POIs are visible alongside Overpass results.
            try {
                if (window.CURRENT_USER_ID) {
                    const existingKeys = new Set();
                    (data || []).forEach(function(p) {
                        try {
                            const variants = PoiMapManager.canonicalVariants(p);
                            variants.forEach(v => { if (v) existingKeys.add(v); });
                        } catch (e) {}
                    });
                    const bboxParamUser = [bounds.getSouth(), bounds.getWest(), bounds.getNorth(), bounds.getEast()].join(',');
                    const paramsUser = new URLSearchParams({ bbox: bboxParamUser, limit: 2000, mine: 1, types: (serverTypes && serverTypes.length) ? serverTypes.join(',') : '' });
                    const urlsUser = PoiMapManager.makeApiCandidates('api/locations/search.php', paramsUser.toString());
                    for (const u of urlsUser) {
                        try {
                            const resp = await fetch(u, { credentials: 'same-origin', signal: AbortSignal.timeout(10000) });
                            if (!resp || !resp.ok) continue;
                            const j = await resp.json();
                            const rowsUser = Array.isArray(j.data) ? j.data : (Array.isArray(j) ? j : []);
                            const normalizedUser = rowsUser.map(p => {
                                const poi = Object.assign({}, p);
                                if (typeof poi.latitude !== 'undefined' && typeof poi.longitude !== 'undefined') {
                                    poi.lat = parseFloat(poi.latitude);
                                    poi.lon = parseFloat(poi.longitude);
                                }
                                poi.source = 'mysql';
                                if (typeof poi.id !== 'undefined' && poi.id !== null) {
                                    poi.osm_type = poi.osm_type || 'A';
                                    poi.osm_id = poi.osm_id || poi.id;
                                }
                                if (!poi.name && (poi.brand || poi.operator)) poi.name = poi.brand || poi.operator;
                                return poi;
                            });
                            for (const mu of normalizedUser) {
                                try {
                                    const variants = PoiMapManager.canonicalVariants(mu);
                                    const seen = variants.some(v => existingKeys.has(v));
                                    if (!seen) {
                                        variants.forEach(v => { if (v) existingKeys.add(v); });
                                        data.push(mu);
                                    }
                                } catch (e) { /* skip on error */ }
                            }
                            break; // stop after first successful user-rows response
                        } catch (e) { continue; }
                    }
                }
            } catch (e) { /* non-fatal */ }

            if (!data || !Array.isArray(data)) {
                this.setStatus('No POIs found or invalid data from server.', false, false);
                return;
            }

            // Log source counts (MySQL vs Overpass) for visibility and debugging
            try {
                const mysqlCount = data.reduce((acc, p) => {
                    try {
                        const bySource = p && (p.source === 'mysql' || p._is_app === true);
                        const byOwner = (window.CURRENT_USER_ID && p && (Number(p.user_id) === Number(window.CURRENT_USER_ID)));
                        return acc + ((bySource || byOwner) ? 1 : 0);
                    } catch (e) { return acc; }
                }, 0);
                const overpassCount = data.length - mysqlCount;
                // show initial server row counts at debug-level — final counts reported after dedupe
                try { console.debug(`POI fetch (server rows): total=${data.length}, mysql=${mysqlCount}, overpass=${overpassCount}`); } catch (e) {}
                // show a concise status message immediately; final status updated after marker creation
                this.setStatus(`${data.length} POIs loaded — MySQL: ${mysqlCount}, Overpass: ${overpassCount}`, false, true);
            } catch (e) {}

            this.markers.clearLayers();
            this.markerByOsm = {};
            this.poiByOsm = {};

            // Simple positional dedupe: map rounded lat/lon -> { source, marker }
            const seenPositions = new Map();

            data.forEach(poi => {
                // Validate coordinates
                const lat = Number(poi.lat);
                const lon = Number(poi.lon);
                if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

                // positional key rounded to 6 decimals (~10cm) to catch duplicates
                const posKey = `${lat.toFixed(6)}:${lon.toFixed(6)}`;
                const isAppPoi = this._isAppPoi(poi) || (poi.source === 'mysql');
                if (seenPositions.has(posKey)) {
                    try {
                        const existing = seenPositions.get(posKey);
                        // If we've already got an app POI here, skip additional markers
                        if (existing.source === 'mysql') return;
                        // If existing was Overpass but current is MySQL, replace it
                        if (existing.source !== 'mysql' && isAppPoi) {
                            try { this.markers.removeLayer(existing.marker); } catch (e) {}
                            // fall through to add the MySQL marker below
                        } else {
                            // both are non-app Overpass POIs, skip duplicate
                            return;
                        }
                    } catch (e) { /* continue on error */ }
                }

                const osmKey = PoiMapManager.getCanonicalKey(poi) || `${poi.osm_type || 'N'}${poi.osm_id}`;
                if (osmKey) this.poiByOsm[osmKey] = poi;

                // Avoid creating duplicate markers: if we already have a marker for any
                // canonical variant, skip adding a new one. If an existing marker is
                // from Overpass and current row is a MySQL/app POI, replace it.
                try {
                    const variants = PoiMapManager.canonicalVariants(poi);
                    let existingMarker = null; let existingKey = null;
                    for (const v of variants) {
                        if (v && this.markerByOsm[v]) { existingMarker = this.markerByOsm[v]; existingKey = v; break; }
                    }
                    const isAppPoi = this._isAppPoi(poi) || (poi.source === 'mysql');
                    if (existingMarker) {
                        const existingSource = existingMarker && existingMarker.options && existingMarker.options._pv_source ? existingMarker.options._pv_source : (existingMarker && existingMarker.options && existingMarker.options.osm_id ? 'overpass' : null);
                        if (existingSource === 'mysql' && !isAppPoi) {
                            // existing is app-record — keep it and skip adding this overpass row
                            return;
                        }
                        if (existingSource !== 'mysql' && isAppPoi) {
                            // existing is overpass — remove it and continue to add app POI
                            try { this.markers.removeLayer(existingMarker); } catch (e) {}
                            // also clear entries in markerByOsm for the existingKey
                            try { if (existingKey) delete this.markerByOsm[existingKey]; } catch (e) {}
                        } else {
                            // existing and current have same source (both overpass or both app) — skip duplicate
                            return;
                        }
                    }
                } catch (e) { /* ignore de-duplication errors */ }

                const icon = this._getIconForPoi(poi);
                let cat = null;
                try {
                    cat = this._getCategoryForPoi(poi);
                    if (window.POI_DEBUG && console && console.debug) console.debug('marker create', { osmKey, osm_id: poi.osm_id, logo: poi.logo, category: cat, iconUrl: icon && icon.options && icon.options.iconUrl });
                } catch (e) {}
                const markerOptions = {
                    osm_id: poi.osm_id,
                    osm_type: poi.osm_type,
                    _pv_category: cat,
                    _pv_source: poi.source || (this._isAppPoi(poi) ? 'mysql' : 'overpass')
                };

                // Use custom icons only for application (MySQL) POIs. Overpass results
                // get a simple colored standard marker per category/filter.
                if (this._isAppPoi(poi)) {
                    if (icon) markerOptions.icon = icon;
                } else {
                    const colored = this._getColoredMarkerIcon(poi);
                    if (colored) markerOptions.icon = colored;
                }

                const marker = L.marker([lat, lon], markerOptions);

                const displayName = PoiMapManager.poiDisplayName(poi);
                const popupContent = PoiPopupTemplate.createPopupHtmlString(poi, displayName);

                // Prevent Leaflet from panning the map to keep the popup in view.
                // Panning can trigger cluster/marker re-layout which closes popups
                // and temporarily removes marker DOM nodes (causing icons to disappear).
                // Allow previous popups to close when a new one opens
                marker.bindPopup(popupContent, { closeOnClick: true, autoClose: true, autoPan: false, keepInView: false });
                marker.on('click', () => { this.toggleSelection(marker); try { marker.openPopup(); } catch (e) {} });
                
                this.setMarkerSelected(marker, this.selectedOsm.has(osmKey));
                this.markerByOsm[osmKey] = marker;
                this.markers.addLayer(marker);
                // ensure marker index maps canonical variants to this marker
                try {
                    const variants = PoiMapManager.canonicalVariants(poi);
                    variants.forEach(v => { if (v) this.markerByOsm[v] = marker; });
                } catch (e) { if (osmKey) this.markerByOsm[osmKey] = marker; }
                // Try to asynchronously resolve icon URLs and apply to marker if needed
                try { this._ensureMarkerIcon(poi, marker); } catch (e) { /* swallow */ }

                // record positional key to avoid duplicate markers at same coords
                try {
                    if (typeof posKey !== 'undefined' && posKey) {
                        seenPositions.set(posKey, { source: (this._isAppPoi(poi) || (poi.source === 'mysql')) ? 'mysql' : 'overpass', marker });
                    }
                } catch (e) {}

                // If this POI was selected before the refresh, restore selection and popup
                try {
                    // selected keys may be stored either as raw ids or keyed with osm_type prefix
                    const wasSelected = preservedSelection.has(osmKey) || Array.from(preservedSelection).some(k => String(osmKey).endsWith(String(k)));
                    if (wasSelected) {
                        this.selectedOsm.add(osmKey);
                        this.setMarkerSelected(marker, true);
                        try { marker.openPopup(); } catch (e) {}
                    }
                } catch (e) {}
            });

            // Debug: report counts and marker DOM presence to help diagnose invisible markers
            try {
                if (window.POI_DEBUG && console && console.debug) {
                    console.debug('POI load summary', { requestedCount: data.length });
                    try {
                        const clusterLayers = (this.markers && this.markers.getLayers) ? this.markers.getLayers().length : null;
                        console.debug('Markers in cluster layer', clusterLayers);
                    } catch (e) {}
                    try {
                        const pane = document.querySelector('.leaflet-marker-pane');
                        console.debug('leaflet-marker-pane child count', pane ? pane.children.length : null);
                    } catch (e) {}
                    try {
                        const sample = (this.markers && this.markers.getLayers) ? this.markers.getLayers().slice(0,5) : [];
                        sample.forEach((m, idx) => {
                            const el = (m && m.getElement) ? m.getElement() : (m && m._icon ? m._icon : null);
                            console.debug('sample marker element', idx, !!el, el && el.outerHTML ? el.outerHTML.slice(0,200) : (el && el.tagName ? el.tagName : String(el)));
                        });
                    } catch (e) {}
                }
            } catch (e) {}

            // Final status (refresh after icons/markers created)
            const mysqlCountFinal = data.reduce((acc, p) => acc + ((p && (p.source === 'mysql' || p._is_app === true)) ? 1 : 0), 0);
            const overpassCountFinal = data.length - mysqlCountFinal;
            // Log final deduplicated counts and visible marker count
            try {
                const visibleMarkers = (this.markers && typeof this.markers.getLayers === 'function') ? this.markers.getLayers().length : null;
                console.info(`POI fetch (final): total=${data.length}, mysql=${mysqlCountFinal}, overpass=${overpassCountFinal}, markers=${visibleMarkers}`);
            } catch (e) {}
            this.setStatus(data.length > 0 ? `${data.length} POIs loaded — MySQL: ${mysqlCountFinal}, Overpass: ${overpassCountFinal}` : 'No POIs found in this area.', false, false);

        } catch (error) {
            console.error('Error fetching POIs:', error);
            this.setStatus(`Error: ${error.message}`, true, false);
        } finally {
            try { this.hideLoadingOverlay(); } catch (e) {}
        }
    }

    async doImportPoi(osmType, osmId) {
        // Prevent duplicate concurrent imports for the same osmId
        try { if (!this._importsInProgress) this._importsInProgress = new Set(); } catch (e) {}
        if (this._importsInProgress && this._importsInProgress.has(String(osmId))) {
            try { console.debug && console.debug('Import already in progress for', osmId); } catch (e) {}
            return;
        }
        this._importsInProgress.add(String(osmId));

        // Debug: log invocation
        try { console.debug('doImportPoi called', { osmType, osmId, csrf: !!window.CSRF_TOKEN, user: window.CURRENT_USER_ID || null }); } catch (e) {}
        // Guard: require a logged-in user for imports to ensure session cookie/CSRF are present
        if (!window.CURRENT_USER_ID) {
            try { this.setStatus('Please sign in to import POIs.', true, false); } catch (e) {}
            // cleanup import-in-progress marker if set
            try { if (this._importsInProgress) this._importsInProgress.delete(String(osmId)); } catch (e) {}
            return;
        }
        this.setStatus(`Importing ${osmType} ${osmId}...`, false, true);
        try {
            let base = window.APP_BASE || '/';
            if (base.charAt(base.length - 1) !== '/') base += '/';
            // Prefer the fast Overpass importer directly to avoid slow legacy fallbacks
            const candidates = [
                // Direct fast importer (optimized, expects form POST with osm_id/osm_ids)
                { url: `${base}api/locations/import_from_overpass_fast.php`, type: 'form' },
                // Prefer the API route (front-controller) as a secondary option
                { url: `${base}index.php/api/locations/import`, type: 'form' },
                { url: `${base}api/locations/import`, type: 'json' }
            ];
            let response = null;
            let result = null;
            let lastErr = null;
            // Ensure CSRF token is available for scripted imports
            try { await this._ensureCsrfToken(); } catch (e) {}
            if (!window.CSRF_TOKEN) {
                try { console.warn && console.warn('doImportPoi: CSRF token is empty — import may fail or be rejected by server'); } catch (e) {}
            }
            // ensure token for batch imports
            try { await this._ensureCsrfToken(); } catch (e) {}
            // ensure token for selected imports
            try { await this._ensureCsrfToken(); } catch (e) {}
            try { console.debug('Import candidates', candidates); } catch (e) {}
            for (const c of candidates) {
                try {
                    // Normalize osmType for server: accept single-letter codes from frontend
                    // overpass POIs may set `osm_type` to a single char like 'P'/'W'/'R'
                    let sendOsmType = String(osmType || '').toLowerCase();
                    if (sendOsmType.length === 1) {
                        switch (sendOsmType.toUpperCase()) {
                            case 'P': sendOsmType = 'node'; break; // point -> node
                            case 'W': sendOsmType = 'way'; break;
                            case 'R': sendOsmType = 'relation'; break;
                            default: sendOsmType = 'node';
                        }
                    }

                    // Add a fetch timeout via AbortController to avoid long hangs on unreachable candidates
                    const controller = new AbortController();
                    const timeoutMs = 30000; // 30s (increased to accommodate slow imports)
                    const timeout = setTimeout(() => controller.abort(), timeoutMs);
                    try {
                        if (c.type === 'form') {
                            const body = `osm_id=${encodeURIComponent(String(osmId))}&osm_type=${encodeURIComponent(String(sendOsmType))}&csrf_token=${encodeURIComponent(window.CSRF_TOKEN || '')}`;
                            try { if (window.POI_DEBUG && console && console.debug) console.debug('Import candidate try', { url: c.url, type: c.type, body: body.slice(0,200) }); } catch (e) {}
                            response = await fetch(c.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body, signal: controller.signal });
                        } else {
                            const jsonBody = JSON.stringify({ osm_type: sendOsmType, osm_id: osmId });
                            try { if (window.POI_DEBUG && console && console.debug) console.debug('Import candidate try', { url: c.url, type: c.type, body: jsonBody }); } catch (e) {}
                            response = await fetch(c.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: jsonBody, signal: controller.signal });
                        }
                    } finally {
                        clearTimeout(timeout);
                    }
                    if (!response.ok) {
                        let bodyText = '';
                        try { bodyText = await response.text(); } catch (e) { bodyText = ''; }
                        try { console.debug('Import HTTP error', { url: c.url, status: response.status, body: bodyText.slice ? bodyText.slice(0,200) : bodyText }); } catch (e) {}
                        try { if (window.POI_DEBUG && console && console.debug) console.debug('Import candidate response', { url: c.url, status: response.status, body: bodyText.slice ? bodyText.slice(0,200) : bodyText }); } catch (e) {}
                        lastErr = new Error(`HTTP ${response.status} from ${c.url}`);
                        response = null;
                        continue;
                    }
                    // attempt JSON parse
                    try {
                        result = await response.json();
                    } catch (e) {
                        const txt = await response.text();
                        throw new Error(`Invalid JSON from ${c.url}: ${txt.slice(0,200)}`);
                    }
                    break;
                } catch (e) {
                    lastErr = e;
                    response = null;
                    continue;
                }
            }
            if (!result) throw lastErr || new Error('Import failed (no response)');
            try { console.debug('Import result', result); } catch (e) {}
            try { if (window.POI_DEBUG && console && console.debug) console.debug('Import response result', result); } catch (e) {}
            // Accept both legacy `success` and newer `ok` responses
            const ok = (result && (result.success === true || result.ok === true));
            if (ok) {
                this.setStatus(`Successfully imported ${result.name || osmId}.`, false, false);
                // Try to fetch the newly-imported row and add/update it on the map with its logo
                try {
                    // import endpoints return results array when doing fast import
                    const found = Array.isArray(result.results) ? result.results.find(r => String(r.osm_id) === String(osmId) && (r.ok === true || r.ok === '1')) : null;
                    const newId = found && (found.id || found.inserted_id || found.insertedId || found.ID) ? (found.id || found.inserted_id || found.insertedId || found.ID) : null;
                    if (newId) {
                        // fetch single row by id from search API
                        let base = window.APP_BASE || '/'; if (base.charAt(base.length - 1) !== '/') base += '/';
                        const urls = [`${base}api/locations/search.php?id=${encodeURIComponent(newId)}`, `${base}index.php/api/locations/search?id=${encodeURIComponent(newId)}`];
                        let row = null;
                        for (const u of urls) {
                            try {
                                const resp = await fetch(u, { credentials: 'same-origin' });
                                if (!resp.ok) continue;
                                const j = await resp.json();
                                // search.php returns { page, per_page, data: [rows] }
                                if (j && Array.isArray(j.data) && j.data.length) { row = j.data[0]; }
                                else if (Array.isArray(j) && j.length) { row = j[0]; }
                                if (row) break;
                            } catch (e) { continue; }
                        }
                        if (row) {
                            try { this._addOrUpdateAppPoi(row); } catch (e) { console.debug('Failed to add/update imported POI on map', e); }
                        }
                    }
                } catch (e) { console.debug('post-import map update failed', e); }
                // Also refresh full list for sidebars
                this.fetchAndRenderFullPoiList();
                return { ok: true, result };
            } else {
                // Server returned a non-ok response; surface its message to the user
                try {
                    console.error('Import failed (server response full):', result);
                } catch (e) {}
                const first = Array.isArray(result.results) && result.results.length ? result.results[0] : null;
                const msg = (first && (first.msg || first.message)) ? (first.msg || first.message) : ((result && (result.error || result.message)) ? (result.error || result.message) : 'Unknown error');
                try { this.setStatus(`Import failed: ${msg}`, true, false); } catch (e) {}
                return { ok: false, error: msg, details: result };
            }
        } catch (error) {
            console.error('Import failed:', error);
            try { this.setStatus(`Import failed: ${error.message}`, true, false); } catch (e) {}
            return { ok: false, error };
        } finally {
            // remove in-progress marker
            try { if (this._importsInProgress) this._importsInProgress.delete(String(osmId)); } catch (e) {}
            try { console.debug('doImportPoi finally cleanup'); } catch (e) {}
        }
    }

    async doBatchImportVisible() {
        const visiblePois = [];
        this.markers.eachLayer(marker => {
            if (this.map.getBounds().contains(marker.getLatLng())) {
                visiblePois.push({
                    osm_type: marker.options.osm_type,
                    osm_id: marker.options.osm_id
                });
            }
        });

        if (visiblePois.length === 0) {
            alert('No POIs are visible on the map to import.');
            return;
        }

        if (!confirm(`Import ${visiblePois.length} visible POIs?`)) return;

        this.setStatus(`Importing ${visiblePois.length} POIs...`, false, true);
        try {
            let base = window.APP_BASE || '/';
            if (base.charAt(base.length - 1) !== '/') base += '/';
            const candidates = [
                { url: `${base}index.php/api/locations/import_batch`, type: 'form' },
                { url: `${base}index.php/api/locations/import`, type: 'form' },
                { url: `${base}api/locations/import`, type: 'json' },
                { url: `${base}index.php/locations/import`, type: 'form' },
                { url: `${base}api/import.php`, type: 'json' }
            ];
            let response = null;
            let result = null;
            let lastErr = null;
            for (const c of candidates) {
                try {
                    if (c.type === 'form') {
                        const body = 'data=' + encodeURIComponent(JSON.stringify(visiblePois)) + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '');
                        response = await fetch(c.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
                    } else {
                        response = await fetch(c.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(visiblePois) });
                    }
                    if (!response.ok) { lastErr = new Error(`HTTP ${response.status} from ${c.url}`); response = null; continue; }
                    try { result = await response.json(); } catch (e) { const txt = await response.text(); throw new Error(`Invalid JSON from ${c.url}: ${txt.slice(0,200)}`); }
                    break;
                } catch (e) { lastErr = e; response = null; continue; }
            }
            if (!result) throw lastErr || new Error('Batch import failed (no response)');
            if (result.success) {
                this.setStatus(`Successfully imported ${result.imported_count} of ${visiblePois.length} POIs.`, false, false);
                this.fetchAndRenderFullPoiList();
            } else {
                throw new Error(result.message || 'Batch import failed');
            }
        } catch (error) {
            console.error('Batch import failed:', error);
            this.setStatus(`Batch import failed: ${error.message}`, true, false);
        }
    }

    async doImportSelected() {
        const selected = Array.from(this.selectedOsm).map(key => {
            const poi = this.poiByOsm[key];
            return { osm_type: poi.osm_type, osm_id: poi.osm_id };
        });

        if (selected.length === 0) {
            alert('No POIs selected to import.');
            return;
        }

        this.setStatus(`Importing ${selected.length} selected POIs...`, false, true);
        try {
            let base = window.APP_BASE || '/';
            if (base.charAt(base.length - 1) !== '/') base += '/';
            const candidates = [
                { url: `${base}index.php/api/locations/import_batch`, type: 'form' },
                { url: `${base}index.php/api/locations/import`, type: 'form' },
                { url: `${base}api/locations/import`, type: 'json' },
                { url: `${base}index.php/locations/import`, type: 'form' },
                { url: `${base}api/import.php`, type: 'json' }
            ];
            let response = null;
            let result = null;
            let lastErr = null;
            for (const c of candidates) {
                try {
                    if (c.type === 'form') {
                        const body = 'data=' + encodeURIComponent(JSON.stringify(selected)) + '&csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '');
                        response = await fetch(c.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
                    } else {
                        response = await fetch(c.url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(selected) });
                    }
                    if (!response.ok) { lastErr = new Error(`HTTP ${response.status} from ${c.url}`); response = null; continue; }
                    try { result = await response.json(); } catch (e) { const txt = await response.text(); throw new Error(`Invalid JSON from ${c.url}: ${txt.slice(0,200)}`); }
                    break;
                } catch (e) { lastErr = e; response = null; continue; }
            }
            if (!result) throw lastErr || new Error('Import failed (no response)');
            if (result.success) {
                this.setStatus(`Successfully imported ${result.imported_count} POIs.`, false, false);
                this.selectedOsm.clear();
                this.updateSelectedUI();
                this.fetchAndRenderFullPoiList();
            } else {
                throw new Error(result.message || 'Import failed');
            }
        } catch (error) {
            console.error('Import failed:', error);
            this.setStatus(`Import failed: ${error.message}`, true, false);
        }
    }

    async fetchAndRenderFullPoiList(opts = {}) {
        const listContainer = document.getElementById('poi-list-all');
        if (!listContainer) return;

        const search = document.getElementById('poi-search')?.value || '';
        const onlyMine = document.getElementById('poi-only-mine')?.checked || false;

        // Show loading state safely
        listContainer.innerHTML = '';
        const loadingMsg = document.createElement('p');
        loadingMsg.textContent = 'Loading...';
        loadingMsg.className = 'text-muted';
        listContainer.appendChild(loadingMsg);

        try {
            const params = new URLSearchParams({
                action: 'list_all',
                search: search,
                only_mine: onlyMine ? '1' : '0'
            });
            let base = window.APP_BASE || '/';
            if (base.charAt(base.length - 1) !== '/') base += '/';
            const response = await fetch(`${base}api/locations/search.php?${params.toString()}`);
            if (!response.ok) throw new Error(`HTTP error ${response.status}`);
            const respJson = await response.json();
            // Normalize responses: API may return an array or an object { page, per_page, data: [...] }
            const rows = Array.isArray(respJson) ? respJson : (respJson && Array.isArray(respJson.data) ? respJson.data : []);
            this.renderPoiList(rows);
        } catch (error) {
            console.error('Error fetching POI list:', error);
            const errorElement = PoiPopupTemplate.createErrorElement(error.message);
            listContainer.innerHTML = '';
            listContainer.appendChild(errorElement);
        }
    }

    renderPoiList(appRows) {
        const listContainer = document.getElementById('poi-list-all');
        if (!listContainer) return;

        // Clear existing content safely
        listContainer.innerHTML = '';

        if (!appRows || appRows.length === 0) {
            const emptyMsg = document.createElement('p');
            emptyMsg.textContent = 'No POIs found in the database matching your criteria.';
            emptyMsg.className = 'text-muted';
            listContainer.appendChild(emptyMsg);
            return;
        }

        const ul = document.createElement('ul');
        ul.className = 'list-group';

        appRows.forEach(poi => {
            const displayName = PoiMapManager.poiDisplayName(poi);
            const li = PoiPopupTemplate.createListItem(poi, displayName);
            ul.appendChild(li);
        });

        listContainer.appendChild(ul);

        // Bind click events to all new list links
        ul.querySelectorAll('.poi-list-item-link').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                const lat = parseFloat(link.dataset.lat);
                const lon = parseFloat(link.dataset.lon);
                if (!isNaN(lat) && !isNaN(lon)) {
                    this.map.setView([lat, lon], 15);
                }
            });
        });
    }

    bindUIEvents() {
        // Bind all buttons and inputs
        const loadBtn = document.getElementById('load-pois-btn');
        if (loadBtn) {
            loadBtn.addEventListener('click', (ev) => {
                ev.preventDefault();
                loadBtn.disabled = true;
                loadBtn.textContent = 'Loading…';
                this.hasLoadedOnce = true;
                // Force an Overpass lookup to display OSM POIs
                const p = this.fetchAndPlot({ force: true, forceOverpass: true });
                if (p && p.finally) {
                    p.finally(() => {
                        loadBtn.disabled = false;
                        loadBtn.textContent = 'Reload POIs';
                    });
                } else {
                    setTimeout(() => {
                        loadBtn.disabled = false;
                        loadBtn.textContent = 'Reload POIs';
                    }, 800);
                }
            });
        }

        // Helper to enable/disable the Load POIs button depending on active filters
        const updateLoadBtnState = () => {
            try {
                const loadBtn = document.getElementById('load-pois-btn');
                if (!loadBtn) return;
                // Active if any checkbox selected, search text present, or 'only mine' checked
                const anyChecked = !!document.querySelector('#poi-filter input[type=checkbox]:checked');
                const searchVal = (document.getElementById('poi-search') && document.getElementById('poi-search').value) || '';
                const onlyMine = (document.getElementById('poi-only-mine') && document.getElementById('poi-only-mine').checked);
                const active = anyChecked || (searchVal && String(searchVal).trim().length > 0) || !!onlyMine;
                loadBtn.disabled = !active;
            } catch (e) {}
        };

        // Initialize state and wire up filter changes to update the button state
        try { updateLoadBtnState(); } catch (e) {}
        document.addEventListener('change', ev => {
            if (ev.target && (ev.target.closest && ev.target.closest('#poi-filter') || ev.target.id === 'poi-search' || ev.target.id === 'poi-only-mine')) updateLoadBtnState();
        });

        const searchInput = document.getElementById('poi-search');
        const searchBtn = document.getElementById('poi-search-btn');
        const applyFilterHandler = (ev) => {
            ev.preventDefault();
            this.fetchAndPlot({ force: true });
            this.fetchAndRenderFullPoiList();
        };
        if (searchBtn) searchBtn.addEventListener('click', applyFilterHandler);
        if (searchInput) searchInput.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') applyFilterHandler(ev);
        });

        const importSelBtn = document.getElementById('import-selected-pois');
        if (importSelBtn) importSelBtn.addEventListener('click', (ev) => {
            ev.preventDefault();
            this.doImportSelected();
        });

        const resetBtn = document.getElementById('reset-poi-filters');
        if (resetBtn) {
            resetBtn.addEventListener('click', (ev) => {
                ev.preventDefault();
                localStorage.removeItem('poi_selected_categories');
                document.querySelectorAll('#poi-filter .poi-filter-btn, #poi-filter .poi-filter-item').forEach(btn => {
                    try {
                        if (btn.setAttribute) btn.setAttribute('aria-pressed', 'false');
                        const checkbox = btn.querySelector && btn.querySelector('input[type=checkbox]');
                        if (checkbox) checkbox.checked = false;
                    } catch(e){}
                });
                this.updateSelectedUI();
                this.hasLoadedOnce = true;
                // Explicitly request MySQL-only results after resetting filters
                this.fetchAndPlot({ force: true, onlyMysql: true });
            });
        }

        document.querySelectorAll('#poi-filter .poi-filter-btn, #poi-filter .poi-filter-item').forEach(btn => {
            btn.addEventListener('click', (ev) => {
                try {
                    const isLabel = btn.classList && btn.classList.contains('poi-filter-item');
                    if (!isLabel) ev.preventDefault();
                    const isPressed = btn.getAttribute && btn.getAttribute('aria-pressed') === 'true';
                    if (btn.setAttribute) btn.setAttribute('aria-pressed', String(!isPressed));
                    const checkbox = btn.querySelector && btn.querySelector('input[type=checkbox]');
                    if (checkbox) {
                        if (isLabel) {
                            // label click toggles checkbox natively; debounce fetch after a short delay
                            setTimeout(() => {
                                this.hasLoadedOnce = true;
                                if (this._filterDebounce) clearTimeout(this._filterDebounce);
                                this._filterDebounce = setTimeout(() => this.fetchAndPlot({ force: true }), 120);
                            }, 10);
                            return;
                        } else {
                            checkbox.checked = !isPressed;
                        }
                    }
                    this.hasLoadedOnce = true;
                    if (this._filterDebounce) clearTimeout(this._filterDebounce);
                    this._filterDebounce = setTimeout(() => this.fetchAndPlot({ force: true }), 120);
                } catch(e){}
            });
        });

        const mineChk = document.getElementById('poi-only-mine');
        if (mineChk) {
            mineChk.addEventListener('change', () => {
                this.hasLoadedOnce = true;
                // Always trigger a forced reload when the 'Only my POIs' state changes.
                // This ensures the server is queried for the current map bbox so that
                // the user's POIs for the visible area (any country) are returned,
                // rather than reusing a previously-loaded dataset (which may be
                // limited to Europe).
                try {
                    // Clear any client-side filters/cached markers so fetch starts from a clean state
                    this._clearOnlyMineFilter();
                } catch (e) {}
                // Force network fetch which will call the MySQL search endpoint when checked
                // and overpass otherwise.
                this.fetchAndPlot({ force: true });
                this.updateSelectedUI();
            });
        }

        // Event delegation for import buttons in popups
        this.map.on('popupopen', (e) => {
            const popupNode = e.popup.getElement();
            try { console.debug && console.debug('popupopen fired', { popup: e.popup, popupNodeExists: !!popupNode }); } catch (e) {}
            try { if (window.POI_DEBUG && console && console.debug) console.debug('Popup opened HTML:', popupNode && popupNode.innerHTML ? popupNode.innerHTML.trim().slice(0,500) : '<no-html>'); } catch (e) {}
            const importBtn = popupNode.querySelector('.poi-import-btn');
            try { console.debug && console.debug('Found importBtn?', !!importBtn); } catch (e) {}
            if (importBtn && !importBtn.dataset.pvBound) {
                importBtn.dataset.pvBound = '1';
                importBtn.addEventListener('click', (ev) => {
                    ev.preventDefault();
                    try { ev.stopPropagation(); } catch (e) {}
                    const btn = ev.currentTarget || ev.target;
                    const osmId = btn.dataset.osmId;
                    const osmType = btn.dataset.osmType;
                    try { console.debug && console.debug('importBtn clicked', { osmId, osmType, btn }); } catch (e) {}
                    if (!(osmId && osmType)) return;

                    // Inline spinner/button busy state
                    try {
                        btn.disabled = true;
                        const spinner = document.createElement('span');
                        spinner.className = 'pv-inline-spinner';
                        spinner.style.cssText = 'display:inline-block;margin-left:6px;width:14px;height:14px;border-radius:50%;border:2px solid rgba(255,255,255,0.6);border-top-color:rgba(255,255,255,1);animation:pv-spin 0.8s linear infinite;vertical-align:middle;';
                        spinner.dataset.pvSpinner = '1';
                        btn.appendChild(spinner);
                        // visual pulse while import is in progress
                        try { btn.classList.add && btn.classList.add('pv-import-pulse'); } catch (e) {}
                    } catch (e) {}

                    // Call import but DO NOT close the popup; animate button on result
                    let success = false;
                    const p = this.doImportPoi(osmType, osmId);
                    if (p && p.then) {
                        p.then(res => {
                            success = !!(res && res.ok);
                            if (success) {
                                try { this.animateImportButtonSuccess(btn); } catch (e) {}
                            } else {
                                try { this.animateImportButtonFailure(btn); } catch (e) {}
                            }
                        }).catch(() => {
                            success = false;
                            try { this.animateImportButtonFailure(btn); } catch (e) {}
                        }).finally(() => {
                            try {
                                const s = btn.querySelector('[data-pv-spinner]');
                                if (s) s.remove();
                                try { btn.classList.remove && btn.classList.remove('pv-import-pulse'); } catch (e) {}
                                if (!success) {
                                    btn.disabled = false;
                                } else {
                                    // leave disabled in imported state
                                    btn.disabled = true;
                                }
                            } catch (e) {}
                        });
                    } else {
                        // best-effort restore after timeout
                        setTimeout(() => {
                            try {
                                btn.disabled = false;
                                const s = btn.querySelector('[data-pv-spinner]');
                                if (s) s.remove();
                                try { btn.classList.remove && btn.classList.remove('pv-import-pulse'); } catch (e) {}
                            } catch (e) {}
                        }, 16000);
                    }
                });
            }
        });

        // Minimal spinner CSS for inline spinner
        if (!document.getElementById('pv-inline-spinner-style')) {
            const style = document.createElement('style');
            style.id = 'pv-inline-spinner-style';
            style.appendChild(document.createTextNode('@keyframes pv-spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}')); 
            style.appendChild(document.createTextNode('\n.pv-import-success{background-color:#28a745;color:#fff;transform:scale(1.05);transition:transform 0.18s ease,background-color 0.18s ease;}'));
            style.appendChild(document.createTextNode('\n.pv-import-success .pv-inline-spinner{display:none}'));
            style.appendChild(document.createTextNode('\n.pv-import-fail{animation:pv-shake 0.6s ease; background-color:#c0392b;color:#fff;}'));
            style.appendChild(document.createTextNode('\n@keyframes pv-shake{0%{transform:translateX(0)}20%{transform:translateX(-6px)}40%{transform:translateX(6px)}60%{transform:translateX(-4px)}80%{transform:translateX(4px)}100%{transform:translateX(0)}}'));
            document.head.appendChild(style);
        }
    }

    renderFilterSwatches() {
        document.querySelectorAll('#poi-filter .poi-filter-btn, #poi-filter .poi-filter-item').forEach(btn => {
            const cat = btn.getAttribute && btn.getAttribute('data-category');
            if (!cat || btn.querySelector('.filter-icon') || btn.querySelector('.filter-swatch')) return;

            const iconFile = PoiMapManager.CATEGORY_ICONS[cat];

            if (iconFile) {
                const img = document.createElement('img');
                img.className = 'filter-icon';
                img.style.cssText = 'width:20px; height:20px; margin-right:6px; vertical-align:middle;';
                // Use ICONS_BASE instead of APP_BASE + 'icons/'
                const iconsBase = (window.ICONS_BASE && String(window.ICONS_BASE)) || ((window.APP_BASE || '/') + '/assets/icons/');
                img.src = iconsBase.charAt(iconsBase.length - 1) === '/' ? iconsBase + encodeURIComponent(iconFile) : iconsBase + '/' + encodeURIComponent(iconFile);
                img.onerror = () => {
                    img.remove();
                    this.addSwatch(btn, cat);
                };
                btn.insertBefore(img, btn.firstChild);
            } else {
                this.addSwatch(btn, cat);
            }
        });
    }

    _getIconForPoi(poi) {
        try {
            const cat = this._getCategoryForPoi(poi) || 'poi';
            const isAppPoi = (this._isAppPoi(poi) || (poi && String(poi.source) === 'mysql'));
            let color;
            if (!isAppPoi) {
                // For Overpass/external POIs prefer a legend category color when
                // the category predicate matches (so hotels/supermarket/etc use
                // the same colors as the legend). Fall back to hashed color.
                const detectedCat = this._getCategoryForPoi(poi);
                if (detectedCat && PoiMapManager.CATEGORY_COLORS[detectedCat]) {
                    color = PoiMapManager.CATEGORY_COLORS[detectedCat];
                } else {
                    color = PoiMapManager._colorForOsm(poi && poi.osm_id ? poi.osm_id : (poi && poi.id ? poi.id : '0'));
                }
            } else {
                color = PoiMapManager.CATEGORY_COLORS[cat] || '#888';
            }
            // Use custom logos/icons for application POIs (MySQL/internal) and a colored div for others.
            if (!isAppPoi) {
                // Overpass / external POIs: compact colored dot. Visual sizing and box-shadow live in CSS.
                // Wrap the marker in an outer element and set the CSS variable
                // on the root so it's easy to query and CSS can reference it.
                const html = `<div class="pv-div-icon-overpass-root" style="--pv-marker-bg:${color}"><div class="pv-marker pv-marker-overpass pv-marker--sm" style="--pv-marker-bg:${color}"></div></div>`;
                // Add a root class so the outer icon element can be selected directly
                return L.divIcon({ className: 'pv-div-icon pv-div-icon-overpass pv-div-icon-overpass-root', html, iconSize: [20, 20], iconAnchor: [10, 10], popupAnchor: [0, -10] });
            }
            // If the POI has a custom logo provided (MySQL/app POI), try to use it as an icon.
            if (isAppPoi && poi && poi.logo) {
                try {
                    const logoFile = String(poi.logo).trim();
                    let url = logoFile;
                    // If logo looks like a plain filename, resolve against ICONS_BASE or APP_BASE/assets/icons/
                    if (!/^https?:\/\//i.test(logoFile) && !logoFile.startsWith('/')) {
                        const iconsBase = (window.ICONS_BASE && String(window.ICONS_BASE)) || ((window.APP_BASE || '/') + '/assets/icons/');
                        url = iconsBase.charAt(iconsBase.length - 1) === '/' ? iconsBase + encodeURIComponent(logoFile) : iconsBase + '/' + encodeURIComponent(logoFile);
                    }
                    return L.icon({ iconUrl: url, iconSize: [32, 32], iconAnchor: [16, 32], popupAnchor: [0, -28] });
                } catch (e) {}
            }
            // Fallback for MySQL: use category icon file if available
            try {
                if (isAppPoi) {
                    const iconFile = PoiMapManager.CATEGORY_ICONS[cat];
                    if (iconFile) {
                        const iconsBase = (window.ICONS_BASE && String(window.ICONS_BASE)) || ((window.APP_BASE || '/') + '/assets/icons/');
                        const url = iconsBase.charAt(iconsBase.length - 1) === '/' ? iconsBase + encodeURIComponent(iconFile) : iconsBase + '/' + encodeURIComponent(iconFile);
                        return L.icon({ iconUrl: url, iconSize: [28, 28], iconAnchor: [14, 28], popupAnchor: [0, -24] });
                    }
                }
            } catch (e) {}

            // Default: small colored div icon for non-MySQL sources
            const html = `<div class="pv-marker pv-marker-fallback pv-marker--xs" style="--pv-marker-bg:${color}"></div>`;
            return L.divIcon({ className: 'pv-div-icon pv-div-icon-fallback', html, iconSize: [16, 16], iconAnchor: [8, 8], popupAnchor: [0, -8] });
        } catch (e) {
            return null;
        }
    }

    _ensureMarkerIcon(poi, marker) {
        try {
            // Only set image icons for application (MySQL) POIs. Overpass results keep colored markers.
            if (!this._isAppPoi(poi)) return;
            // If POI has a logo and marker currently has no image icon, try to set it.
            if (poi && poi.logo) {
                // If marker already uses icon with iconUrl, nothing to do
                const current = marker.options && marker.options.icon;
                if (current && current.options && current.options.iconUrl) return;
                // Create icon and set it
                const icon = this._getIconForPoi(poi);
                if (icon) marker.setIcon(icon);
            }
        } catch (e) {}
    }

    addSwatch(btn, cat) {
        const color = PoiMapManager.CATEGORY_COLORS[cat];
        if (!color) return;
        const swatch = document.createElement('span');
        swatch.className = 'filter-swatch';
        swatch.style.cssText = `display:inline-block; width:12px; height:12px; border-radius:3px; margin-right:6px; vertical-align:middle; background:${color};`;
        btn.insertBefore(swatch, btn.firstChild);
    }

    animateImportButtonSuccess(btn) {
        try {
            if (!btn) return;
            // Add success class and update text
            btn.classList.remove('pv-import-fail');
            btn.classList.add('pv-import-success');
            const origText = btn.getAttribute('data-orig-text') || btn.textContent;
            btn.setAttribute('data-orig-text', origText);
            btn.textContent = (window.I18N?.pois?.imported_label) || 'Imported';
            // small checkmark appended
            const check = document.createElement('span');
            check.className = 'pv-import-check';
            check.style.cssText = 'margin-left:8px;font-weight:700';
            check.textContent = '✓';
            btn.appendChild(check);
            // subtle pulse
            setTimeout(() => { try { btn.classList.remove('pv-import-success'); } catch (e) {} }, 900);
        } catch (e) { console.warn('animateImportButtonSuccess failed', e); }
    }

    animateImportButtonFailure(btn) {
        try {
            if (!btn) return;
            btn.classList.remove('pv-import-success');
            btn.classList.add('pv-import-fail');
            const orig = btn.getAttribute('data-orig-text') || btn.textContent;
            // briefly show 'Failed' then revert
            btn.textContent = (window.I18N?.pois?.import_failed) || 'Import failed';
            setTimeout(() => {
                try { btn.classList.remove('pv-import-fail'); btn.textContent = orig; } catch (e) {}
            }, 1400);
        } catch (e) { console.warn('animateImportButtonFailure failed', e); }
    }

    getSelectedCategories() {
        const sel = [];
        try {
            const checks = document.querySelectorAll('#poi-filter input.poi-filter-checkbox:checked');
            if (checks && checks.length) {
                checks.forEach(cb => { if (cb.value) sel.push(cb.value); });
            } else {
                document.querySelectorAll('#poi-filter .poi-filter-btn, #poi-filter .poi-filter-item').forEach(btn => {
                    try {
                        if (btn.getAttribute && btn.getAttribute('aria-pressed') === 'true') {
                            const cat = btn.getAttribute('data-category');
                            if (cat) sel.push(cat);
                        } else {
                            const cb = btn.querySelector && btn.querySelector('input[type=checkbox]');
                            if (cb && cb.checked && cb.value) sel.push(cb.value);
                        }
                    } catch(e){}
                });
            }
        } catch(e){}
        try {
            localStorage.setItem('poi_selected_categories', JSON.stringify(sel));
        } catch (e) {}
        return sel;
    }

    restoreSelectedCategories() {
        try {
            const raw = localStorage.getItem('poi_selected_categories');
            if (!raw) return;
            const arr = JSON.parse(raw);
            if (!Array.isArray(arr)) return;
            arr.forEach(cat => {
                try {
                    const btn = document.querySelector('#poi-filter .poi-filter-btn[data-category="' + cat + '"]');
                    if (btn) btn.setAttribute('aria-pressed', 'true');
                    const cb = document.querySelector('#poi-filter input[type=checkbox][value="' + cat + '"]');
                    if (cb) cb.checked = true;
                    const lbl = document.querySelector('#poi-filter .poi-filter-item[data-category="' + cat + '"]');
                    if (lbl) {
                        try { const lcb = lbl.querySelector('input[type=checkbox]'); if (lcb) lcb.checked = true; } catch(e){}
                    }
                } catch(e){}
            });
        } catch (e) {}
    }

    // ... other methods like doImportPoi, doBatchImportVisible, etc.

    static _buildPredicatesFromMap(map) {
        const out = {};
        for (const cat in map) {
            const rules = Array.isArray(map[cat]) ? map[cat] : [];
            out[cat] = (tags, name, poiType) => {
                if (!tags && !name && !poiType) return false;
                try {
                    if (tags) {
                        for (const r of rules) {
                            const k = r && r.k;
                            const v = r && r.v;
                            if (typeof k === 'undefined' || typeof v === 'undefined') continue;
                            if (typeof tags[k] !== 'undefined' && String(tags[k]) === String(v)) return true;
                        }
                    }
                    return false;
                } catch (e) {
                    return false;
                }
            };
        }
        return out;
    }
}

// Entry and initialization moved to `assets/js/poi-entry.js` which imports this module.
