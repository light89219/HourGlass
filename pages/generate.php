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

$weekendDays = array_map('intval', array_filter(explode(',', $settings['weekends']), function($v) { return $v !== ''; }));
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

$teacherAvail = [];
$result = $db->query("SELECT * FROM teacher_availability");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $teacherAvail[$row['teacher_id']][$row['day_of_week']][$row['slot_number']] = true;
}

$categoryCourses = [];
$result = $db->query("SELECT cc.*, c.name as course_name, c.sub_start_date, c.sub_end_date
    FROM category_courses cc JOIN courses c ON cc.course_id = c.id ORDER BY c.name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $categoryCourses[$row['category_id']][] = $row;
}

// Clear existing schedules
$db->exec("DELETE FROM schedule_slots");
$db->exec("DELETE FROM daily_schedule");

// ============================================================
// STEP 1: Even-distribution allocation
// Each course gets ceil(lecture_count / teaching_weeks) per week
// so ALL courses finish near the end date at similar progress.
// ============================================================
$numTeachingDays = count($teachingDays);

$groupEntries = [];
$groupRemaining = [];
$groupDailyLimit = [];
foreach ($groups as $group) {
    $catId = $group['category_id'];
    if (!isset($categoryCourses[$catId])) continue;

    foreach ($categoryCourses[$catId] as $i => $cc) {
        $weeks = getTeachingWeeks(
            $settings['start_date'], $settings['end_date'],
            $weekendDays, $holidays,
            $cc['sub_start_date'], $cc['sub_end_date']
        );
        $weeklySlots = ($weeks > 0) ? (int)ceil($cc['lecture_count'] / $weeks) : $cc['lecture_count'];
        $weeklySlots = max(1, $weeklySlots);

        $tid = $cc['teacher_id'];
        $availDays = 0;
        foreach ($teachingDays as $dow) {
            if (isset($teacherAvail[$tid][$dow])) $availDays++;
        }

        $idx = count($groupEntries[$group['id']] ?? []);
        $groupEntries[$group['id']][$idx] = [
            'course_id' => $cc['course_id'],
            'teacher_id' => $tid,
            'weekly_needed' => $weeklySlots,
            'course_name' => $cc['course_name'],
        ];
        $groupRemaining[$group['id']][$idx] = $weeklySlots;
        $groupDailyLimit[$group['id']][$idx] = (int)ceil($weeklySlots / max(1, $availDays));
    }
}

// ============================================================
// STEP 2: Build weekly template (round-robin, spread across days)
// ============================================================
$teacherAssigned = [];
$scheduled = [];
$groupLastCourse = [];
$groupDayCount = [];

for ($slot = 1; $slot <= $dailySlots; $slot++) {
    foreach ($teachingDays as $dow) {
        if ($slot === 1) $groupLastCourse = [];
        foreach ($groups as $group) {
            $gid = $group['id'];
            if (!isset($groupEntries[$gid])) {
                $scheduled[] = [
                    'group_id' => $gid, 'day_of_week' => $dow,
                    'slot_number' => $slot, 'course_id' => null, 'teacher_id' => null,
                ];
                continue;
            }

            $lastCid = $groupLastCourse[$gid] ?? null;
            $bestIdx = -1;
            $bestScore = -PHP_INT_MAX;

            // Pass 1: different from previous slot
            foreach ($groupRemaining[$gid] as $i => $rem) {
                if ($rem <= 0) continue;
                $e = $groupEntries[$gid][$i];
                if ($e['course_id'] === $lastCid) continue;
                if (!isset($teacherAvail[$e['teacher_id']][$dow][$slot])) continue;
                if (isset($teacherAssigned[$dow][$slot][$e['teacher_id']])) continue;

                $todayCount = $groupDayCount[$gid][$dow][$e['course_id']] ?? 0;
                $limit = $groupDailyLimit[$gid][$i];
                $score = -$todayCount * 1000;
                if ($todayCount >= $limit) $score -= 5000;
                $score += $rem;
                if ($score > $bestScore) { $bestScore = $score; $bestIdx = $i; }
            }

            // Pass 2: allow consecutive
            if ($bestIdx === -1) {
                foreach ($groupRemaining[$gid] as $i => $rem) {
                    if ($rem <= 0) continue;
                    $e = $groupEntries[$gid][$i];
                    if (!isset($teacherAvail[$e['teacher_id']][$dow][$slot])) continue;
                    if (isset($teacherAssigned[$dow][$slot][$e['teacher_id']])) continue;

                    $todayCount = $groupDayCount[$gid][$dow][$e['course_id']] ?? 0;
                    $limit = $groupDailyLimit[$gid][$i];
                    $score = -$todayCount * 1000;
                    if ($todayCount >= $limit) $score -= 5000;
                    $score += $rem;
                    if ($score > $bestScore) { $bestScore = $score; $bestIdx = $i; }
                }
            }

            if ($bestIdx >= 0) {
                $e = $groupEntries[$gid][$bestIdx];
                $scheduled[] = [
                    'group_id' => $gid, 'day_of_week' => $dow,
                    'slot_number' => $slot, 'course_id' => $e['course_id'], 'teacher_id' => $e['teacher_id'],
                ];
                $teacherAssigned[$dow][$slot][$e['teacher_id']] = true;
                $groupRemaining[$gid][$bestIdx]--;
                $groupLastCourse[$gid] = $e['course_id'];
                $groupDayCount[$gid][$dow][$e['course_id']] = ($groupDayCount[$gid][$dow][$e['course_id']] ?? 0) + 1;
            } else {
                $scheduled[] = [
                    'group_id' => $gid, 'day_of_week' => $dow,
                    'slot_number' => $slot, 'course_id' => null, 'teacher_id' => null,
                ];
                $groupLastCourse[$gid] = null;
            }
        }
    }
}

// Insert weekly template
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

// ============================================================
// STEP 3: Expand weekly template into daily_schedule
// ============================================================
$weeklyTemplate = [];
foreach ($scheduled as $s) {
    $weeklyTemplate[$s['group_id']][$s['day_of_week']][$s['slot_number']] = $s;
}

// Build course sub-range lookup
$courseRanges = [];
$result = $db->query("SELECT id, sub_start_date, sub_end_date FROM courses");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $courseRanges[$row['id']] = $row;
}

$insertDaily = $db->prepare("INSERT INTO daily_schedule (schedule_date, group_id, slot_number, course_id, teacher_id, status)
    VALUES (:dt, :g, :s, :c, :t, 'scheduled')");

$current = new DateTime($settings['start_date']);
$end = new DateTime($settings['end_date']);
$dailyCount = 0;

while ($current <= $end) {
    $dow = (int)$current->format('w');
    $dateStr = $current->format('Y-m-d');

    if (!in_array($dow, $weekendDays) && !in_array($dateStr, $holidays)) {
        foreach ($groups as $group) {
            $gid = $group['id'];
            for ($slot = 1; $slot <= $dailySlots; $slot++) {
                $tmpl = $weeklyTemplate[$gid][$dow][$slot] ?? null;
                $courseId = $tmpl['course_id'] ?? null;
                $teacherId = $tmpl['teacher_id'] ?? null;

                // Check course sub-range
                if ($courseId && isset($courseRanges[$courseId])) {
                    $cr = $courseRanges[$courseId];
                    if ($cr['sub_start_date'] && $dateStr < $cr['sub_start_date']) {
                        $courseId = null;
                        $teacherId = null;
                    }
                    if ($cr['sub_end_date'] && $dateStr > $cr['sub_end_date']) {
                        $courseId = null;
                        $teacherId = null;
                    }
                }

                $insertDaily->bindValue(':dt', $dateStr, SQLITE3_TEXT);
                $insertDaily->bindValue(':g', $gid, SQLITE3_INTEGER);
                $insertDaily->bindValue(':s', $slot, SQLITE3_INTEGER);
                $insertDaily->bindValue(':c', $courseId, $courseId ? SQLITE3_INTEGER : SQLITE3_NULL);
                $insertDaily->bindValue(':t', $teacherId, $teacherId ? SQLITE3_INTEGER : SQLITE3_NULL);
                $insertDaily->execute();
                $insertDaily->reset();
                $dailyCount++;
            }
        }
    }
    $current->modify('+1 day');
}

// Check for unplaced courses in weekly template
$warnings = [];
foreach ($groups as $group) {
    $gid = $group['id'];
    if (!isset($groupEntries[$gid])) continue;
    foreach ($groupEntries[$gid] as $i => $entry) {
        if ($groupRemaining[$gid][$i] > 0) {
            $warnings[] = "Group {$group['name']}: could not place {$groupRemaining[$gid][$i]} weekly slot(s) for \"{$entry['course_name']}\"";
        }
    }
}

if (!empty($warnings)) {
    flash('error', 'Schedule generated with warnings: ' . implode('; ', $warnings));
} else {
    flash('success', "Schedule generated! Weekly template + $dailyCount daily slots created.");
}

header('Location: ?page=schedule');
exit;
