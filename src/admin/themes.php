<?php
/**
 * Admin: Theme Management
 * URL: /admin/themes.php
 *
 * Allows admins to:
 * - Create new themes (simple JSON configuration)
 * - Edit existing themes
 * - Preview themes
 * - Export/Import themes
 * - Set default theme
 */

// Security & Initialization
if (file_exists(__DIR__ . '/../helpers/session.php')) {
    require_once __DIR__ . '/../helpers/session.php';
    start_secure_session();
}
if (file_exists(__DIR__ . '/../helpers/auth.php')) {
    require_once __DIR__ . '/../helpers/auth.php';
}

// Require admin access
if (!function_exists('is_admin_user') || !is_admin_user()) {
    http_response_code(403);
    die('Access denied. Admin only.');
}

// Load helpers
require_once __DIR__ . '/../helpers/i18n.php';
require_once __DIR__ . '/../config/env.php';

// Theme Manager Class
class ThemeManager
{
    private $theme_dir;
    private $config_file;

    public function __construct()
    {
        $this->theme_dir = __DIR__ . '/../config/themes';
        $this->config_file = $this->theme_dir . '/themes.json';

        // Create directory if it doesn't exist
        if (!is_dir($this->theme_dir)) {
            mkdir($this->theme_dir, 0755, true);
        }
    }

    /**
     * Get all themes
     */
    public function getThemes()
    {
        if (!file_exists($this->config_file)) {
            return ['dark' => $this->getDefaultTheme('dark')];
        }

        $json = file_get_contents($this->config_file);
        return json_decode($json, true) ?? [];
    }

    /**
     * Get single theme
     */
    public function getTheme($name)
    {
        $themes = $this->getThemes();
        return $themes[$name] ?? null;
    }

    /**
     * Get default theme template
     */
    public function getDefaultTheme($name = 'custom')
    {
        return [
            'name' => $name,
            'label' => ucfirst($name),
            'description' => 'Custom theme',
            'created_at' => date('Y-m-d H:i:s'),
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
            ],
            'shadows' => [
                'shadow' => '0 2px 8px rgba(0, 0, 0, 0.3)',
                'shadow-lg' => '0 4px 16px rgba(0, 0, 0, 0.4)',
            ],
        ];
    }

    /**
     * Create or update theme
     */
    public function saveTheme($name, $data)
    {
        $themes = $this->getThemes();
        $themes[$name] = $data;

        // Ensure name and label are set
        if (!isset($themes[$name]['name'])) {
            $themes[$name]['name'] = $name;
        }
        if (!isset($themes[$name]['label'])) {
            $themes[$name]['label'] = ucfirst($name);
        }
        if (!isset($themes[$name]['created_at'])) {
            $themes[$name]['created_at'] = date('Y-m-d H:i:s');
        }

        $themes[$name]['updated_at'] = date('Y-m-d H:i:s');

        $json = json_encode($themes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return file_put_contents($this->config_file, $json) !== false;
    }

    /**
     * Delete theme
     */
    public function deleteTheme($name)
    {
        if ($name === 'dark') {
            return false; // Cannot delete default theme
        }

        $themes = $this->getThemes();
        unset($themes[$name]);

        $json = json_encode($themes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return file_put_contents($this->config_file, $json) !== false;
    }

    /**
     * Generate CSS from theme
     */
    public function generateCSS($theme)
    {
        $css = ":root {\n";

        // Colors
        if (isset($theme['colors'])) {
            $css .= "    /* Colors */\n";
            foreach ($theme['colors'] as $key => $value) {
                $css .= "    --{$key}: {$value};\n";
            }
        }

        // Shadows
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

// Initialize
$manager = new ThemeManager();
$action = $_GET['action'] ?? 'list';
$theme_name = $_GET['theme'] ?? '';
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? $action;
    $theme_name = $_POST['theme_name'] ?? $theme_name;

    if ($action === 'save' && $theme_name) {
        $theme_data = $manager->getDefaultTheme($theme_name);
        $theme_data['label'] = $_POST['label'] ?? ucfirst($theme_name);
        $theme_data['description'] = $_POST['description'] ?? '';

        // Parse colors from form
        if (isset($_POST['colors']) && is_array($_POST['colors'])) {
            $theme_data['colors'] = $_POST['colors'];
        }

        if ($manager->saveTheme($theme_name, $theme_data)) {
            $message = "Theme '{$theme_name}' saved successfully!";
            $action = 'list';
        } else {
            $error = "Failed to save theme.";
        }
    } elseif ($action === 'delete' && $theme_name && $theme_name !== 'dark') {
        if ($manager->deleteTheme($theme_name)) {
            $message = "Theme '{$theme_name}' deleted.";
            $action = 'list';
        } else {
            $error = "Failed to delete theme.";
        }
    }
}

// Get current theme
$current_theme = null;
if ($action === 'edit' || $action === 'preview') {
    $current_theme = $manager->getTheme($theme_name);
    if (!$current_theme) {
        $error = "Theme not found.";
        $action = 'list';
    }
}

// Get all themes
$all_themes = $manager->getThemes();

// Collect admin CSS in a variable BEFORE including header (will be added to HEAD_EXTRA)
ob_start();
?>
<style>
        .admin-main {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: var(--bg-light);
        }
        
        .admin-header {
            background: var(--secondary-color);
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid var(--primary-color);
        }
        
        .admin-header h1 {
            font-size: 2em;
            margin-bottom: 5px;
            color: var(--text-dark);
        }
        
        .admin-header p {
            color: var(--text-light);
            font-size: 0.9em;
        }
        
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            border-left: 4px solid var(--success-color);
            background: rgba(39, 174, 96, 0.1);
        }
        
        .error {
            border-left-color: var(--error-color);
            background: rgba(231, 76, 60, 0.1);
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .tab-button {
            padding: 12px 20px;
            background: transparent;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }
        
        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .tab-button:hover {
            color: var(--text-dark);
        }
        
        /* Theme List */
        .theme-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .theme-card {
            background: var(--bg-lighter);
            border: 2px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            transition: all 0.2s;
        }
        
        .theme-card:hover {
            border-color: var(--primary-color);
            box-shadow: 0 0 20px rgba(0, 68, 255, 0.2);
        }
        
        .theme-card h3 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .theme-card p {
            color: var(--text-light);
            font-size: 0.9em;
            margin-bottom: 15px;
        }
        
        .theme-colors {
            display: flex;
            gap: 5px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .color-swatch {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            border: 1px solid var(--text-lighter);
        }
        
        .theme-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: var(--border-color);
            color: var(--text-dark);
        }
        
        .btn-secondary:hover {
            background: var(--bg-lighter);
        }
        
        .btn-danger {
            background: var(--error-color);
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        /* Form */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-dark);
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            background: var(--bg-lighter);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            color: var(--text-dark);
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(0, 68, 255, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        /* Color Picker */
        .color-inputs {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .color-input-group {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            padding: 12px;
            background: var(--bg-light);
            border-radius: 4px;
            border: 1px solid var(--border-color);
        }
        
        .color-input-group label {
            color: var(--text-dark);
            font-size: 0.9em;
            margin: 0;
        }
        
        .color-input-group input[type="text"] {
            flex: 1;
            background: var(--bg-lighter);
            border: 1px solid var(--border-color);
            color: var(--text-dark);
            padding: 8px;
            border-radius: 4px;
        }
        
        .color-input-group input[type="color"] {
            width: 50px;
            height: 40px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            cursor: pointer;
            padding: 2px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        /* Preview */
        .preview-container {
            background: var(--bg-lighter);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .preview-header {
            background: var(--primary-color);
            color: white;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .preview-colors {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .preview-color {
            padding: 20px;
            border-radius: 4px;
            text-align: center;
            border: 1px solid var(--border-color);
        }
        
        .preview-color-name {
            color: var(--text-light);
            font-size: 0.8em;
            margin-top: 10px;
        }
        
        /* Code Block */
        .code-block {
            background: var(--bg-light);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            padding: 15px;
            overflow-x: auto;
            margin-top: 15px;
        }
        
        .code-block code {
            color: var(--primary-light);
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
            line-height: 1.5;
        }
        
        /* Headings */
        .admin-main h3 {
            color: var(--text-dark);
        }
        
        .admin-main small {
            color: var(--text-lighter);
        }
        
        @media (max-width: 768px) {
            .theme-grid {
                grid-template-columns: 1fr;
            }
            
            .color-inputs {
                grid-template-columns: 1fr;
            }
            
            .admin-header {
                padding: 15px;
            }
            
            .admin-header h1 {
                font-size: 1.5em;
            }
        }
    </style>
<?php
$HEAD_EXTRA = ob_get_clean();

// Include Header & Navigation
require_once __DIR__ . '/../includes/header.php';
?>
<main class="admin-main" style="background-color: var(--bg-light); min-height: 100vh;">
    <div class="admin-header">
        <h1>🎨 Theme Manager</h1>
        <p>Create, edit, and manage application themes</p>
    </div>
        
        <?php if ($message): ?>
            <div class="message"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($action === 'list'): ?>
            <!-- LIST VIEW -->
            <div class="tabs">
                <button class="tab-button active">All Themes</button>
                <a href="?action=create" class="btn btn-primary" style="margin-left: auto;">+ Create Theme</a>
            </div>
            
            <div class="theme-grid">
                <?php foreach ($all_themes as $name => $theme): ?>
                    <div class="theme-card">
                        <h3><?php echo htmlspecialchars($theme['label'] ?? $name); ?></h3>
                        <p><?php echo htmlspecialchars($theme['description'] ?? 'No description'); ?></p>
                        
                        <div class="theme-colors">
                            <?php
                            $colors = [
                                $theme['colors']['primary-color'] ?? '#0044ff',
                                $theme['colors']['success-color'] ?? '#27ae60',
                                $theme['colors']['warning-color'] ?? '#f39c12',
                                $theme['colors']['error-color'] ?? '#e74c3c',
                            ];
                    foreach ($colors as $color):
                        ?>
                                <div class="color-swatch" style="background-color: <?php echo htmlspecialchars($color); ?>;"></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="theme-actions">
                            <a href="?action=edit&theme=<?php echo urlencode($name); ?>" class="btn btn-primary">Edit</a>
                            <a href="?action=preview&theme=<?php echo urlencode($name); ?>" class="btn btn-secondary">Preview</a>
                            <?php if ($name !== 'dark'): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this theme?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="theme_name" value="<?php echo htmlspecialchars($name); ?>">
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
        <?php elseif ($action === 'create' || $action === 'edit'): ?>
            <!-- EDIT VIEW -->
            <div class="tabs">
                <button class="tab-button active"><?php echo $action === 'create' ? 'Create Theme' : 'Edit Theme'; ?></button>
                <a href="?action=list" class="btn btn-secondary" style="margin-left: auto;">← Back</a>
            </div>
            
            <div style="max-width: 800px; background: var(--bg-lighter); padding: 20px; border-radius: 8px; border: 1px solid var(--border-color);">
                <form method="POST">
                    <input type="hidden" name="action" value="save">
                    
                    <div class="form-group">
                        <label>Theme Name</label>
                        <input type="text" name="theme_name" value="<?php echo htmlspecialchars($current_theme['name'] ?? ''); ?>" required <?php echo $action === 'edit' ? 'readonly' : ''; ?>>
                        <small>Lowercase, no spaces (e.g., "ocean", "sunset")</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Label (Display Name)</label>
                        <input type="text" name="label" value="<?php echo htmlspecialchars($current_theme['label'] ?? ''); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description"><?php echo htmlspecialchars($current_theme['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <h3 style="margin-top: 30px; margin-bottom: 15px;">Colors</h3>
                    <div class="color-inputs">
                        <?php
                        $color_labels = [
                        'primary-color' => 'Primary Color',
                        'primary-dark' => 'Primary Dark',
                        'primary-light' => 'Primary Light',
                        'secondary-color' => 'Secondary Color',
                        'success-color' => 'Success',
                        'warning-color' => 'Warning',
                        'error-color' => 'Error',
                        'info-color' => 'Info',
                        'text-dark' => 'Text Dark',
                        'text-light' => 'Text Light',
                        'text-lighter' => 'Text Lighter',
                        'bg-light' => 'Background Light',
                        'bg-lighter' => 'Background Lighter',
                        'border-color' => 'Border Color',
                        ];

            foreach ($color_labels as $key => $label):
                $value = $current_theme['colors'][$key] ?? '#000000';
                ?>
                            <div class="color-input-group">
                                <label style="margin: 0; min-width: 120px; flex-shrink: 0;"><?php echo htmlspecialchars($label); ?></label>
                                <input type="text" name="colors[<?php echo htmlspecialchars($key); ?>]" value="<?php echo htmlspecialchars($value); ?>" pattern="^#[0-9A-Fa-f]{6}$" required>
                                <input type="color" name="colors[<?php echo htmlspecialchars($key); ?>]" value="<?php echo htmlspecialchars($value); ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="form-actions" style="margin-top: 30px;">
                        <a href="?action=list" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save Theme</button>
                    </div>
                </form>
            </div>
            
        <?php elseif ($action === 'preview' && $current_theme): ?>
            <!-- PREVIEW VIEW -->
            <div class="tabs">
                <button class="tab-button active">Preview: <?php echo htmlspecialchars($current_theme['label']); ?></button>
                <a href="?action=list" class="btn btn-secondary" style="margin-left: auto;">← Back</a>
            </div>
            
            <div class="preview-container" style="--primary-color: <?php echo htmlspecialchars($current_theme['colors']['primary-color'] ?? '#0044ff'); ?>; --primary-dark: <?php echo htmlspecialchars($current_theme['colors']['primary-dark'] ?? '#003366'); ?>;">
                <div class="preview-header">
                    <h2>Preview: <?php echo htmlspecialchars($current_theme['label'] ?? ''); ?></h2>
                </div>
                
                <h3 style="margin-bottom: 20px;">Color Palette</h3>
                <div class="preview-colors">
                    <?php foreach ($current_theme['colors'] as $key => $value): ?>
                        <div class="preview-color" style="background-color: <?php echo htmlspecialchars($value); ?>; color: <?php echo strpos($key, 'text') === 0 ? '#1a1a1a' : 'white'; ?>;">
                            <strong><?php echo htmlspecialchars($key); ?></strong>
                            <div class="preview-color-name"><?php echo htmlspecialchars($value); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <h3 style="margin-top: 30px; margin-bottom: 15px;">Generated CSS</h3>
                <div class="code-block">
                    <code><?php echo htmlspecialchars($manager->generateCSS($current_theme)); ?></code>
                </div>
                
                <div class="form-actions" style="margin-top: 30px;">
                    <a href="?action=edit&theme=<?php echo urlencode($current_theme['name']); ?>" class="btn btn-primary">Edit Theme</a>
                </div>
            </div>
            
        <?php endif; ?>
</main>
<?php
// Include Footer
require_once __DIR__ . '/../includes/footer.php';
?>