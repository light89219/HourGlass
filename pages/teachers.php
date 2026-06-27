<?php
$db = getDB();
$settings = getSettings();
$dailySlots = $settings ? $settings['daily_slots'] : 6;
$weekendDays = $settings ? array_map('intval', explode(',', $settings['weekends'])) : [0, 6];
$teachingDays = getTeachingDaysOfWeek($weekendDays);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO teachers (name) VALUES (:n)");
            $stmt->bindValue(':n', $name, SQLITE3_TEXT);
            $stmt->execute();
            $teacherId = $db->lastInsertRowID();
            if ($teacherId) {
                foreach ($teachingDays as $dow) {
                    for ($slot = 1; $slot <= $dailySlots; $slot++) {
                        $stmt2 = $db->prepare("INSERT OR IGNORE INTO teacher_availability (teacher_id, day_of_week, slot_number) VALUES (:t, :d, :s)");
                        $stmt2->bindValue(':t', $teacherId, SQLITE3_INTEGER);
                        $stmt2->bindValue(':d', $dow, SQLITE3_INTEGER);
                        $stmt2->bindValue(':s', $slot, SQLITE3_INTEGER);
                        $stmt2->execute();
                    }
                }
            }
            flash('success', "Teacher \"$name\" added with full availability.");
        }
        header('Location: ?page=teachers');
        exit;
    }

    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        if ($name && $id) {
            $stmt = $db->prepare("UPDATE teachers SET name = :n WHERE id = :id");
            $stmt->bindValue(':n', $name, SQLITE3_TEXT);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            flash('success', 'Teacher updated.');
        }
        header('Location: ?page=teachers&avail=' . $id);
        exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM teachers WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Teacher deleted.');
        header('Location: ?page=teachers');
        exit;
    }

    if ($action === 'save_availability') {
        $id = (int)$_POST['teacher_id'];
        $stmt = $db->prepare("DELETE FROM teacher_availability WHERE teacher_id = :t");
        $stmt->bindValue(':t', $id, SQLITE3_INTEGER);
        $stmt->execute();

        $slots = $_POST['slots'] ?? [];
        foreach ($slots as $key => $val) {
            [$dow, $slot] = explode('_', $key);
            $stmt2 = $db->prepare("INSERT INTO teacher_availability (teacher_id, day_of_week, slot_number) VALUES (:t, :d, :s)");
            $stmt2->bindValue(':t', $id, SQLITE3_INTEGER);
            $stmt2->bindValue(':d', (int)$dow, SQLITE3_INTEGER);
            $stmt2->bindValue(':s', (int)$slot, SQLITE3_INTEGER);
            $stmt2->execute();
        }
        flash('success', 'Availability updated.');
        header('Location: ?page=teachers&avail=' . $id);
        exit;
    }
}

$teachers = [];
$result = $db->query("SELECT * FROM teachers ORDER BY name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $teachers[] = $row;
}

$availTeacher = null;
$availSlots = [];
if (isset($_GET['avail'])) {
    $tid = (int)$_GET['avail'];
    $stmt = $db->prepare("SELECT * FROM teachers WHERE id = :id");
    $stmt->bindValue(':id', $tid, SQLITE3_INTEGER);
    $availTeacher = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($availTeacher) {
        $stmt2 = $db->prepare("SELECT day_of_week, slot_number FROM teacher_availability WHERE teacher_id = :t");
        $stmt2->bindValue(':t', $tid, SQLITE3_INTEGER);
        $res = $stmt2->execute();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $availSlots[$row['day_of_week'] . '_' . $row['slot_number']] = true;
        }
    }
}
?>

<div class="page-header">
    <h1>Teachers</h1>
</div>

<div class="card">
    <h2>Add Teacher</h2>
    <form method="POST" class="form-row">
        <input type="hidden" name="action" value="add">
        <div class="form-group" style="flex: 1;">
            <label>Teacher Name</label>
            <input type="text" name="name" required placeholder="Enter teacher name">
        </div>
        <button type="submit" class="btn btn-primary">Add Teacher</button>
    </form>
</div>

<div class="card">
    <h2>Teacher List (<?= count($teachers) ?>)</h2>
    <?php if (empty($teachers)): ?>
        <p class="empty">No teachers added yet.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th style="width: 200px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($teachers as $t): ?>
            <tr>
                <td>
                    <?php if ($availTeacher && $availTeacher['id'] === $t['id']): ?>
                        <form method="POST" class="form-row" style="gap: 6px;">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <input type="text" name="name" value="<?= htmlspecialchars($t['name']) ?>" style="width: 200px;">
                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                        </form>
                    <?php else: ?>
                        <?= htmlspecialchars($t['name']) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="inline-actions">
                        <a href="?page=teachers&avail=<?= $t['id'] ?>" class="btn btn-sm">Availability</a>
                        <form method="POST" onsubmit="return confirm('Delete this teacher?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php if ($availTeacher): ?>
<div class="card">
    <h2>Availability: <?= htmlspecialchars($availTeacher['name']) ?></h2>
    <p style="font-size: 12px; color: var(--text-light); margin-bottom: 12px;">Check the slots where this teacher is available to teach.</p>
    <form method="POST">
        <input type="hidden" name="action" value="save_availability">
        <input type="hidden" name="teacher_id" value="<?= $availTeacher['id'] ?>">
        <table class="avail-table">
            <thead>
                <tr>
                    <th>Slot</th>
                    <?php foreach ($teachingDays as $dow): ?>
                        <th><?= getDayName($dow) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php for ($s = 1; $s <= $dailySlots; $s++): ?>
                <tr>
                    <td><strong><?= $s ?></strong></td>
                    <?php foreach ($teachingDays as $dow): ?>
                        <td>
                            <input type="checkbox" name="slots[<?= $dow ?>_<?= $s ?>]" value="1"
                                <?= isset($availSlots[$dow . '_' . $s]) ? 'checked' : '' ?>>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
        <div style="margin-top: 12px;">
            <button type="submit" class="btn btn-primary">Save Availability</button>
            <a href="?page=teachers" class="btn">Close</a>
        </div>
    </form>
</div>
<?php endif; ?>
