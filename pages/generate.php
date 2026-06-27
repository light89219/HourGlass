<?php
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ?page=schedule');
    exit;
}

$settings = getSettings();
if (!$settings) {
    flash('error', 'Configure settings first.');
    header('Location: ?page=settings');
    exit;
}

$weekendDays = array_map('intval', array_filter(explode(',', $settings['weekends']), fn($v) => $v !== ''));
$holidays = json_decode($settings['holidays'], true) ?: [];
$dailySlots = $settings['daily_slots'];
$teachingDays = getTeachingDaysOfWeek($weekendDays);

if (empty($teachingDays)) {
    flash('error', 'All days are marked as weekends. Adjust settings.');
    header('Location: ?page=settings');
    exit;
}

$groups = [];
$result = $db->query("SELECT g.*, gc.name as category_name FROM groups g JOIN group_categories gc ON g.category_id = gc.id ORDER BY gc.name, g.name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $groups[] = $row;
}

if (empty($groups)) {
    flash('error', 'Add groups first.');
    header('Location: ?page=groups');
    exit;
}

// Load teacher availability
$teacherAvail = [];
$result = $db->query("SELECT * FROM teacher_availability");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $teacherAvail[$row['teacher_id']][$row['day_of_week']][$row['slot_number']] = true;
}

// Load category courses
$categoryCourses = [];
$result = $db->query("SELECT cc.*, c.name as course_name, c.sub_start_date, c.sub_end_date
    FROM category_courses cc JOIN courses c ON cc.course_id = c.id ORDER BY c.name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $categoryCourses[$row['category_id']][] = $row;
}

// Clear existing schedule
$db->exec("DELETE FROM schedule_slots");

// For each group, determine required weekly slots per course
$groupCourseWeekly = [];
foreach ($groups as $group) {
    $catId = $group['category_id'];
    if (!isset($categoryCourses[$catId])) continue;

    foreach ($categoryCourses[$catId] as $cc) {
        $weeks = getTeachingWeeks(
            $settings['start_date'], $settings['end_date'],
            $weekendDays, $holidays,
            $cc['sub_start_date'], $cc['sub_end_date']
        );
        $weeklySlots = ($weeks > 0) ? (int)ceil($cc['lecture_count'] / $weeks) : $cc['lecture_count'];
        $weeklySlots = max(1, $weeklySlots);
        $groupCourseWeekly[$group['id']][] = [
            'course_id' => $cc['course_id'],
            'teacher_id' => $cc['teacher_id'],
            'weekly_needed' => $weeklySlots,
            'remaining' => $weeklySlots,
            'course_name' => $cc['course_name'],
        ];
    }
}

// Track teacher assignments: $teacherAssigned[day][slot] = teacher_id
$teacherAssigned = [];

// Schedule: iterate over each day and slot, assign courses to groups
$scheduled = [];

foreach ($teachingDays as $dow) {
    for ($slot = 1; $slot <= $dailySlots; $slot++) {
        foreach ($groups as $group) {
            $gid = $group['id'];
            if (!isset($groupCourseWeekly[$gid])) continue;

            $assigned = false;
            foreach ($groupCourseWeekly[$gid] as &$entry) {
                if ($entry['remaining'] <= 0) continue;

                $tid = $entry['teacher_id'];
                $cid = $entry['course_id'];

                // Check teacher availability
                if (!isset($teacherAvail[$tid][$dow][$slot])) continue;

                // Check teacher not double-booked
                if (isset($teacherAssigned[$dow][$slot][$tid])) continue;

                // Assign
                $scheduled[] = [
                    'group_id' => $gid,
                    'day_of_week' => $dow,
                    'slot_number' => $slot,
                    'course_id' => $cid,
                    'teacher_id' => $tid,
                ];
                $teacherAssigned[$dow][$slot][$tid] = true;
                $entry['remaining']--;
                $assigned = true;
                break;
            }
            unset($entry);

            // If nothing assigned, insert empty slot
            if (!$assigned) {
                $scheduled[] = [
                    'group_id' => $gid,
                    'day_of_week' => $dow,
                    'slot_number' => $slot,
                    'course_id' => null,
                    'teacher_id' => null,
                ];
            }
        }
    }
}

// Insert all scheduled slots
$stmt = $db->prepare("INSERT INTO schedule_slots (group_id, day_of_week, slot_number, course_id, teacher_id)
    VALUES (:g, :d, :s, :c, :t)");

foreach ($scheduled as $s) {
    $stmt->bindValue(':g', $s['group_id'], SQLITE3_INTEGER);
    $stmt->bindValue(':d', $s['day_of_week'], SQLITE3_INTEGER);
    $stmt->bindValue(':s', $s['slot_number'], SQLITE3_INTEGER);
    $stmt->bindValue(':c', $s['course_id'], $s['course_id'] ? SQLITE3_INTEGER : SQLITE3_NULL);
    $stmt->bindValue(':t', $s['teacher_id'], $s['teacher_id'] ? SQLITE3_INTEGER : SQLITE3_NULL);
    $stmt->execute();
    $stmt->reset();
}

// Check for unplaced courses
$warnings = [];
foreach ($groups as $group) {
    $gid = $group['id'];
    if (!isset($groupCourseWeekly[$gid])) continue;
    foreach ($groupCourseWeekly[$gid] as $entry) {
        if ($entry['remaining'] > 0) {
            $warnings[] = "Group {$group['name']}: could not place {$entry['remaining']} weekly slot(s) for \"{$entry['course_name']}\"";
        }
    }
}

if (!empty($warnings)) {
    flash('error', 'Schedule generated with warnings: ' . implode('; ', $warnings));
} else {
    flash('success', 'Schedule generated successfully! All courses placed.');
}

header('Location: ?page=schedule');
exit;
