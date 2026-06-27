<?php
define('APP_NAME', 'University Schedule Manager');
define('DB_PATH', __DIR__ . '/data/schedule.db');

function getDB(): SQLite3 {
    static $db = null;
    if ($db === null) {
        $db = new SQLite3(DB_PATH);
        $db->enableExceptions(true);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA foreign_keys = ON');
    }
    return $db;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function renderFlash(): string {
    if (empty($_SESSION['flash'])) return '';
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $cls = htmlspecialchars($f['type']);
        $msg = htmlspecialchars($f['message']);
        $html .= "<div class=\"flash flash-{$cls}\">{$msg}</div>";
    }
    $_SESSION['flash'] = [];
    return $html;
}

session_start();
