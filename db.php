<?php
require_once __DIR__ . '/config.php';

function initDatabase() {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        daily_slots INTEGER NOT NULL DEFAULT 6,
        weekends TEXT NOT NULL DEFAULT '0,6',
        holidays TEXT NOT NULL DEFAULT '[]'
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS teachers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS teacher_availability (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        teacher_id INTEGER NOT NULL,
        day_of_week INTEGER NOT NULL,
        slot_number INTEGER NOT NULL,
        FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
        UNIQUE(teacher_id, day_of_week, slot_number)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS group_categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        category_id INTEGER NOT NULL,
        FOREIGN KEY (category_id) REFERENCES group_categories(id) ON DELETE CASCADE,
        UNIQUE(name, category_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS courses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        sub_start_date TEXT,
        sub_end_date TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS category_courses (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        category_id INTEGER NOT NULL,
        course_id INTEGER NOT NULL,
        teacher_id INTEGER NOT NULL,
        lecture_count INTEGER NOT NULL DEFAULT 1,
        FOREIGN KEY (category_id) REFERENCES group_categories(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
        UNIQUE(category_id, course_id, teacher_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS schedule_slots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER NOT NULL,
        day_of_week INTEGER NOT NULL,
        slot_number INTEGER NOT NULL,
        course_id INTEGER,
        teacher_id INTEGER,
        FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
        FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,
        UNIQUE(group_id, day_of_week, slot_number)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS daily_schedule (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        schedule_date TEXT NOT NULL,
        group_id INTEGER NOT NULL,
        slot_number INTEGER NOT NULL,
        course_id INTEGER,
        teacher_id INTEGER,
        status TEXT NOT NULL DEFAULT 'scheduled',
        FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,
        UNIQUE(schedule_date, group_id, slot_number)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS lecture_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id INTEGER NOT NULL,
        course_id INTEGER NOT NULL,
        lecture_date TEXT NOT NULL,
        slot_number INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'done',
        FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
        FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
        UNIQUE(group_id, lecture_date, slot_number)
    )");
}

function getSettings() {
    $db = getDB();
    $result = $db->query("SELECT * FROM settings WHERE id = 1");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}

function getDayName($dow) {
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return $days[$dow] ?? '';
}

function getTeachingWeeks($startDate, $endDate, $weekendDays, $holidays, $subStart = null, $subEnd = null) {
    $start = new DateTime($subStart ?: $startDate);
    $end = new DateTime($subEnd ?: $endDate);
    $teachingDays = 0;
    $current = clone $start;
    while ($current <= $end) {
        $dow = (int)$current->format('w');
        $dateStr = $current->format('Y-m-d');
        if (!in_array($dow, $weekendDays) && !in_array($dateStr, $holidays)) {
            $teachingDays++;
        }
        $current->modify('+1 day');
    }
    $daysPerWeek = 7 - count($weekendDays);
    if ($daysPerWeek <= 0) return 0;
    return max(1, (int)ceil($teachingDays / $daysPerWeek));
}

function getTeachingDaysOfWeek($weekendDays) {
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        if (!in_array($i, $weekendDays)) {
            $days[] = $i;
        }
    }
    return $days;
}

initDatabase();
