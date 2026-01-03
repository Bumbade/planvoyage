export default function initPoiFilters() {
    try {
        // Neue Container für die vier Seiten
        const topContainer = document.getElementById('poi-filters-top');
        const leftContainer = document.getElementById('poi-filters-left');
        const rightContainer = document.getElementById('poi-filters-right');
        const bottomContainer = document.getElementById('poi-filters-bottom');
        
        if (!topContainer || !leftContainer || !rightContainer || !bottomContainer) {
            console.warn('POI filter containers not found');
            return;
        }
        
        const filters = window.POI_FILTERS || {};
        if (Object.keys(filters).length === 0) {
            console.warn('POI_FILTERS not loaded or empty');
            return;
        }
        
        const allowed = (window.POI_ALLOWED_CATEGORIES && window.POI_ALLOWED_CATEGORIES.length)
            ? window.POI_ALLOWED_CATEGORIES
            : Object.keys(filters);

        // Ensure we include any server-provided filters even when the allow-list
        // (e.g. from `get_poi_categories`) doesn't mention them. This prevents
        // missing buttons when new categories were added server-side.
        const allFilterKeys = Object.keys(filters);
        for (const fk of allFilterKeys) {
            if (allowed.indexOf(fk) === -1) allowed.push(fk);
        }
        
        const labels = (window.I18N && (window.I18N.pois_types || window.I18N.pois)) || {};
        
        // Clear all containers
        topContainer.innerHTML = '';
        leftContainer.innerHTML = '';
        rightContainer.innerHTML = '';
        bottomContainer.innerHTML = '';

        // Map category keys to icon filenames (fallback to generic poi.png)
        const ICON_MAP = {
            hotel: 'hotel.png',
            attraction: 'Attractions.png',
            tourist_info: 'TouristInfo.png',
            food: 'food.png',
            nightlife: 'nightlife.png',
            gas_stations: 'gas_station.png',
            charging_station: 'charging.png',
            parking: 'Parking.png',
            bank: 'bank.png',
            healthcare: 'Pharmacy.png',
            fitness: 'Fitness.png',
            laundry: 'Laundry.png',
            supermarket: 'supermarket.png',
            tobacco: 'tobaccoVape.png',
            cannabis: 'Cannabis.png',
            transport: 'Transportation.png',
            dump_station: 'dump_station.png',
            campgrounds: 'campground.png',
            natureparks: 'national_park.png'
        };

        const iconsBase = (window.ICONS_BASE || (window.APP_BASE ? (window.APP_BASE + '/assets/icons/') : '/src/assets/icons/'));

        // Define groups and desired order (use id + fallback title)
        const GROUPS = [
            { id: 'tourism', title: 'Tourismus & Freizeit', keys: ['hotel','attraction','tourist_info','campgrounds','natureparks'] },
            { id: 'gastronomy', title: 'Gastronomie & Ausgehen', keys: ['food','nightlife'] },
            { id: 'mobility', title: 'Mobilität & Infrastruktur', keys: ['transport','parking','gas_stations','charging_station','dump_station'] },
            { id: 'services', title: 'Versorgung & Dienstleistungen', keys: ['supermarket','bank','healthcare','laundry'] },
            { id: 'sport', title: 'Sport & Gesundheit', keys: ['fitness'] },
            { id: 'specialty', title: 'Spezialhandel', keys: ['tobacco','cannabis'] }
        ];

        const rendered = new Set();

        function renderFilterItem(key) {
            if (!filters[key]) return null;
            // Create label wrapper (acts as button container)
            const label = document.createElement('label');
            label.className = 'poi-filter-item';
            label.setAttribute('data-category', key);

            // Create hidden checkbox
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'poi-filter-checkbox';
            checkbox.value = key;
            checkbox.setAttribute('aria-label', labels[key] || key);
            label.appendChild(checkbox);

            // Label text
            const labelText = labels[key] || (window.I18N && window.I18N.pois && window.I18N.pois[key]) || key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' ');

            // Create the visible label span (must be the immediate sibling of the input)
            const span = document.createElement('span');
            span.className = 'poi-filter-label';

            // Icon (placed inside the label span so input + .poi-filter-label adjacency is preserved)
            const iconFile = ICON_MAP[key] || 'poi.png';
            const img = document.createElement('img');
            img.className = 'filter-icon';
            img.src = iconsBase + iconFile;
            img.alt = labelText + ' icon';
            span.appendChild(img);

            // Text
            const textNode = document.createTextNode(labelText);
            span.appendChild(textNode);

            label.appendChild(span);

            return label;
        }

        // Render a flat list of filters (no grouping) so CSS grid can arrange them into columns
        // Preserve the original GROUPS ordering by flattening group keys first
        const orderedKeys = [];
        GROUPS.forEach(g => {
            (g.keys || []).forEach(k => { if (allowed.indexOf(k) !== -1 && orderedKeys.indexOf(k) === -1) orderedKeys.push(k); });
        });
        // Add any allowed keys not covered by GROUPS
        allowed.forEach(k => { if (orderedKeys.indexOf(k) === -1) orderedKeys.push(k); });

        // Verteile die Filter auf top und bottom (je 50%)
        const halfCount = Math.ceil(orderedKeys.length / 2);
        
        orderedKeys.forEach(function(k, index) {
            if (!filters[k]) return;
            const item = renderFilterItem(k);
            if (!item) return;
            
            // Erste Hälfte oben, zweite Hälfte unten
            if (index < halfCount) {
                topContainer.appendChild(item);
            } else {
                bottomContainer.appendChild(item);
            }
            rendered.add(k);
        });

        // Log rendered keys for debugging (helps identify missing categories)
        try {
            console.info('POI filters rendered:', Array.from(rendered).join(', '));
            console.info(`POI filters count: rendered=${rendered.size}, available=${Object.keys(filters).length}, allowed=${allowed.length}`);
            console.info(`Distribution: top=${topContainer.children.length}, bottom=${bottomContainer.children.length}`);
        } catch (e) {}
        
        console.info(`POI filters initialized: ${allowed.length} categories`);
    } catch (e) {
        console.error('POI filter render failed', e);
    }
}

