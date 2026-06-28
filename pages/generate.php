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

// Fill ALL available slots proportionally by lecture count.
// Instead of distributing thinly over the entire period, pack every slot from Monday.
$totalAvailableSlots = $dailySlots * count($teachingDays);

$groupEntries = [];
$groupRemaining = [];
foreach ($groups as $group) {
    $catId = $group['category_id'];
    if (!isset($categoryCourses[$catId])) continue;

    $catEntries = $categoryCourses[$catId];
    $totalLectures = array_sum(array_column($catEntries, 'lecture_count'));
    if ($totalLectures === 0) continue;

    // Largest remainder method: allocate weekly slots proportional to lecture count
    $floorShares = [];
    $remainders = [];
    foreach ($catEntries as $i => $cc) {
        $raw = $cc['lecture_count'] / $totalLectures * $totalAvailableSlots;
        $floorShares[$i] = max(1, (int)floor($raw));
        $remainders[$i] = $raw - $floorShares[$i];
    }

    $allocated = array_sum($floorShares);
    $toDistribute = $totalAvailableSlots - $allocated;

    arsort($remainders);
    foreach ($remainders as $i => $rem) {
        if ($toDistribute <= 0) break;
        $floorShares[$i]++;
        $toDistribute--;
    }

    foreach ($catEntries as $i => $cc) {
        $groupEntries[$group['id']][$i] = [
            'course_id' => $cc['course_id'],
            'teacher_id' => $cc['teacher_id'],
            'weekly_needed' => $floorShares[$i],
            'course_name' => $cc['course_name'],
        ];
        $groupRemaining[$group['id']][$i] = $floorShares[$i];
    }
}

// Greedy assignment: iterate day/slot in order, for each group pick best course
// Scoring priorities:
//   1. Avoid consecutive same course in adjacent slots
//   2. Prefer courses that appeared LEAST today (spread across days)
//   3. Among ties, prefer courses with most remaining (fill slots fully)

$teacherAssigned = []; // [dow][slot][teacher_id] = true
$scheduled = [];
$groupLastCourse = []; // [gid] => course_id of last assigned slot
$groupDayCount = [];   // [gid][dow][course_id] => count of appearances today

// Calculate ideal daily limit per course based on teacher's actual available days
$groupDailyLimit = [];
$numTeachingDays = count($teachingDays);
foreach ($groups as $group) {
    $gid = $group['id'];
    if (!isset($groupEntries[$gid])) continue;
    foreach ($groupEntries[$gid] as $i => $e) {
        $tid = $e['teacher_id'];
        $availDays = 0;
        foreach ($teachingDays as $dow) {
            if (isset($teacherAvail[$tid][$dow])) $availDays++;
        }
        $availDays = max(1, $availDays);
        $groupDailyLimit[$gid][$i] = (int)ceil($e['weekly_needed'] / $availDays);
    }
}

// Round-robin: fill slot 1 across all days, then slot 2, etc.
// This distributes courses evenly across days instead of clustering on early days.
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

            // Pass 1: find best course that is different from previous slot
            foreach ($groupRemaining[$gid] as $i => $rem) {
                if ($rem <= 0) continue;
                $e = $groupEntries[$gid][$i];
                if ($e['course_id'] === $lastCid) continue;
                if (!isset($teacherAvail[$e['teacher_id']][$dow][$slot])) continue;
                if (isset($teacherAssigned[$dow][$slot][$e['teacher_id']])) continue;

                $todayCount = $groupDayCount[$gid][$dow][$e['course_id']] ?? 0;
                $dailyLimit = $groupDailyLimit[$gid][$i];
                // Strong penalty for exceeding daily limit, then prefer least-used today
                $score = -$todayCount * 1000;
                if ($todayCount >= $dailyLimit) $score -= 5000;
                $score += $rem;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIdx = $i;
                }
            }

            // Pass 2: if nothing found, allow consecutive same course
            if ($bestIdx === -1) {
                foreach ($groupRemaining[$gid] as $i => $rem) {
                    if ($rem <= 0) continue;
                    $e = $groupEntries[$gid][$i];
                    if (!isset($teacherAvail[$e['teacher_id']][$dow][$slot])) continue;
                    if (isset($teacherAssigned[$dow][$slot][$e['teacher_id']])) continue;

                    $todayCount = $groupDayCount[$gid][$dow][$e['course_id']] ?? 0;
                    $dailyLimit = $groupDailyLimit[$gid][$i];
                    $score = -$todayCount * 1000;
                    if ($todayCount >= $dailyLimit) $score -= 5000;
                    $score += $rem;
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestIdx = $i;
                    }
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
    flash('success', 'Schedule generated successfully! All courses placed.');
}

header('Location: ?page=schedule');
exit;
