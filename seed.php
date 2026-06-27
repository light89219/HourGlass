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

// 2. Teachers
$teachers = [
    'Prof. Smith', 'Prof. Johnson', 'Prof. Williams', 'Prof. Brown',
    'Prof. Taylor', 'Prof. Davies', 'Prof. Wilson', 'Prof. Evans'
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

// Restrict Prof. Kim: only available Mon/Wed/Fri
$db->exec("DELETE FROM teacher_availability WHERE teacher_id = 1 AND day_of_week IN (2, 4)");
// Restrict Prof. Han: only available afternoons (slots 4-6)
$db->exec("DELETE FROM teacher_availability WHERE teacher_id = 8 AND slot_number < 4");

echo "Teacher availability: set (Prof. Smith=Mon/Wed/Fri only, Prof. Evans=afternoon only)\n";

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
    ['Art', '2026-09-01', '2026-10-31'],          // sub-range: first 2 months only
    ['Physical Education', null, null],
    ['Music', '2026-11-01', '2026-12-20'],         // sub-range: last 2 months only
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

// 6. Category Courses (category_id, course_id, teacher_id, lecture_count)
// Year 1 courses
$year1 = [
    [1, 1, 48],  // Mathematics - Prof. Kim - 48 lectures
    [2, 2, 32],  // Physics - Prof. Lee - 32
    [4, 3, 32],  // English - Prof. Choi - 32
    [5, 4, 16],  // Korean Lit - Prof. Jung - 16
    [6, 5, 16],  // History - Prof. Kang - 16
    [7, 6, 32],  // CS - Prof. Yoon - 32
    [9, 7, 16],  // Art - Prof. Han (sub-range) - 16
    [10, 3, 16], // PE - Prof. Choi - 16
];
foreach ($year1 as $c) {
    $db->exec("INSERT INTO category_courses (category_id, course_id, teacher_id, lecture_count)
        VALUES (1, {$c[0]}, {$c[1]}, {$c[2]})");
}

// Year 2 courses
$year2 = [
    [1, 1, 48],  // Mathematics - Prof. Kim - 48
    [3, 2, 32],  // Chemistry - Prof. Lee - 32
    [4, 4, 32],  // English - Prof. Choi - 32
    [8, 5, 16],  // Biology - Prof. Kang - 16
    [12, 6, 16], // Economics - Prof. Yoon - 16
    [7, 7, 32],  // CS - Prof. Han - 32
    [11, 3, 16], // Music (sub-range) - Prof. Choi - 16
    [6, 8, 16],  // History - Prof. Han - 16
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
echo "5 groups, 8 teachers, 12 courses\n";
echo "\nNext: open the app and click 'Auto-Generate Schedule' on the Schedule page.\n";
