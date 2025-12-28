export default function initPoiFilters() {
    try {
        const container = document.getElementById('poi-filter');
        if (!container) {
            console.warn('POI filter container not found');
            return;
        }
        
        const filters = window.POI_FILTERS || {};
        if (Object.keys(filters).length === 0) {
            console.warn('POI_FILTERS not loaded or empty');
            return;
        }
        
        const allowed = window.POI_ALLOWED_CATEGORIES && window.POI_ALLOWED_CATEGORIES.length 
            ? window.POI_ALLOWED_CATEGORIES 
            : Object.keys(filters);
        
        const labels = (window.I18N && (window.I18N.pois_types || window.I18N.pois)) || {};
        
        // Preserve any existing load-controls element (we'll re-append after rendering)
        const existingLoadControls = container.querySelector('#poi-load-controls');
        // Clear container
        container.innerHTML = '';

        // Map category keys to icon filenames (fallback to generic poi.png)
        const ICON_MAP = {
            hotel: 'hotel.png',
            attraction: 'Attractions.png',
            tourist_info: 'TouristInfo.png',
            food: 'food.png',
            nightlife: 'poi.png',
            gas_stations: 'gas_station.png',
            charging_station: 'charging.png',
            parking: 'Parking.png',
            bank: 'bank.png',
            healthcare: 'Pharmacy.png',
            fitness: 'Fitness.png',
            laundry: 'Laundry.png',
            supermarket: 'supermarket.png',
            tobacco: 'TabacoVape.png',
            cannabis: 'Cannabis.png',
            transport: 'Transportation.png',
            dump_station: 'dump_station.png',
            campgrounds: 'campground.png'
        };

        const iconsBase = (window.ICONS_BASE || (window.APP_BASE ? (window.APP_BASE + '/assets/icons/') : '/src/assets/icons/'));

        // Define groups and desired order (use id + fallback title)
        const GROUPS = [
            { id: 'tourism', title: 'Tourismus & Freizeit', keys: ['hotel','attraction','tourist_info','campgrounds'] },
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

        orderedKeys.forEach(function(k) {
            if (!filters[k]) return;
            const item = renderFilterItem(k);
            if (item) { container.appendChild(item); rendered.add(k); }
        });

        // Re-append preserved load controls (so buttons placed in HTML stay visible)
        if (existingLoadControls) {
            container.appendChild(existingLoadControls);
        }
        
        console.info(`POI filters initialized: ${allowed.length} categories`);
    } catch (e) {
        console.error('POI filter render failed', e);
    }
}

