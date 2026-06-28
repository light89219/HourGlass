<?php
$db = getDB();
$settings = getSettings();

if (!$settings) {
    echo '<div class="card"><p class="empty">Configure settings first. <a href="?page=settings">Go to Settings</a></p></div>';
    return;
}

$weekendDays = array_map('intval', array_filter(explode(',', $settings['weekends']), function($v) { return $v !== ''; }));
$holidays = json_decode($settings['holidays'], true) ?: [];
$teachingDays = getTeachingDaysOfWeek($weekendDays);
$dailySlots = $settings['daily_slots'];

// Handle daily slot edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_daily_slot') {
    $date = $_POST['schedule_date'] ?? '';
    $groupId = (int)$_POST['group_id'];
    $slotNum = (int)$_POST['slot_number'];
    $courseId = (int)($_POST['course_id'] ?? 0) ?: null;
    $teacherId = (int)($_POST['teacher_id'] ?? 0) ?: null;
    $status = $_POST['status'] ?? 'scheduled';

    if ($teacherId && $courseId && $status !== 'cancelled') {
        $stmt = $db->prepare("SELECT ds.*, g.name as group_name FROM daily_schedule ds
            JOIN groups g ON ds.group_id = g.id
            WHERE ds.schedule_date = :dt AND ds.slot_number = :s AND ds.teacher_id = :t AND ds.group_id != :g AND ds.status != 'cancelled'");
        $stmt->bindValue(':dt', $date, SQLITE3_TEXT);
        $stmt->bindValue(':s', $slotNum, SQLITE3_INTEGER);
        $stmt->bindValue(':t', $teacherId, SQLITE3_INTEGER);
        $stmt->bindValue(':g', $groupId, SQLITE3_INTEGER);
        $conflict = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($conflict) {
            flash('error', "Teacher conflict: already assigned to group \"{$conflict['group_name']}\" on $date slot $slotNum.");
            header("Location: ?page=schedule&view=daily&date=$date&group=$groupId");
            exit;
        }
    }

    $stmt = $db->prepare("SELECT id FROM daily_schedule WHERE schedule_date = :dt AND group_id = :g AND slot_number = :s");
    $stmt->bindValue(':dt', $date, SQLITE3_TEXT);
    $stmt->bindValue(':g', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':s', $slotNum, SQLITE3_INTEGER);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($existing) {
        $stmt2 = $db->prepare("UPDATE daily_schedule SET course_id = :c, teacher_id = :t, status = :st WHERE id = :id");
        $stmt2->bindValue(':c', $courseId, $courseId ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt2->bindValue(':t', $teacherId, $teacherId ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt2->bindValue(':st', $status, SQLITE3_TEXT);
        $stmt2->bindValue(':id', $existing['id'], SQLITE3_INTEGER);
        $stmt2->execute();
    }

    flash('success', 'Slot updated.');
    header("Location: ?page=schedule&view=daily&date=$date&group=$groupId");
    exit;
}

// Load groups
$groups = [];
$result = $db->query("SELECT g.*, gc.name as category_name FROM groups g JOIN group_categories gc ON g.category_id = gc.id ORDER BY gc.name, g.name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $groups[] = $row;
}

if (empty($groups)) {
    echo '<div class="card"><p class="empty">Add groups first. <a href="?page=groups">Go to Groups</a></p></div>';
    return;
}

$scheduleExists = $db->querySingle("SELECT COUNT(*) FROM schedule_slots") > 0;
$selectedGroupId = (int)($_GET['group'] ?? $groups[0]['id']);
$currentView = $_GET['view'] ?? 'daily';
$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Clamp date to schedule range
if ($selectedDate < $settings['start_date']) $selectedDate = $settings['start_date'];
if ($selectedDate > $settings['end_date']) $selectedDate = $settings['end_date'];

// Load courses and teachers for editing
$courses = [];
$result = $db->query("SELECT * FROM courses ORDER BY name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $courses[] = $row; }
$teachers = [];
$result = $db->query("SELECT * FROM teachers ORDER BY name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $teachers[] = $row; }
?>

<div class="page-header">
    <h1>Schedule</h1>
    <form method="POST" action="?page=generate" onsubmit="return confirm('This will regenerate the entire schedule (weekly + all daily). Continue?')">
        <button type="submit" class="btn btn-primary">Auto-Generate Schedule</button>
    </form>
</div>

<?php if (!$scheduleExists): ?>
    <div class="card">
        <p class="empty">No schedule generated yet. Click "Auto-Generate Schedule" to create one.</p>
    </div>
<?php else: ?>

<!-- View tabs -->
<div class="tabs">
    <a href="?page=schedule&view=daily&date=<?= $selectedDate ?>&group=<?= $selectedGroupId ?>"
       class="tab <?= $currentView === 'daily' ? 'active' : '' ?>">Daily Schedule</a>
    <a href="?page=schedule&view=weekly&group=<?= $selectedGroupId ?>"
       class="tab <?= $currentView === 'weekly' ? 'active' : '' ?>">Weekly Template</a>
</div>

<?php if ($currentView === 'daily'): ?>
<!-- ======================== DAILY VIEW ======================== -->
<?php
$dateDow = (int)(new DateTime($selectedDate))->format('w');
$isWeekend = in_array($dateDow, $weekendDays);
$isHoliday = in_array($selectedDate, $holidays);
$isTeachingDay = !$isWeekend && !$isHoliday;
$dayLabel = (new DateTime($selectedDate))->format('l, Y-m-d');

// Navigation dates
$prevDate = (new DateTime($selectedDate))->modify('-1 day')->format('Y-m-d');
$nextDate = (new DateTime($selectedDate))->modify('+1 day')->format('Y-m-d');

$editSlot = isset($_GET['edit_slot']) ? (int)$_GET['edit_slot'] : null;
?>

<!-- Date navigation -->
<div class="card">
    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
        <a href="?page=schedule&view=daily&date=<?= $prevDate ?>&group=<?= $selectedGroupId ?>" class="btn">&larr; Prev</a>
        <form method="GET" class="form-row" style="gap: 8px; margin: 0;">
            <input type="hidden" name="page" value="schedule">
            <input type="hidden" name="view" value="daily">
            <input type="hidden" name="group" value="<?= $selectedGroupId ?>">
            <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>"
                   min="<?= htmlspecialchars($settings['start_date']) ?>"
                   max="<?= htmlspecialchars($settings['end_date']) ?>"
                   onchange="this.form.submit()" style="width: 170px;">
        </form>
        <strong style="font-size: 15px;"><?= $dayLabel ?></strong>
        <a href="?page=schedule&view=daily&date=<?= $nextDate ?>&group=<?= $selectedGroupId ?>" class="btn">Next &rarr;</a>
    </div>
</div>

<?php if ($isWeekend): ?>
    <div class="card"><p class="empty">This is a weekend day. No lectures.</p></div>
<?php elseif ($isHoliday): ?>
    <div class="card"><p class="empty">This is a holiday. No lectures.</p></div>
<?php else: ?>

<!-- Group tabs -->
<div class="tabs">
    <?php foreach ($groups as $g): ?>
        <a href="?page=schedule&view=daily&date=<?= $selectedDate ?>&group=<?= $g['id'] ?>"
           class="tab <?= $g['id'] === $selectedGroupId ? 'active' : '' ?>">
            <?= htmlspecialchars($g['category_name']) ?> - <?= htmlspecialchars($g['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php
// Load daily schedule for selected group and date
$daySchedule = [];
$stmt = $db->prepare("SELECT ds.*, c.name as course_name, t.name as teacher_name
    FROM daily_schedule ds
    LEFT JOIN courses c ON ds.course_id = c.id
    LEFT JOIN teachers t ON ds.teacher_id = t.id
    WHERE ds.schedule_date = :dt AND ds.group_id = :g
    ORDER BY ds.slot_number");
$stmt->bindValue(':dt', $selectedDate, SQLITE3_TEXT);
$stmt->bindValue(':g', $selectedGroupId, SQLITE3_INTEGER);
$res = $stmt->execute();
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $daySchedule[$row['slot_number']] = $row;
}
?>

<div class="card">
    <h2>Daily Schedule</h2>
    <table>
        <thead>
            <tr>
                <th style="width: 60px;">Slot</th>
                <th>Course</th>
                <th>Teacher</th>
                <th style="width: 100px;">Status</th>
                <th style="width: 80px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php for ($s = 1; $s <= $dailySlots; $s++): ?>
            <?php
            $cell = $daySchedule[$s] ?? null;
            $isEditing = $editSlot === $s;
            ?>
            <?php if ($isEditing): ?>
            <tr>
                <td><strong><?= $s ?></strong></td>
                <td colspan="4">
                    <form method="POST" class="form-row" style="gap: 6px;">
                        <input type="hidden" name="action" value="edit_daily_slot">
                        <input type="hidden" name="schedule_date" value="<?= htmlspecialchars($selectedDate) ?>">
                        <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                        <input type="hidden" name="slot_number" value="<?= $s ?>">
                        <select name="course_id" style="width: 160px;">
                            <option value="">-- Empty --</option>
                            <?php foreach ($courses as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($cell && $cell['course_id'] == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="teacher_id" style="width: 160px;">
                            <option value="">-- None --</option>
                            <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= ($cell && $cell['teacher_id'] == $t['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status" style="width: 110px;">
                            <option value="scheduled" <?= ($cell && $cell['status'] === 'scheduled') ? 'selected' : '' ?>>Scheduled</option>
                            <option value="done" <?= ($cell && $cell['status'] === 'done') ? 'selected' : '' ?>>Done</option>
                            <option value="cancelled" <?= ($cell && $cell['status'] === 'cancelled') ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                        <a href="?page=schedule&view=daily&date=<?= $selectedDate ?>&group=<?= $selectedGroupId ?>" class="btn btn-sm">Cancel</a>
                    </form>
                </td>
            </tr>
            <?php else: ?>
            <tr style="<?= ($cell && $cell['status'] === 'cancelled') ? 'opacity: 0.4; text-decoration: line-through;' : '' ?>">
                <td><strong><?= $s ?></strong></td>
                <td><?= $cell && $cell['course_name'] ? htmlspecialchars($cell['course_name']) : '<span style="color:#ccc;">&mdash;</span>' ?></td>
                <td><?= $cell && $cell['teacher_name'] ? htmlspecialchars($cell['teacher_name']) : '<span style="color:#ccc;">&mdash;</span>' ?></td>
                <td>
                    <?php if ($cell && $cell['course_id']): ?>
                        <?php if ($cell['status'] === 'done'): ?>
                            <span class="tag tag-green">Done</span>
                        <?php elseif ($cell['status'] === 'cancelled'): ?>
                            <span class="tag tag-gray">Cancelled</span>
                        <?php else: ?>
                            <span class="tag tag-blue">Scheduled</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="?page=schedule&view=daily&date=<?= $selectedDate ?>&group=<?= $selectedGroupId ?>&edit_slot=<?= $s ?>" class="btn btn-sm">Edit</a>
                </td>
            </tr>
            <?php endif; ?>
        <?php endfor; ?>
        </tbody>
    </table>
</div>

<!-- All groups for this date -->
<div class="card">
    <h2>All Groups &mdash; <?= $dayLabel ?></h2>
    <div style="overflow-x: auto;">
    <table>
        <thead>
            <tr>
                <th>Group</th>
                <?php for ($s = 1; $s <= $dailySlots; $s++): ?>
                    <th style="text-align: center;">Slot <?= $s ?></th>
                <?php endfor; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
            <?php
            $gStmt = $db->prepare("SELECT ds.*, c.name as course_name FROM daily_schedule ds
                LEFT JOIN courses c ON ds.course_id = c.id
                WHERE ds.schedule_date = :dt AND ds.group_id = :g ORDER BY ds.slot_number");
            $gStmt->bindValue(':dt', $selectedDate, SQLITE3_TEXT);
            $gStmt->bindValue(':g', $g['id'], SQLITE3_INTEGER);
            $gRes = $gStmt->execute();
            $gSlots = [];
            while ($row = $gRes->fetchArray(SQLITE3_ASSOC)) { $gSlots[$row['slot_number']] = $row; }
            ?>
            <tr>
                <td style="white-space: nowrap;"><strong><?= htmlspecialchars($g['category_name']) ?> - <?= htmlspecialchars($g['name']) ?></strong></td>
                <?php for ($s = 1; $s <= $dailySlots; $s++): ?>
                    <?php $sl = $gSlots[$s] ?? null; ?>
                    <td style="text-align: center; font-size: 11px; padding: 4px;
                        <?= ($sl && $sl['status'] === 'cancelled') ? 'opacity: 0.4; text-decoration: line-through;' : '' ?>
                        <?= ($sl && $sl['status'] === 'done') ? 'background: #dcfce7;' : '' ?>">
                        <?= ($sl && $sl['course_name']) ? htmlspecialchars($sl['course_name']) : '<span style="color:#ccc;">&mdash;</span>' ?>
                    </td>
                <?php endfor; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php endif; // is teaching day ?>

<?php elseif ($currentView === 'weekly'): ?>
<!-- ======================== WEEKLY TEMPLATE VIEW ======================== -->

<div class="tabs">
    <?php foreach ($groups as $g): ?>
        <a href="?page=schedule&view=weekly&group=<?= $g['id'] ?>"
           class="tab <?= $g['id'] === $selectedGroupId ? 'active' : '' ?>">
            <?= htmlspecialchars($g['category_name']) ?> - <?= htmlspecialchars($g['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php
$schedule = [];
$stmt = $db->prepare("SELECT ss.*, c.name as course_name, t.name as teacher_name
    FROM schedule_slots ss
    LEFT JOIN courses c ON ss.course_id = c.id
    LEFT JOIN teachers t ON ss.teacher_id = t.id
    WHERE ss.group_id = :g");
$stmt->bindValue(':g', $selectedGroupId, SQLITE3_INTEGER);
$res = $stmt->execute();
while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    $schedule[$row['day_of_week']][$row['slot_number']] = $row;
}
?>

<div class="card">
    <h2>Weekly Template <span style="font-weight: 400; font-size: 12px; color: var(--text-light);">(read-only &mdash; edit individual dates in Daily Schedule)</span></h2>
    <div class="schedule-grid" style="grid-template-columns: 60px repeat(<?= count($teachingDays) ?>, 1fr);">
        <div class="schedule-cell header">Slot</div>
        <?php foreach ($teachingDays as $dow): ?>
            <div class="schedule-cell header"><?= getDayName($dow) ?></div>
        <?php endforeach; ?>

        <?php for ($slot = 1; $slot <= $dailySlots; $slot++): ?>
            <div class="schedule-cell slot-label"><?= $slot ?></div>
            <?php foreach ($teachingDays as $dow): ?>
                <?php $cell = $schedule[$dow][$slot] ?? null; ?>
                <?php if ($cell && $cell['course_id']): ?>
                <div class="schedule-cell filled" style="flex-direction: column;">
                    <div class="course-name"><?= htmlspecialchars($cell['course_name'] ?? '') ?></div>
                    <div class="teacher-name"><?= htmlspecialchars($cell['teacher_name'] ?? '') ?></div>
                </div>
                <?php else: ?>
                <div class="schedule-cell" style="color: var(--text-light);">&mdash;</div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endfor; ?>
    </div>
</div>

<?php endif; // view ?>
<?php endif; // scheduleExists ?>
