// Loads the `poi-search.html` fragment and inserts it into the DOM
export default function loadPoiSearch(insertSelector = '#poi-filter') {
  async function insert() {
    try {
      const resp = await fetch(`${window.APP_BASE || '/'}assets/html/poi-search.html`);
      if (!resp.ok) return;
      const html = await resp.text();
      const container = document.querySelector(insertSelector) || document.body;
      // Insert at top of container
      const wrapper = document.createElement('div');
      wrapper.innerHTML = html;
      // Avoid duplicating if already present
      if (!document.getElementById('poi-search')) container.insertBefore(wrapper, container.firstChild);
    } catch (e) {
      console.debug('poi-search-loader: failed to load fragment', e);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', insert);
  else insert();
}
