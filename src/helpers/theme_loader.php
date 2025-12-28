<?php

/**
 * Theme Configuration Loader
 *
 * Loads themes from config/themes/themes.json
 * Injects CSS variables into global.css
 */

class ThemeConfigLoader
{
    private static $instance = null;
    private $theme_file;
    private $active_theme = 'dark';
    private $themes = [];

    private function __construct()
    {
        $this->theme_file = __DIR__ . '/themes/themes.json';
        $this->loadThemes();
        $this->detectActiveTheme();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load all themes from JSON file
     */
    private function loadThemes()
    {
        if (file_exists($this->theme_file)) {
            $json = file_get_contents($this->theme_file);
            $this->themes = json_decode($json, true) ?? [];
        }

        // Always have default dark theme
        if (!isset($this->themes['dark'])) {
            $this->themes['dark'] = $this->getDefaultDarkTheme();
        }
    }

    /**
     * Get default dark theme (fallback)
     */
    private function getDefaultDarkTheme()
    {
        return [
            'name' => 'dark',
            'label' => 'Dark',
            'description' => 'Default dark theme',
            'colors' => [
                'primary-color' => '#0044ff',
                'primary-dark' => '#003366',
                'primary-light' => '#4d94ff',
                'secondary-color' => '#2c3e50',
                'secondary-light' => '#34495e',
                'success-color' => '#27ae60',
                'warning-color' => '#f39c12',
                'error-color' => '#e74c3c',
                'info-color' => '#3498db',
                'text-dark' => '#e0e0e0',
                'text-light' => '#b0b0b0',
                'text-lighter' => '#808080',
                'bg-light' => '#1a1a1a',
                'bg-lighter' => '#2d2d2d',
                'border-color' => '#404040',
                'warning-bg-light' => '#2a2a00',
                'warning-border' => '#666600',
                'warning-text' => '#ffcc00',
                'text-hint' => '#999999',
                'map-primary-line' => '#4da6ff',
                'map-outline' => '#1a1a1a',
                'map-fallback-line' => '#ffaa00',
                'selection-bg' => '#1a3a5a',
            ],
            'shadows' => [
                'shadow' => '0 2px 8px rgba(0, 0, 0, 0.3)',
                'shadow-lg' => '0 4px 16px rgba(0, 0, 0, 0.4)',
            ],
        ];
    }

    /**
     * Detect active theme from:
     * 1. URL parameter (?theme=name)
     * 2. Cookie (theme_preference)
     * 3. Default (dark)
     */
    private function detectActiveTheme()
    {
        // URL parameter (testing/switching)
        if (isset($_GET['theme']) && isset($this->themes[$_GET['theme']])) {
            $this->active_theme = $_GET['theme'];
            setcookie('theme_preference', $_GET['theme'], time() + (30 * 24 * 60 * 60), '/');
            return;
        }

        // Cookie preference
        if (isset($_COOKIE['theme_preference']) && isset($this->themes[$_COOKIE['theme_preference']])) {
            $this->active_theme = $_COOKIE['theme_preference'];
            return;
        }

        // Default
        $this->active_theme = 'dark';
    }

    /**
     * Get active theme
     */
    public function getActiveTheme()
    {
        return $this->themes[$this->active_theme] ?? $this->themes['dark'];
    }

    /**
     * Get all themes
     */
    public function getThemes()
    {
        return $this->themes;
    }

    /**
     * Get theme by name
     */
    public function getTheme($name)
    {
        return $this->themes[$name] ?? null;
    }

    /**
     * Get CSS for active theme
     */
    public function getActiveThemeCSS()
    {
        $theme = $this->getActiveTheme();
        return $this->generateCSS($theme);
    }

    /**
     * Generate CSS from theme data
     */
    public function generateCSS($theme)
    {
        $css = ":root {\n";

        if (isset($theme['colors'])) {
            $css .= "    /* Colors - " . htmlspecialchars($theme['label']) . " */\n";
            foreach ($theme['colors'] as $key => $value) {
                $css .= "    --{$key}: {$value};\n";
            }
        }

        if (isset($theme['shadows'])) {
            $css .= "\n    /* Shadows */\n";
            foreach ($theme['shadows'] as $key => $value) {
                $css .= "    --{$key}: {$value};\n";
            }
        }

        $css .= "}\n";
        return $css;
    }
}

// Singleton instance
function get_theme_loader()
{
    return ThemeConfigLoader::getInstance();
}

// Get active theme variables as PHP array
function get_active_theme_colors()
{
    $loader = ThemeConfigLoader::getInstance();
    $theme = $loader->getActiveTheme();
    return $theme['colors'] ?? [];
}

// Output theme CSS inline (use in <style> tag)
function output_theme_css()
{
    $loader = ThemeConfigLoader::getInstance();
    echo $loader->getActiveThemeCSS();
}
