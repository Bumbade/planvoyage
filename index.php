<?php
// Root front controller proxy.
// Some environments or client candidates call `/index.php/...` instead of `/src/index.php/...`.
// Include the real router from `src/index.php` so both base paths work.
require_once __DIR__ . '/src/index.php';
