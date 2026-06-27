<?php
$currentPage = $_GET['page'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<nav>
    <div class="nav-inner">
        <span class="brand"><?= APP_NAME ?></span>
        <a href="?page=dashboard" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="?page=settings" class="nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>">Settings</a>
        <a href="?page=teachers" class="nav-link <?= $currentPage === 'teachers' ? 'active' : '' ?>">Teachers</a>
        <a href="?page=courses" class="nav-link <?= $currentPage === 'courses' ? 'active' : '' ?>">Courses</a>
        <a href="?page=categories" class="nav-link <?= $currentPage === 'categories' ? 'active' : '' ?>">Categories</a>
        <a href="?page=groups" class="nav-link <?= $currentPage === 'groups' ? 'active' : '' ?>">Groups</a>
        <a href="?page=schedule" class="nav-link <?= $currentPage === 'schedule' ? 'active' : '' ?>">Schedule</a>
        <a href="?page=statistics" class="nav-link <?= $currentPage === 'statistics' ? 'active' : '' ?>">Statistics</a>
    </div>
</nav>
<main class="container">
<?= renderFlash() ?>
