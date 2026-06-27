<?php
$db = getDB();
$settings = getSettings();

if (!$settings) {
    echo '<div class="card"><p class="empty">Configure settings first. <a href="?page=settings">Go to Settings</a></p></div>';
    return;
}

$weekendDays = array_map('intval', array_filter(explode(',', $settings['weekends']), fn($v) => $v !== ''));
$teachingDays = getTeachingDaysOfWeek($weekendDays);
$dailySlots = $settings['daily_slots'];

// Handle slot edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_slot') {
    $groupId = (int)$_POST['group_id'];
    $dow = (int)$_POST['day_of_week'];
    $slotNum = (int)$_POST['slot_number'];
    $courseId = (int)($_POST['course_id'] ?? 0) ?: null;
    $teacherId = (int)($_POST['teacher_id'] ?? 0) ?: null;

    // Check teacher conflict
    if ($teacherId && $courseId) {
        $stmt = $db->prepare("SELECT ss.*, g.name as group_name FROM schedule_slots ss
            JOIN groups g ON ss.group_id = g.id
            WHERE ss.day_of_week = :d AND ss.slot_number = :s AND ss.teacher_id = :t AND ss.group_id != :g");
        $stmt->bindValue(':d', $dow, SQLITE3_INTEGER);
        $stmt->bindValue(':s', $slotNum, SQLITE3_INTEGER);
        $stmt->bindValue(':t', $teacherId, SQLITE3_INTEGER);
        $stmt->bindValue(':g', $groupId, SQLITE3_INTEGER);
        $conflict = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($conflict) {
            flash('error', "Teacher conflict: already assigned to group \"{$conflict['group_name']}\" at this slot.");
            header('Location: ?page=schedule&group=' . $groupId);
            exit;
        }
    }

    $stmt = $db->prepare("SELECT id FROM schedule_slots WHERE group_id = :g AND day_of_week = :d AND slot_number = :s");
    $stmt->bindValue(':g', $groupId, SQLITE3_INTEGER);
    $stmt->bindValue(':d', $dow, SQLITE3_INTEGER);
    $stmt->bindValue(':s', $slotNum, SQLITE3_INTEGER);
    $existing = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($existing) {
        $stmt2 = $db->prepare("UPDATE schedule_slots SET course_id = :c, teacher_id = :t WHERE id = :id");
        $stmt2->bindValue(':c', $courseId, $courseId ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt2->bindValue(':t', $teacherId, $teacherId ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt2->bindValue(':id', $existing['id'], SQLITE3_INTEGER);
        $stmt2->execute();
    } else {
        $stmt2 = $db->prepare("INSERT INTO schedule_slots (group_id, day_of_week, slot_number, course_id, teacher_id) VALUES (:g, :d, :s, :c, :t)");
        $stmt2->bindValue(':g', $groupId, SQLITE3_INTEGER);
        $stmt2->bindValue(':d', $dow, SQLITE3_INTEGER);
        $stmt2->bindValue(':s', $slotNum, SQLITE3_INTEGER);
        $stmt2->bindValue(':c', $courseId, $courseId ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt2->bindValue(':t', $teacherId, $teacherId ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt2->execute();
    }

    flash('success', 'Slot updated.');
    header('Location: ?page=schedule&group=' . $groupId);
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

$selectedGroupId = (int)($_GET['group'] ?? $groups[0]['id']);

// Check if schedule exists
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM schedule_slots");
$scheduleExists = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'] > 0;

// Load schedule for selected group
$schedule = [];
if ($scheduleExists) {
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
}

// Load courses and teachers for editing
$courses = [];
$result = $db->query("SELECT * FROM courses ORDER BY name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $courses[] = $row;
}
$teachers = [];
$result = $db->query("SELECT * FROM teachers ORDER BY name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $teachers[] = $row;
}

// Check which slot is being edited
$editSlot = null;
if (isset($_GET['edit_dow']) && isset($_GET['edit_slot'])) {
    $editSlot = ['dow' => (int)$_GET['edit_dow'], 'slot' => (int)$_GET['edit_slot']];
}
?>

<div class="page-header">
    <h1>Weekly Schedule</h1>
    <form method="POST" action="?page=generate" onsubmit="return confirm('This will regenerate the entire schedule. Continue?')">
        <button type="submit" class="btn btn-primary">Auto-Generate Schedule</button>
    </form>
</div>

<!-- Group tabs -->
<div class="tabs">
    <?php foreach ($groups as $g): ?>
        <a href="?page=schedule&group=<?= $g['id'] ?>"
           class="tab <?= $g['id'] === $selectedGroupId ? 'active' : '' ?>">
            <?= htmlspecialchars($g['category_name']) ?> - <?= htmlspecialchars($g['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (!$scheduleExists): ?>
    <div class="card">
        <p class="empty">No schedule generated yet. Click "Auto-Generate Schedule" to create one.</p>
    </div>
<?php else: ?>

<div class="card" style="overflow-x: auto;">
    <div class="schedule-grid" style="grid-template-columns: 60px repeat(<?= count($teachingDays) ?>, 1fr);">
        <!-- Header row -->
        <div class="schedule-cell header">Slot</div>
        <?php foreach ($teachingDays as $dow): ?>
            <div class="schedule-cell header"><?= getDayName($dow) ?></div>
        <?php endforeach; ?>

        <!-- Data rows -->
        <?php for ($slot = 1; $slot <= $dailySlots; $slot++): ?>
            <div class="schedule-cell slot-label"><?= $slot ?></div>
            <?php foreach ($teachingDays as $dow): ?>
                <?php
                $cell = $schedule[$dow][$slot] ?? null;
                $isEditing = $editSlot && $editSlot['dow'] === $dow && $editSlot['slot'] === $slot;
                ?>
                <?php if ($isEditing): ?>
                <div class="schedule-cell" style="padding: 4px;">
                    <form method="POST" style="width: 100%;">
                        <input type="hidden" name="action" value="edit_slot">
                        <input type="hidden" name="group_id" value="<?= $selectedGroupId ?>">
                        <input type="hidden" name="day_of_week" value="<?= $dow ?>">
                        <input type="hidden" name="slot_number" value="<?= $slot ?>">
                        <select name="course_id" style="width: 100%; margin-bottom: 2px; font-size: 11px; padding: 2px;">
                            <option value="">-- Empty --</option>
                            <?php foreach ($courses as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($cell && $cell['course_id'] == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <select name="teacher_id" style="width: 100%; margin-bottom: 2px; font-size: 11px; padding: 2px;">
                            <option value="">-- None --</option>
                            <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= ($cell && $cell['teacher_id'] == $t['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="display: flex; gap: 2px;">
                            <button type="submit" class="btn btn-sm btn-primary" style="flex:1; justify-content: center;">Save</button>
                            <a href="?page=schedule&group=<?= $selectedGroupId ?>" class="btn btn-sm" style="flex:1; justify-content: center;">Cancel</a>
                        </div>
                    </form>
                </div>
                <?php elseif ($cell && $cell['course_id']): ?>
                <a href="?page=schedule&group=<?= $selectedGroupId ?>&edit_dow=<?= $dow ?>&edit_slot=<?= $slot ?>"
                   class="schedule-cell filled" style="flex-direction: column; text-decoration: none;">
                    <div class="course-name"><?= htmlspecialchars($cell['course_name'] ?? '') ?></div>
                    <div class="teacher-name"><?= htmlspecialchars($cell['teacher_name'] ?? '') ?></div>
                </a>
                <?php else: ?>
                <a href="?page=schedule&group=<?= $selectedGroupId ?>&edit_dow=<?= $dow ?>&edit_slot=<?= $slot ?>"
                   class="schedule-cell" style="text-decoration: none; color: var(--text-light); cursor: pointer;">
                    &mdash;
                </a>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endfor; ?>
    </div>
</div>

<!-- All groups overview -->
<div class="card">
    <h2>All Groups Overview</h2>
    <div style="overflow-x: auto;">
    <table>
        <thead>
            <tr>
                <th>Group</th>
                <?php foreach ($teachingDays as $dow): ?>
                    <?php for ($s = 1; $s <= $dailySlots; $s++): ?>
                        <th style="text-align: center; font-size: 11px;"><?= substr(getDayName($dow), 0, 3) ?> <?= $s ?></th>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
            <?php
            $gSchedule = [];
            $stmt2 = $db->prepare("SELECT ss.*, c.name as course_name FROM schedule_slots ss
                LEFT JOIN courses c ON ss.course_id = c.id WHERE ss.group_id = :g");
            $stmt2->bindValue(':g', $g['id'], SQLITE3_INTEGER);
            $res2 = $stmt2->execute();
            while ($row = $res2->fetchArray(SQLITE3_ASSOC)) {
                $gSchedule[$row['day_of_week']][$row['slot_number']] = $row;
            }
            ?>
            <tr>
                <td style="white-space: nowrap;"><strong><?= htmlspecialchars($g['category_name']) ?> - <?= htmlspecialchars($g['name']) ?></strong></td>
                <?php foreach ($teachingDays as $dow): ?>
                    <?php for ($s = 1; $s <= $dailySlots; $s++): ?>
                        <td style="text-align: center; font-size: 10px; padding: 4px;">
                            <?php if (isset($gSchedule[$dow][$s]) && $gSchedule[$dow][$s]['course_id']): ?>
                                <?= htmlspecialchars($gSchedule[$dow][$s]['course_name']) ?>
                            <?php else: ?>
                                <span style="color: #ccc;">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php endif; ?>
