<?php
$db = getDB();
$settings = getSettings();

if (!$settings) {
    echo '<div class="card"><p class="empty">Configure settings first. <a href="?page=settings">Go to Settings</a></p></div>';
    return;
}

$weekendDays = array_map('intval', array_filter(explode(',', $settings['weekends']), fn($v) => $v !== ''));
$holidays = json_decode($settings['holidays'], true) ?: [];
$teachingDays = getTeachingDaysOfWeek($weekendDays);
$dailySlots = $settings['daily_slots'];

// Handle marking lectures
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_date') {
        $markDate = $_POST['mark_date'] ?? '';
        if ($markDate) {
            $dateDow = (int)(new DateTime($markDate))->format('w');
            $dateStr = $markDate;

            if (in_array($dateDow, $weekendDays)) {
                flash('error', 'This date is a weekend day.');
            } elseif (in_array($dateStr, $holidays)) {
                flash('error', 'This date is a holiday.');
            } else {
                // Get all schedule slots for this day of week
                $stmt = $db->prepare("SELECT ss.group_id, ss.course_id, ss.slot_number
                    FROM schedule_slots ss WHERE ss.day_of_week = :d AND ss.course_id IS NOT NULL");
                $stmt->bindValue(':d', $dateDow, SQLITE3_INTEGER);
                $res = $stmt->execute();

                $count = 0;
                while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
                    // Check course sub-range
                    $cStmt = $db->prepare("SELECT sub_start_date, sub_end_date FROM courses WHERE id = :id");
                    $cStmt->bindValue(':id', $row['course_id'], SQLITE3_INTEGER);
                    $course = $cStmt->execute()->fetchArray(SQLITE3_ASSOC);

                    if ($course['sub_start_date'] && $dateStr < $course['sub_start_date']) continue;
                    if ($course['sub_end_date'] && $dateStr > $course['sub_end_date']) continue;

                    $ins = $db->prepare("INSERT OR IGNORE INTO lecture_log (group_id, course_id, lecture_date, slot_number, status)
                        VALUES (:g, :c, :d, :s, 'done')");
                    $ins->bindValue(':g', $row['group_id'], SQLITE3_INTEGER);
                    $ins->bindValue(':c', $row['course_id'], SQLITE3_INTEGER);
                    $ins->bindValue(':d', $dateStr, SQLITE3_TEXT);
                    $ins->bindValue(':s', $row['slot_number'], SQLITE3_INTEGER);
                    $ins->execute();
                    $count++;
                }
                flash('success', "Marked $count lectures as done for $dateStr.");
            }
        }
        header('Location: ?page=statistics');
        exit;
    }

    if ($action === 'unmark_date') {
        $markDate = $_POST['mark_date'] ?? '';
        if ($markDate) {
            $stmt = $db->prepare("DELETE FROM lecture_log WHERE lecture_date = :d");
            $stmt->bindValue(':d', $markDate, SQLITE3_TEXT);
            $stmt->execute();
            flash('success', "Unmarked all lectures for $markDate.");
        }
        header('Location: ?page=statistics');
        exit;
    }
}

// Load groups with category info
$groups = [];
$result = $db->query("SELECT g.*, gc.name as category_name FROM groups g JOIN group_categories gc ON g.category_id = gc.id ORDER BY gc.name, g.name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $groups[] = $row;
}

// Calculate teaching dates from start to today (or end date)
$today = date('Y-m-d');
$endCompare = min($today, $settings['end_date']);

// Count teaching dates that have passed
$teachingDatesPassed = 0;
$totalTeachingDates = 0;
$current = new DateTime($settings['start_date']);
$endDt = new DateTime($settings['end_date']);
while ($current <= $endDt) {
    $dow = (int)$current->format('w');
    $dateStr = $current->format('Y-m-d');
    if (!in_array($dow, $weekendDays) && !in_array($dateStr, $holidays)) {
        $totalTeachingDates++;
        if ($dateStr <= $endCompare) {
            $teachingDatesPassed++;
        }
    }
    $current->modify('+1 day');
}

// Marked dates
$markedDates = [];
$result = $db->query("SELECT DISTINCT lecture_date FROM lecture_log ORDER BY lecture_date");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $markedDates[] = $row['lecture_date'];
}
?>

<div class="page-header">
    <h1>Statistics</h1>
</div>

<!-- Summary -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= $totalTeachingDates ?></div>
        <div class="stat-label">Total Teaching Days</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $teachingDatesPassed ?></div>
        <div class="stat-label">Days Passed</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= count($markedDates) ?></div>
        <div class="stat-label">Days Marked Done</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $totalTeachingDates - $teachingDatesPassed ?></div>
        <div class="stat-label">Days Remaining</div>
    </div>
</div>

<!-- Mark lectures -->
<div class="card">
    <h2>Mark Lectures</h2>
    <div class="form-row">
        <form method="POST" class="form-row" style="flex: 1;">
            <input type="hidden" name="action" value="mark_date">
            <div class="form-group">
                <label>Date</label>
                <input type="date" name="mark_date" value="<?= $today ?>" required
                    min="<?= htmlspecialchars($settings['start_date']) ?>"
                    max="<?= htmlspecialchars($settings['end_date']) ?>">
            </div>
            <button type="submit" class="btn btn-success">Mark as Done</button>
        </form>
        <form method="POST" class="form-row">
            <input type="hidden" name="action" value="unmark_date">
            <div class="form-group">
                <label>Date to Unmark</label>
                <input type="date" name="mark_date" required
                    min="<?= htmlspecialchars($settings['start_date']) ?>"
                    max="<?= htmlspecialchars($settings['end_date']) ?>">
            </div>
            <button type="submit" class="btn btn-danger">Unmark</button>
        </form>
    </div>
    <?php if (!empty($markedDates)): ?>
    <div style="margin-top: 12px;">
        <strong style="font-size: 12px;">Marked dates:</strong>
        <div class="holiday-list">
            <?php foreach ($markedDates as $md): ?>
                <span class="holiday-item" style="background: #dcfce7; border-color: #bbf7d0;"><?= htmlspecialchars($md) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Per-group statistics -->
<?php foreach ($groups as $group): ?>
<?php
$catId = $group['category_id'];
$gid = $group['id'];

// Get category courses
$stmt = $db->prepare("SELECT cc.*, c.name as course_name, c.sub_start_date, c.sub_end_date, t.name as teacher_name
    FROM category_courses cc
    JOIN courses c ON cc.course_id = c.id
    JOIN teachers t ON cc.teacher_id = t.id
    WHERE cc.category_id = :cat ORDER BY c.name");
$stmt->bindValue(':cat', $catId, SQLITE3_INTEGER);
$res = $stmt->execute();
$catCourses = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $catCourses[] = $row;
}
if (empty($catCourses)) continue;
?>
<div class="card">
    <h2><?= htmlspecialchars($group['category_name']) ?> - Group <?= htmlspecialchars($group['name']) ?></h2>
    <table>
        <thead>
            <tr>
                <th>Course</th>
                <th>Teacher</th>
                <th style="width: 100px;">Required</th>
                <th style="width: 100px;">Done</th>
                <th style="width: 100px;">Remaining</th>
                <th style="width: 200px;">Progress</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($catCourses as $cc): ?>
            <?php
            $stmt2 = $db->prepare("SELECT COUNT(*) as cnt FROM lecture_log WHERE group_id = :g AND course_id = :c AND status = 'done'");
            $stmt2->bindValue(':g', $gid, SQLITE3_INTEGER);
            $stmt2->bindValue(':c', $cc['course_id'], SQLITE3_INTEGER);
            $done = $stmt2->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];
            $required = $cc['lecture_count'];
            $remaining = max(0, $required - $done);
            $pct = $required > 0 ? round(($done / $required) * 100) : 0;
            $barClass = $pct >= 80 ? 'green' : ($pct >= 40 ? 'yellow' : 'red');
            ?>
            <tr>
                <td><?= htmlspecialchars($cc['course_name']) ?></td>
                <td><?= htmlspecialchars($cc['teacher_name']) ?></td>
                <td><?= $required ?></td>
                <td><strong><?= $done ?></strong></td>
                <td>
                    <?php if ($remaining > 0): ?>
                        <span style="color: var(--danger);"><?= $remaining ?></span>
                    <?php else: ?>
                        <span class="tag tag-green">Complete</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div class="progress-bar" style="flex: 1;">
                            <div class="fill <?= $barClass ?>" style="width: <?= min(100, $pct) ?>%;"></div>
                        </div>
                        <span style="font-size: 11px; color: var(--text-light); width: 35px;"><?= $pct ?>%</span>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>
