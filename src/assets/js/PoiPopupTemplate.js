/**
 * PoiPopupTemplate - Secure HTML generation for POI popups using <template> elements
 * Prevents XSS attacks by using textContent instead of innerHTML
 */
class PoiPopupTemplate {
    /**
     * Create a secure popup element using DOM methods
     * @param {Object} poi - The POI object
     * @param {string} displayName - Escaped display name
     * @param {Function} onImportClick - Callback for import button click
     * @returns {HTMLElement} Safe DOM element
     */
    static createPopupElement(poi, displayName, onImportClick) {
        // Create container using template element for safety
        const template = document.createElement('template');
        template.innerHTML = `
            <div class="poi-popup-container">
                <div class="poi-popup-header"></div>
                <div class="poi-popup-body"></div>
                <div class="poi-popup-footer"></div>
            </div>
        `;

        const container = template.content.cloneNode(true).firstElementChild;

        // Add header (display name and OSM info)
        const header = container.querySelector('.poi-popup-header');
        header.style.display = 'block';
        const headerTitle = document.createElement('b');
        headerTitle.textContent = displayName; // Safe: textContent only
        header.appendChild(headerTitle);

        // Show localized POI type if available
        try {
            const typeKey = poi.type || '';
            const typeLabel = (window.I18N && window.I18N.pois_types && window.I18N.pois_types[typeKey]) || typeKey || '';
            if (typeLabel) {
                const typeEl = document.createElement('div');
                typeEl.className = 'poi-popup-type text-muted';
                typeEl.textContent = typeLabel;
                header.appendChild(typeEl);
            }
        } catch (e) {}

        const osmInfo = document.createElement('small');
        osmInfo.className = 'text-muted d-block';
        osmInfo.textContent = `OSM: ${poi.osm_type || ''} ${poi.osm_id}`; // Safe: textContent only
        header.appendChild(osmInfo);

        // Add body (address, contact, website, opening hours)
        const body = container.querySelector('.poi-popup-body');
        // Ensure body is visible even if external CSS hides it
        body.style.display = 'block';
        // Normalize tags: accept object, JSON string, or Postgres hstore text
        let tags = {};
        try {
            if (typeof poi.tags === 'object' && poi.tags) tags = poi.tags;
            else if (typeof poi.tags === 'string' && poi.tags.trim()) {
                const raw = poi.tags.trim();
                try {
                    const parsed = JSON.parse(raw);
                    if (parsed && typeof parsed === 'object') tags = parsed;
                } catch (e) {
                    // fallback: parse simple hstore-like format "key"=>"value"
                    const h = {};
                    const re = /"([^"\\]+)"\s*=>\s*"([^"\\]*)"/g;
                    let m;
                    while ((m = re.exec(raw)) !== null) {
                        h[m[1]] = m[2];
                    }
                    tags = h;
                }
            }
        } catch (e) { tags = {}; }

        // Structured fields: Country, City, Street, Phone, Email, Opening hours (display if available)
        const country = tags['addr:country'] || tags.country || poi.country || '';
        const city = tags['addr:city'] || tags.city || poi.city || '';
        const street = tags['addr:street'] || tags.street || '';
        const housenumber = tags['addr:housenumber'] || tags.housenumber || '';
        const phone = tags.phone || tags['contact:phone'] || tags.contact_phone || '';
        const email = tags.email || tags['contact:email'] || '';
        const opening = tags.opening_hours || tags['opening_hours'] || '';

        // Use a definition list for compact field labels and values
        const dl = document.createElement('dl');
        dl.className = 'poi-popup-fields';

        const addField = (label, value, opts = {}) => {
            if (!value && value !== 0) return;
            const dt = document.createElement('dt');
            dt.className = 'poi-popup-field-label text-muted';
            dt.textContent = label + ':';
            const dd = document.createElement('dd');
            dd.className = 'poi-popup-field-value';
            if (opts.link === 'tel') {
                const a = document.createElement('a');
                a.href = 'tel:' + encodeURIComponent(value);
                a.textContent = value;
                a.className = 'poi-popup-link';
                dd.appendChild(a);
            } else if (opts.link === 'mailto') {
                const a = document.createElement('a');
                a.href = 'mailto:' + encodeURIComponent(value);
                a.textContent = value;
                a.className = 'poi-popup-link';
                dd.appendChild(a);
            } else if (opts.link === 'url') {
                const a = document.createElement('a');
                a.href = this.sanitizeUrl(value);
                a.textContent = value;
                a.target = '_blank';
                a.rel = 'noopener nofollow';
                a.className = 'poi-popup-link';
                dd.appendChild(a);
            } else {
                dd.textContent = value;
            }
            dl.appendChild(dt);
            dl.appendChild(dd);
        };

        // Prefer localized labels when available. Support several I18N locations:
        // - window.I18N.pois_labels[key]
        // - window.I18N.pois[key]
        // - window.I18N.pois['label.' + key]
        const LBL = (key, fallback) => {
            try {
                if (window.I18N) {
                    if (window.I18N.pois_labels && window.I18N.pois_labels[key]) return window.I18N.pois_labels[key];
                    if (window.I18N.pois && window.I18N.pois[key]) return window.I18N.pois[key];
                    if (window.I18N.pois && window.I18N.pois['label.' + key]) return window.I18N.pois['label.' + key];
                }
            } catch (e) {}
            return fallback;
        };

        addField(LBL('country','Land'), country);
        addField(LBL('city','Stadt'), city);
        addField(LBL('street','Straße'), street ? (street + (housenumber ? ' ' + housenumber : '')) : '');
        addField(LBL('phone','Telefon'), phone, { link: 'tel' });
        addField(LBL('email','Email'), email, { link: 'mailto' });
        // website (optional) shown as URL
        const websiteVal = tags.website || tags['contact:website'] || tags.url || '';
        addField(LBL('website','Website'), websiteVal, { link: 'url' });
        addField(LBL('opening_hours','Öffnungszeiten'), opening);

        // Only append if at least one field was added
        if (dl.children.length > 0) body.appendChild(dl);

        // Add footer: show Import for external POIs, but for MySQL (user-owned) POIs show Edit link
        const footer = container.querySelector('.poi-popup-footer');
        // Ensure footer layout is applied by CSS, avoid inline styles
        const isMysql = (poi.source === 'mysql');
        if (isMysql) {
            // Show Edit link which navigates to the details page for this POI
            const editLink = document.createElement('a');
            editLink.className = 'poi-edit-link btn btn-sm btn-primary';
            editLink.textContent = (window.I18N && window.I18N.pois && window.I18N.pois.edit) || 'Edit';
            const base = (window.APP_BASE || '') + '';
            // Use POI id (MySQL) if available, otherwise fallback to osm_id
            const id = poi.id || poi.osm_id || '';
            editLink.href = base + '/index.php/locations/view?id=' + encodeURIComponent(id);
            editLink.setAttribute('role', 'button');
            footer.appendChild(editLink);
        } else {
            // Ensure footer is visible and laid out
            footer.style.display = 'flex';
            footer.style.gap = '6px';
            const importBtn = document.createElement('button');
            importBtn.className = 'poi-import-btn btn btn-sm btn-primary';
            importBtn.textContent = (window.I18N && window.I18N.pois && window.I18N.pois.import) || 'Import'; // Safe: textContent only
            importBtn.dataset.osmId = poi.osm_id;
            importBtn.dataset.osmType = poi.osm_type;

            if (onImportClick) {
                importBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    onImportClick(poi.osm_id, poi.osm_type);
                });
            }

            footer.appendChild(importBtn);
        }

        return container;
    }

    /**
     * Create HTML string for Leaflet popup (used with bindPopup)
     * This version safely generates HTML using DOM manipulation then converts to string
     * @param {Object} poi - The POI object
     * @param {string} displayName - Escaped display name
     * @returns {string} Safe HTML string
     */
    static createPopupHtmlString(poi, displayName) {
        const element = this.createPopupElement(poi, displayName, null);
        return element.outerHTML;
    }

    /**
     * Sanitize URLs to prevent XSS and injection attacks
     * @param {string} url - Raw URL
     * @returns {string} Safe URL
     */
    static sanitizeUrl(url) {
        if (!url) return '#';
        const trimmed = String(url).trim().toLowerCase();

        // Only allow http/https/mailto
        if (trimmed.startsWith('http://') || trimmed.startsWith('https://')) {
            try {
                const urlObj = new URL(url);
                return urlObj.href;
            } catch (e) {
                return '#';
            }
        }
        if (trimmed.startsWith('mailto:')) {
            return url;
        }

        // Reject javascript: and data: URLs
        return '#';
    }

    /**
     * Render POI list item safely using DOM methods
     * @param {Object} poi - POI object
     * @param {string} displayName - Escaped display name
     * @returns {HTMLElement} Safe list item element
     */
    static createListItem(poi, displayName) {
        const template = document.createElement('template');
        template.innerHTML = `<li class="list-group-item"></li>`;

        const li = template.content.cloneNode(true).firstElementChild;

        // Create link
        const link = document.createElement('a');
        link.href = '#';
        link.className = 'poi-list-item-link';
        link.textContent = displayName; // Safe: textContent only
        link.dataset.lat = poi.lat;
        link.dataset.lon = poi.lon;

        li.appendChild(link);
        // Optionally show localized type label below the link
        try {
            const typeLabel = (window.I18N && window.I18N.pois_types && window.I18N.pois_types[poi.type]) || poi.type || '';
            if (typeLabel) {
                const t = document.createElement('small');
                t.className = 'poi-list-item-type text-muted d-block';
                t.textContent = typeLabel;
                li.appendChild(t);
            }
        } catch (e) {}

        // Create info text
        const info = document.createElement('small');
        info.className = 'text-muted d-block';
        info.textContent = `OSM: ${poi.osm_type} ${poi.osm_id} | Added: ${new Date(poi.created_at).toLocaleDateString()}`;
        li.appendChild(info);

        return li;
    }

    /**
     * Render error message safely
     * @param {string} message - Error message
     * @returns {HTMLElement} Safe error element
     */
    static createErrorElement(message) {
        const template = document.createElement('template');
        template.innerHTML = `<div class="alert alert-danger"></div>`;

        const div = template.content.cloneNode(true).firstElementChild;
        div.textContent = `Error loading list: ${message}`; // Safe: textContent only
        return div;
    }
}

// Export for use in PoiMapManager and Quick Search
window.PoiPopupTemplate = PoiPopupTemplate;
