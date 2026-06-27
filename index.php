<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/header.php';

$page = $_GET['page'] ?? 'dashboard';
$allowed = ['dashboard', 'settings', 'teachers', 'courses', 'categories', 'groups', 'schedule', 'generate', 'statistics'];

if (in_array($page, $allowed) && file_exists(__DIR__ . "/pages/{$page}.php")) {
    require_once __DIR__ . "/pages/{$page}.php";
} else {
    echo '<div class="empty">Page not found.</div>';
}

require_once __DIR__ . '/includes/footer.php';
