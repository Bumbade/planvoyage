/**
 * Utility Functions - Shared Helpers
 * 
 * Centralized utility functions used across the application.
 * Import these functions to avoid duplicating logic across multiple files.
 */

/**
 * Escape HTML special characters to prevent XSS attacks
 * @param {string} str - String to escape
 * @returns {string} Escaped string safe for HTML context
 * 
 * @example
 * const safeHtml = escapeHtml('<script>alert("xss")</script>');
 * // Returns: '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'
 */
export function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Format a number with thousands separator
 * @param {number} num - Number to format
 * @param {string} locale - Locale for formatting (default: 'de-DE')
 * @returns {string} Formatted number string
 * 
 * @example
 * const formatted = formatNumber(1234567);
 * // Returns: '1.234.567' (in de-DE)
 */
export function formatNumber(num, locale = 'de-DE') {
    return new Intl.NumberFormat(locale).format(num);
}

/**
 * Truncate text to a maximum length with ellipsis
 * @param {string} text - Text to truncate
 * @param {number} maxLength - Maximum length (default: 100)
 * @returns {string} Truncated text with ellipsis if needed
 * 
 * @example
 * const truncated = truncateText('This is a very long text', 10);
 * // Returns: 'This is a ...'
 */
export function truncateText(text, maxLength = 100) {
    if (!text) return '';
    if (text.length <= maxLength) return text;
    return text.substring(0, maxLength - 3) + '...';
}

/**
 * Parse query string parameters from URL
 * @param {string} queryString - Query string (with or without leading '?')
 * @returns {object} Object with parsed parameters
 * 
 * @example
 * const params = parseQueryString('?id=123&name=test');
 * // Returns: { id: '123', name: 'test' }
 */
export function parseQueryString(queryString) {
    const params = {};
    const cleaned = queryString.replace(/^\?/, '');
    
    if (!cleaned) return params;
    
    cleaned.split('&').forEach(pair => {
        const [key, value] = pair.split('=');
        if (key) {
            params[decodeURIComponent(key)] = value ? decodeURIComponent(value) : '';
        }
    });
    
    return params;
}

/**
 * Debounce function calls (useful for event handlers)
 * @param {function} func - Function to debounce
 * @param {number} wait - Wait time in milliseconds
 * @returns {function} Debounced function
 * 
 * @example
 * const handleResize = debounce(() => { console.log('resized'); }, 300);
 * window.addEventListener('resize', handleResize);
 */
export function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Deep clone an object or array
 * @param {*} obj - Object or array to clone
 * @returns {*} Deep cloned copy
 * 
 * @example
 * const original = { nested: { value: 1 } };
 * const clone = deepClone(original);
 * clone.nested.value = 2; // original.nested.value is still 1
 */
export function deepClone(obj) {
    if (obj === null || typeof obj !== 'object') return obj;
    if (obj instanceof Date) return new Date(obj.getTime());
    if (obj instanceof Array) return obj.map(item => deepClone(item));
    if (obj instanceof Object) {
        const clone = {};
        for (let key in obj) {
            if (obj.hasOwnProperty(key)) {
                clone[key] = deepClone(obj[key]);
            }
        }
        return clone;
    }
}

/**
 * Create an SVG-based letter icon as a Leaflet `L.icon`.
 * This provides a small visible placeholder when a POI logo file is missing.
 * Note: relies on global `L` (Leaflet) being available in the page context.
 */
export function createSvgLetterIcon(letter, bg) {
    try {
        letter = (letter || '').toString().trim().substring(0,2).toUpperCase();
        bg = bg || '#2b8af3';
        var size = 64;
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '">'
                + '<circle cx="' + (size/2) + '" cy="' + (size/2) + '" r="' + (size/2 - 2) + '" fill="' + bg + '" />'
                + '<text x="50%" y="54%" font-family="Segoe UI,Roboto,Arial,sans-serif" font-size="28" fill="#ffffff" text-anchor="middle" alignment-baseline="middle">' + escapeHtml(letter) + '</text>'
                + '</svg>';
        var url = 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
        if (typeof L !== 'undefined' && L && typeof L.icon === 'function') {
            return L.icon({ iconUrl: url, iconSize: [32,32], iconAnchor: [16,32], popupAnchor: [0,-20], className: 'poi-logo-icon' });
        }
        return url;
    } catch (e) { return null; }
}

/**
 * Parse a Postgres HSTORE-like string into a JS object.
 * Accepts JSON strings as well. Returns empty object on failure.
 */
export function parsePostgresHstore(hstr) {
    if (!hstr) return {};
    try {
        var trimmed = String(hstr).trim();
        if ((trimmed.charAt(0) === '{' && trimmed.charAt(trimmed.length-1) === '}') || trimmed.charAt(0) === '[') {
            try {
                var parsed = JSON.parse(trimmed);
                if (parsed && typeof parsed === 'object') return parsed;
            } catch (e) {}
        }
    } catch (e) {}
    var obj = {};
    try {
        var hstoreRegex = /"([^\"]+)"\s*=>\s*"([^\"]*)"/g;
        var match;
        while ((match = hstoreRegex.exec(hstr)) !== null) {
            obj[match[1]] = match[2];
        }
    } catch (e) {}
    return obj;
}

export default {
    escapeHtml,
    formatNumber,
    truncateText,
    parseQueryString,
    debounce,
    deepClone,
    createSvgLetterIcon,
    parsePostgresHstore
};
