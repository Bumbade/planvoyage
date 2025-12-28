<?php
/**
 * Utility Functions - Shared Helpers
 * 
 * Centralized utility functions used across the application.
 * Include this file to access common helpers.
 * 
 * Usage:
 *   require_once __DIR__ . '/utils.php';
 *   $safe = escapeHtml('<script>alert("xss")</script>');
 */

/**
 * Escape HTML special characters to prevent XSS attacks
 * 
 * @param string $str String to escape
 * @return string Escaped string safe for HTML context
 * 
 * @example
 * $safe = escapeHtml('<script>alert("xss")</script>');
 * // Returns: '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'
 */
if (!function_exists('escapeHtml')) {
    function escapeHtml($str) {
        if (!$str) return '';
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Format a number with locale-specific formatting
 * 
 * @param number $num Number to format
 * @param string $locale Locale code (default: 'de_DE')
 * @return string Formatted number string
 * 
 * @example
 * $formatted = formatNumber(1234567, 'de_DE');
 * // Returns: '1.234.567'
 */
if (!function_exists('formatNumber')) {
    function formatNumber($num, $locale = 'de_DE') {
        $fmt = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        return $fmt->format($num);
    }
}

/**
 * Truncate text to a maximum length with ellipsis
 * 
 * @param string $text Text to truncate
 * @param int $maxLength Maximum length (default: 100)
 * @return string Truncated text with ellipsis if needed
 * 
 * @example
 * $truncated = truncateText('This is a very long text', 10);
 * // Returns: 'This is a ...'
 */
if (!function_exists('truncateText')) {
    function truncateText($text, $maxLength = 100) {
        if (!$text) return '';
        if (strlen($text) <= $maxLength) return $text;
        return substr($text, 0, $maxLength - 3) . '...';
    }
}

/**
 * Check if string starts with a given prefix
 * 
 * @param string $haystack String to search in
 * @param string $needle Prefix to check
 * @return bool True if $haystack starts with $needle
 * 
 * @example
 * $result = str_starts_with('hello world', 'hello');
 * // Returns: true
 */
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return strpos($haystack, $needle) === 0;
    }
}

/**
 * Check if string ends with a given suffix
 * 
 * @param string $haystack String to search in
 * @param string $needle Suffix to check
 * @return bool True if $haystack ends with $needle
 * 
 * @example
 * $result = str_ends_with('hello world', 'world');
 * // Returns: true
 */
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return strrpos($haystack, $needle) === (strlen($haystack) - strlen($needle));
    }
}

/**
 * Safe JSON encoding with error handling
 * 
 * @param mixed $data Data to encode
 * @param int $flags JSON encoding flags
 * @return string JSON string, or '{}' on error
 * 
 * @example
 * $json = safeJsonEncode(['key' => 'value']);
 * // Returns: '{"key":"value"}'
 */
if (!function_exists('safeJsonEncode')) {
    function safeJsonEncode($data, $flags = JSON_UNESCAPED_SLASHES) {
        $json = json_encode($data, $flags);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JSON encode error: ' . json_last_error_msg());
            return '{}';
        }
        return $json;
    }
}
