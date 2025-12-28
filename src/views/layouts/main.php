<?php
require_once __DIR__ . '/../../helpers/url.php';
include_once __DIR__ . '/../includes/header.php';
?>

<div class="container">
    <?php
    // This is where the content of the specific view will be included
    if (isset($content)) {
        echo $content;
    }
?>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>