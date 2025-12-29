// Deprecated loader: server now renders the search controls in PHP views.
// Keep a no-op export for backwards compatibility with existing imports.
export default async function loadPoiSearch(_insertSelector = '#poi-filter') {
  return;
}
