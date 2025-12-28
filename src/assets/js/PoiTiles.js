/* PoiTiles.js - ESM module: fetch user's MySQL POIs and render as grouped tile cards
   Groups by country then by canonical `type` and renders accessible markup.
   Uses `window.I18N.pois_types` for localized type labels.
*/
export default class PoiTiles{
    constructor(opts={}){
        this.container = document.querySelector(opts.selector||'#my-pois-tiles');
        this.filters = opts.filters||window.POI_FILTERS||{};
        this.i18n = opts.i18n || (window.I18N || {pois:{}, pois_types:{}});
        this.loading = false;
    }

    async init(){
        if(!this.container) return;
        try{
            this.setLoading(true);
            const url = (window.APP_BASE || '') + '/api/locations/search.php?mine=1&limit=1000';
            const resp = await fetch(url, {credentials:'same-origin'});
            if(!resp.ok) throw new Error('HTTP '+resp.status);
            const j = await resp.json();
            const rows = Array.isArray(j.data) ? j.data : (Array.isArray(j) ? j : []);
            this.render(rows||[]);
        }catch(e){
            this.container.innerHTML = `<div class="alert alert-danger">${(this.i18n.pois && this.i18n.pois.failed) || 'Failed to load POIs.'}</div>`;
            console.error('PoiTiles init failed', e);
        }finally{ this.setLoading(false); }
    }

    setLoading(on){ this.loading = !!on; if(!this.container) return; this.container.classList.toggle('loading', this.loading); }

    render(pois=[]){
        if(!this.container) return;
        this.container.innerHTML = '';
        if(!pois || !pois.length){
            this.container.innerHTML = `<div class="alert alert-info">${(this.i18n.pois && this.i18n.pois.none_found) || 'No POIs found.'}</div>`;
            return;
        }

        const grouped = this._groupByCountryThenType(pois);
        const countryKeys = Object.keys(grouped).sort((a,b)=>a.localeCompare(b));
        const frag = document.createDocumentFragment();
        for(const countryKey of countryKeys){
            // countryKey is the translated/normalized country name
            const types = grouped[countryKey];
            // create a collapsible country section using <details>
            const countryDetails = document.createElement('details');
            countryDetails.className = 'poi-country-section';

            const summary = document.createElement('summary');
            const left = document.createElement('div'); left.className = 'country-summary-left';
            const ctitle = document.createElement('span'); ctitle.className = 'country-summary-title'; ctitle.textContent = countryKey;
            left.appendChild(ctitle);
            summary.appendChild(left);

            // total count badge for the country
            const total = Object.values(types).reduce((n,arr)=>n+arr.length,0);
            const count = document.createElement('span'); count.className = 'country-summary-count'; count.textContent = total;
            summary.appendChild(count);

            countryDetails.appendChild(summary);

            const countryContent = document.createElement('div'); countryContent.className = 'poi-country-content';

            const typeKeys = Object.keys(types).sort((a,b)=>a.localeCompare(b));
            for(const type of typeKeys){
                const typeDetails = document.createElement('details'); typeDetails.className = 'poi-type-section';
                const tsummary = document.createElement('summary');
                const tleft = document.createElement('div'); tleft.className = 'poi-type-summary-left';
                const typeLabel = (this.i18n.pois_types && this.i18n.pois_types[type]) ? this.i18n.pois_types[type] : type;
                const ttitle = document.createElement('span'); ttitle.className = 'poi-type-summary-title'; ttitle.textContent = typeLabel;
                tleft.appendChild(ttitle);
                tsummary.appendChild(tleft);
                const tcount = document.createElement('span'); tcount.className = 'poi-type-summary-count'; tcount.textContent = types[type].length;
                tsummary.appendChild(tcount);
                typeDetails.appendChild(tsummary);

                const grid = document.createElement('div'); grid.className = 'poi-tiles-grid';
                // sort POIs by display name (case-insensitive) for stable ordering
                const sorted = (types[type]||[]).slice().sort((a,b)=>{
                    const na = String(a.display_name||a.name||a.osm_name||'').trim();
                    const nb = String(b.display_name||b.name||b.osm_name||'').trim();
                    return na.localeCompare(nb, undefined, {sensitivity:'base'});
                });
                for(const poi of sorted){ grid.appendChild(this._buildTile(poi)); }
                // Wrap grid in a scrollable container capped to 5 visible items
                const listWrapper = document.createElement('div'); listWrapper.className = 'poi-tiles-list';
                listWrapper.appendChild(grid);
                typeDetails.appendChild(listWrapper);
                countryContent.appendChild(typeDetails);
            }

            countryDetails.appendChild(countryContent);
            frag.appendChild(countryDetails);
        }

        this.container.appendChild(frag);
    }

    _buildTile(poi){
        const article = document.createElement('article'); article.className = 'poi-tile'; article.setAttribute('role','article');
        // Title row (prominent)
        const title = document.createElement('div'); title.className = 'poi-tile-title'; title.textContent = poi.display_name || poi.name || poi.osm_name || this._fallbackName(poi);
        article.appendChild(title);
        // Meta row (address / website / phone)
        const meta = document.createElement('div'); meta.className = 'poi-tile-meta';
        // prefer address, website and phone for meta information
        const metaParts = [];
        // build address from available fields
        const addrParts = [];
        if (poi.address) addrParts.push(poi.address);
        if (poi.street) addrParts.push(poi.street + (poi.housenumber ? ' ' + poi.housenumber : ''));
        if (poi.postcode) addrParts.push(poi.postcode);
        if (poi.city) addrParts.push(poi.city);
        const address = addrParts.filter(Boolean).join(', ');
        if (address) {
            const span = document.createElement('span'); span.textContent = address; meta.appendChild(span); metaParts.push('address');
        }
        // website
        const website = poi.website || poi.url || poi.web;
        if (website) {
            const a = document.createElement('a');
            a.className = 'poi-meta-link';
            a.href = website.indexOf('://') === -1 ? 'https://' + website : website;
            a.target = '_blank'; a.rel = 'noopener noreferrer';
            a.textContent = (window.I18N && window.I18N.actions && window.I18N.actions.website) ? window.I18N.actions.website : (new URL(a.href)).hostname.replace('www.','');
            if (meta.children.length) meta.appendChild(document.createTextNode('  '));
            meta.appendChild(a);
            metaParts.push('website');
        }
        // phone
        const phone = poi.phone || poi.telephone || poi.contact_phone;
        if (phone) {
            const t = document.createElement('a');
            t.className = 'poi-meta-link';
            t.href = 'tel:' + phone.replace(/[^+0-9]/g,'');
            t.textContent = phone;
            if (meta.children.length) meta.appendChild(document.createTextNode('  '));
            meta.appendChild(t);
            metaParts.push('phone');
        }
        // fall back: if no useful meta, leave empty
        if (meta.children.length) article.appendChild(meta);

        if(poi.description){ const desc = document.createElement('div'); desc.className = 'poi-tile-desc'; desc.textContent = poi.description.length>180 ? poi.description.slice(0,180)+'…' : poi.description; article.appendChild(desc); }

        const actions = document.createElement('div'); actions.className = 'poi-tile-actions';
        const viewBtn = document.createElement('a'); viewBtn.className = 'btn-small';
        viewBtn.textContent = (window.I18N && window.I18N.actions && window.I18N.actions.view) ? window.I18N.actions.view : 'View';
        viewBtn.href = (window.APP_BASE || '') + '/index.php/locations/view?id=' + encodeURIComponent(poi.id || '');
        actions.appendChild(viewBtn);
        // Edit button only for owner (compare numeric ids)
        try{
            const current = window.CURRENT_USER_ID ? parseInt(window.CURRENT_USER_ID,10) : null;
            const owner = poi.user_id ? parseInt(poi.user_id,10) : null;
            if(current && owner && current === owner){
                const editBtn = document.createElement('a'); editBtn.className = 'btn-small btn-edit';
                editBtn.textContent = (window.I18N && window.I18N.actions && window.I18N.actions.edit) ? window.I18N.actions.edit : 'Edit';
                editBtn.href = (window.APP_BASE || '') + '/index.php/locations/view?id=' + encodeURIComponent(poi.id || '') + '&edit=1';
                actions.appendChild(editBtn);
            }
        }catch(e){}
        article.appendChild(actions);

        // Center map when the tile is clicked (but ignore clicks on action links)
        article.addEventListener('click', (ev) => {
            // if a link/button was clicked, let its default action proceed
            const target = ev.target;
            if(target && (target.tagName === 'A' || (target.closest && target.closest('a')))) return;
            try{
                const manager = window.PV_POI_MANAGER || (window.PoiMapManager && window.PoiMapManager.instance);
                if(manager && typeof manager.centerOnPoi === 'function'){
                    // center at zoom 15 for list clicks
                    manager.centerOnPoi(poi, { zoom: 15 });
                }
            }catch(e){/* ignore */}
        });
        return article;
    }

    _fallbackName(poi){ return poi.name || poi.osm_name || ('POI '+(poi.id||'')).toString(); }

    _groupByCountryThenType(pois){
        const ret = {};
        for(const p of (pois||[])){
            const countryName = this._countryName(p) || 'Unknown';
            const type = p.type || p.category || 'other';
            if(!ret[countryName]) ret[countryName] = {};
            if(!ret[countryName][type]) ret[countryName][type] = [];
            ret[countryName][type].push(p);
        }
        return ret;
    }

    _countryName(p){
        // 1) prefer explicit country name fields, 2) use country_code mapped via I18N, 3) fallback to Intl
        if(!p) return null;
        if(p.country_name) return p.country_name;
        // if `country` is a two-letter code prefer mapping via I18N/Intl
        if(p.country && typeof p.country === 'string' && p.country.length===2){
            const codeFromCountry = p.country;
            try{
                if(this.i18n && this.i18n.countries && this.i18n.countries[codeFromCountry.toUpperCase()]){
                    return this.i18n.countries[codeFromCountry.toUpperCase()];
                }
            }catch(e){}
            try{ if(typeof Intl !== 'undefined' && Intl.DisplayNames){ const df = new Intl.DisplayNames([navigator.language||'en'],{type:'region'}); return df.of(codeFromCountry.toUpperCase()); } }catch(e){}
            return codeFromCountry.toUpperCase();
        }
        if(p.country) return p.country;
        const code = p.country_code || null;
        if(code){
            // try server-provided I18N map first
            try{
                if(this.i18n && this.i18n.countries && this.i18n.countries[code.toUpperCase()]){
                    return this.i18n.countries[code.toUpperCase()];
                }
            }catch(e){}
            // fall back to Intl
            try{
                if(typeof Intl !== 'undefined' && Intl.DisplayNames){
                    const df = new Intl.DisplayNames([navigator.language||'en'],{type:'region'});
                    return df.of(code.toUpperCase());
                }
            }catch(e){}
            return code.toUpperCase();
        }
        return null;
    }

    _countryFromCode(code){
        if(!code) return null;
        try{
            const up = code.toUpperCase();
            if(typeof Intl !== 'undefined' && Intl.DisplayNames){ try{ const df = new Intl.DisplayNames([navigator.language||'en'],{type:'region'}); return df.of(up);}catch(e){}
            }
            return code;
        }catch(e){ return code; }
    }
}

