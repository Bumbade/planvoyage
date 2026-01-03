<?php
/**
 * src/helpers/flash_component.php
 * Renders flash messages (success/error) as reusable component.
 * Replaces inline flash message logic in views.
 *
 * Usage in a view:
 *   <?php renderFlashMessages(); ?>
 */

/**
 * Renders flash success and error messages from session.
 * Escapes output to prevent XSS.
 *
 * @return void
 */
function renderFlashMessages(): void
{
    $flashOk = function_exists('flash_get') ? flash_get('success') : null;
    if ($flashOk):
        ?>
        <div class="flash-success" role="alert">
            <?php echo htmlspecialchars($flashOk); ?>
        </div>
        <?php
    endif;

    $flashErr = function_exists('flash_get') ? flash_get('error') : null;
    if ($flashErr):
        ?>
        <div class="flash-error" role="alert">
            <?php echo htmlspecialchars($flashErr); ?>
        </div>
        <?php
    endif;
}

/**
 * Get flash message without rendering (for API endpoints).
 *
 * @param string $type 'success' or 'error'
 * @return string|null The flash message or null
 */
function getFlashMessage(string $type = 'success'): ?string
{
    return function_exists('flash_get') ? flash_get($type) : null;
}

/**
 * Check if a flash message exists.
 *
 * @param string $type 'success' or 'error'
 * @return bool
 */
function hasFlashMessage(string $type = 'success'): bool
{
    $msg = getFlashMessage($type);
    return !empty($msg);
}
