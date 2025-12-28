<?php

// Fallback API endpoint to support environments without URL rewriting
// Access: /src/api/documents.php?q=... or via app_base
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../helpers/session.php';
start_secure_session();
require_once __DIR__ . '/../config/mysql.php';
require_once __DIR__ . '/../controllers/DocumentsController.php';

$controller = new DocumentsController();
$controller->apiSearch();
