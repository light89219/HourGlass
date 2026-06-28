<?php
require_once __DIR__ . '/db.php';

$db = getDB();

// Clear all existing data
$db->exec("DELETE FROM lecture_log");
$db->exec("DELETE FROM schedule_slots");
$db->exec("DELETE FROM category_courses");
$db->exec("DELETE FROM groups");
$db->exec("DELETE FROM group_categories");
$db->exec("DELETE FROM teacher_availability");
$db->exec("DELETE FROM teachers");
$db->exec("DELETE FROM courses");
$db->exec("DELETE FROM settings");

echo "Cleared existing data.\n";

// 1. Settings: Sep 1 - Dec 20, 2026, 6 slots/day, weekends = Sun + Sat
$db->exec("INSERT INTO settings (id, start_date, end_date, daily_slots, weekends, holidays)
    VALUES (1, '2026-09-01', '2026-12-20', 6, '0,6', '[\"2026-10-03\",\"2026-10-09\",\"2026-12-25\"]')");
echo "Settings: OK\n";

// 2. Teachers (30)
$teachers = [
    'Prof. Smith', 'Prof. Johnson', 'Prof. Williams', 'Prof. Brown',
    'Prof. Taylor', 'Prof. Davies', 'Prof. Wilson', 'Prof. Evans',
    'Prof. Thomas', 'Prof. Roberts', 'Prof. Walker', 'Prof. Wright',
    'Prof. Thompson', 'Prof. White', 'Prof. Hughes', 'Prof. Edwards',
    'Prof. Green', 'Prof. Hall', 'Prof. Lewis', 'Prof. Harris',
    'Prof. Clarke', 'Prof. Jackson', 'Prof. Wood', 'Prof. Turner',
    'Prof. Martin', 'Prof. Cooper', 'Prof. Hill', 'Prof. Ward',
    'Prof. Morris', 'Prof. Moore'
];
foreach ($teachers as $t) {
    $stmt = $db->prepare("INSERT INTO teachers (name) VALUES (:n)");
    $stmt->bindValue(':n', $t, SQLITE3_TEXT);
    $stmt->execute();
}
echo "Teachers: " . count($teachers) . "\n";

// 3. Teacher availability (all teachers available all teaching day slots by default)
$teachingDays = [1, 2, 3, 4, 5]; // Mon-Fri
$dailySlots = 6;
$result = $db->query("SELECT id FROM teachers");
$teacherIds = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $teacherIds[] = $row['id'];
}

foreach ($teacherIds as $tid) {
    foreach ($teachingDays as $dow) {
        for ($s = 1; $s <= $dailySlots; $s++) {
            $db->exec("INSERT INTO teacher_availability (teacher_id, day_of_week, slot_number) VALUES ($tid, $dow, $s)");
        }
    }
}

// Restrict some teachers
$db->exec("DELETE FROM teacher_availability WHERE teacher_id = 8 AND slot_number < 4");          // Prof. Evans: afternoon only (slots 4-6)
$db->exec("DELETE FROM teacher_availability WHERE teacher_id = 15 AND day_of_week IN (1, 3)");   // Prof. Hughes: Tue/Thu/Fri only
$db->exec("DELETE FROM teacher_availability WHERE teacher_id = 22 AND slot_number > 3");         // Prof. Jackson: morning only (slots 1-3)

echo "Teacher availability: set (Evans=afternoon, Hughes=Tue/Thu/Fri, Jackson=morning)\n";

// 4. Courses
$courses = [
    ['Mathematics', null, null],
    ['Physics', null, null],
    ['Chemistry', null, null],
    ['English', null, null],
    ['Korean Literature', null, null],
    ['History', null, null],
    ['Computer Science', null, null],
    ['Biology', null, null],
    ['Art', '2026-09-01', '2026-10-31'],
    ['Physical Education', null, null],
    ['Music', '2026-11-01', '2026-12-20'],
    ['Economics', null, null],
];
foreach ($courses as $c) {
    $stmt = $db->prepare("INSERT INTO courses (name, sub_start_date, sub_end_date) VALUES (:n, :ss, :se)");
    $stmt->bindValue(':n', $c[0], SQLITE3_TEXT);
    $stmt->bindValue(':ss', $c[1], $c[1] ? SQLITE3_TEXT : SQLITE3_NULL);
    $stmt->bindValue(':se', $c[2], $c[2] ? SQLITE3_TEXT : SQLITE3_NULL);
    $stmt->execute();
}
echo "Courses: " . count($courses) . " (Art=Sep-Oct only, Music=Nov-Dec only)\n";

// 5. Group Categories
$db->exec("INSERT INTO group_categories (name) VALUES ('Year 1')");
$db->exec("INSERT INTO group_categories (name) VALUES ('Year 2')");
echo "Categories: Year 1, Year 2\n";

// 6. Category Courses (course_id, teacher_id, lecture_count)
// Each course gets a unique teacher — 30 teachers available, no sharing needed
// Year 1 courses
$year1 = [
    [1,  1,  24],  // Mathematics   - Prof. Smith    - 24 lectures (Mon/Wed/Fri)
    [1,  17, 24],  // Mathematics   - Prof. Green    - 24 lectures (all days)
    [2,  2,  32],  // Physics       - Prof. Johnson  - 32
    [4,  3,  32],  // English       - Prof. Williams - 32
    [5,  4,  16],  // Korean Lit    - Prof. Brown    - 16
    [6,  5,  16],  // History       - Prof. Taylor   - 16
    [7,  6,  32],  // CS            - Prof. Davies   - 32
    [9,  7,  16],  // Art           - Prof. Wilson   - 16
    [10, 8,  16],  // PE            - Prof. Evans    - 16
];
foreach ($year1 as $c) {
    $db->exec("INSERT INTO category_courses (category_id, course_id, teacher_id, lecture_count)
        VALUES (1, {$c[0]}, {$c[1]}, {$c[2]})");
}

// Year 2 courses — different teachers from Year 1 to reduce conflicts
$year2 = [
    [1,  9,  48],  // Mathematics   - Prof. Thomas   - 48 lectures
    [3,  10, 32],  // Chemistry     - Prof. Roberts  - 32
    [4,  11, 32],  // English       - Prof. Walker   - 32
    [8,  12, 16],  // Biology       - Prof. Wright   - 16
    [12, 13, 16],  // Economics     - Prof. Thompson - 16
    [7,  14, 32],  // CS            - Prof. White    - 32
    [11, 15, 16],  // Music         - Prof. Hughes   - 16
    [6,  16, 16],  // History       - Prof. Edwards  - 16
];
foreach ($year2 as $c) {
    $db->exec("INSERT INTO category_courses (category_id, course_id, teacher_id, lecture_count)
        VALUES (2, {$c[0]}, {$c[1]}, {$c[2]})");
}
echo "Course assignments: Year 1 = " . count($year1) . " courses, Year 2 = " . count($year2) . " courses\n";

// 7. Groups
$db->exec("INSERT INTO groups (name, category_id) VALUES ('A', 1)");
$db->exec("INSERT INTO groups (name, category_id) VALUES ('B', 1)");
$db->exec("INSERT INTO groups (name, category_id) VALUES ('C', 1)");
$db->exec("INSERT INTO groups (name, category_id) VALUES ('D', 2)");
$db->exec("INSERT INTO groups (name, category_id) VALUES ('E', 2)");
echo "Groups: A, B, C (Year 1), D, E (Year 2)\n";

echo "\n=== Seed complete! ===\n";
echo "Schedule period: 2026-09-01 ~ 2026-12-20\n";
echo "Holidays: Oct 3, Oct 9, Dec 25\n";
echo "5 groups, 30 teachers, 12 courses\n";
echo "\nNext: open the app and click 'Auto-Generate Schedule' on the Schedule page.\n";
