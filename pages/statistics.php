<?php
$db = getDB();
$settings = getSettings();

if (!$settings) {
    echo '<div class="card"><p class="empty">Configure settings first. <a href="?page=settings">Go to Settings</a></p></div>';
    return;
}

$weekendDays = array_map('intval', array_filter(explode(',', $settings['weekends']), function($v) { return $v !== ''; }));
$holidays = json_decode($settings['holidays'], true) ?: [];
$dailySlots = $settings['daily_slots'];

// Handle mark/unmark day
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_range_done') {
        $from = $_POST['from_date'] ?? '';
        $to = $_POST['to_date'] ?? '';
        if ($from && $to) {
            $stmt = $db->prepare("UPDATE daily_schedule SET status = 'done' WHERE schedule_date >= :f AND schedule_date <= :t AND status = 'scheduled' AND course_id IS NOT NULL");
            $stmt->bindValue(':f', $from, SQLITE3_TEXT);
            $stmt->bindValue(':t', $to, SQLITE3_TEXT);
            $stmt->execute();
            $count = $db->changes();
            flash('success', "Marked $count lectures as done ($from ~ $to).");
        }
        header('Location: ?page=statistics');
        exit;
    }

    if ($action === 'unmark_range') {
        $from = $_POST['from_date'] ?? '';
        $to = $_POST['to_date'] ?? '';
        if ($from && $to) {
            $stmt = $db->prepare("UPDATE daily_schedule SET status = 'scheduled' WHERE schedule_date >= :f AND schedule_date <= :t AND status = 'done'");
            $stmt->bindValue(':f', $from, SQLITE3_TEXT);
            $stmt->bindValue(':t', $to, SQLITE3_TEXT);
            $stmt->execute();
            $count = $db->changes();
            flash('success', "Reverted $count lectures back to scheduled ($from ~ $to).");
        }
        header('Location: ?page=statistics');
        exit;
    }
}

// Load groups
$groups = [];
$result = $db->query("SELECT g.*, gc.name as category_name FROM groups g JOIN group_categories gc ON g.category_id = gc.id ORDER BY gc.name, g.name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $groups[] = $row;
}

$dailyExists = $db->querySingle("SELECT COUNT(*) FROM daily_schedule") > 0;

if (!$dailyExists) {
    echo '<div class="card"><p class="empty">Generate the schedule first. <a href="?page=schedule">Go to Schedule</a></p></div>';
    return;
}

// Summary counts
$today = date('Y-m-d');
$totalTeachingDates = $db->querySingle("SELECT COUNT(DISTINCT schedule_date) FROM daily_schedule");
$doneCount = $db->querySingle("SELECT COUNT(*) FROM daily_schedule WHERE status = 'done' AND course_id IS NOT NULL");
$scheduledCount = $db->querySingle("SELECT COUNT(*) FROM daily_schedule WHERE status = 'scheduled' AND course_id IS NOT NULL");
$cancelledCount = $db->querySingle("SELECT COUNT(*) FROM daily_schedule WHERE status = 'cancelled'");

$doneDates = $db->querySingle("SELECT COUNT(DISTINCT schedule_date) FROM daily_schedule WHERE status = 'done'");
$pastDates = 0;
$current = new DateTime($settings['start_date']);
$endDt = new DateTime(min($today, $settings['end_date']));
while ($current <= $endDt) {
    $dow = (int)$current->format('w');
    $dateStr = $current->format('Y-m-d');
    if (!in_array($dow, $weekendDays) && !in_array($dateStr, $holidays)) $pastDates++;
    $current->modify('+1 day');
}

// Dates with done status
$markedDates = [];
$result = $db->query("SELECT DISTINCT schedule_date FROM daily_schedule WHERE status = 'done' ORDER BY schedule_date");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $markedDates[] = $row['schedule_date'];
}
?>

<div class="page-header">
    <h1>Statistics</h1>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= $totalTeachingDates ?></div>
        <div class="stat-label">Total Teaching Days</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $doneDates ?></div>
        <div class="stat-label">Days Done</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $doneCount ?></div>
        <div class="stat-label">Lectures Done</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $cancelledCount ?></div>
        <div class="stat-label">Cancelled</div>
    </div>
</div>

<!-- Mark date range -->
<div class="card">
    <h2>Mark Lectures</h2>
    <div class="form-row" style="flex-wrap: wrap; gap: 16px;">
        <form method="POST" class="form-row" style="flex: 1;">
            <input type="hidden" name="action" value="mark_range_done">
            <div class="form-group">
                <label>From</label>
                <input type="date" name="from_date" value="<?= htmlspecialchars($settings['start_date']) ?>" required
                    min="<?= htmlspecialchars($settings['start_date']) ?>"
                    max="<?= htmlspecialchars($settings['end_date']) ?>">
            </div>
            <div class="form-group">
                <label>To</label>
                <input type="date" name="to_date" value="<?= $today ?>" required
                    min="<?= htmlspecialchars($settings['start_date']) ?>"
                    max="<?= htmlspecialchars($settings['end_date']) ?>">
            </div>
            <button type="submit" class="btn btn-success">Mark as Done</button>
        </form>
        <form method="POST" class="form-row">
            <input type="hidden" name="action" value="unmark_range">
            <div class="form-group">
                <label>From</label>
                <input type="date" name="from_date" required
                    min="<?= htmlspecialchars($settings['start_date']) ?>"
                    max="<?= htmlspecialchars($settings['end_date']) ?>">
            </div>
            <div class="form-group">
                <label>To</label>
                <input type="date" name="to_date" required
                    min="<?= htmlspecialchars($settings['start_date']) ?>"
                    max="<?= htmlspecialchars($settings['end_date']) ?>">
            </div>
            <button type="submit" class="btn btn-danger">Undo</button>
        </form>
    </div>
    <?php if (!empty($markedDates)): ?>
    <div style="margin-top: 12px;">
        <strong style="font-size: 12px;">Done dates (<?= count($markedDates) ?>):</strong>
        <div class="holiday-list">
            <?php foreach ($markedDates as $md): ?>
                <span class="holiday-item" style="background: #dcfce7; border-color: #bbf7d0;"><?= htmlspecialchars($md) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Per-group course progress -->
<?php foreach ($groups as $group): ?>
<?php
$catId = $group['category_id'];
$gid = $group['id'];

$stmt = $db->prepare("SELECT cc.*, c.name as course_name, c.sub_start_date, c.sub_end_date, t.name as teacher_name
    FROM category_courses cc
    JOIN courses c ON cc.course_id = c.id
    JOIN teachers t ON cc.teacher_id = t.id
    WHERE cc.category_id = :cat ORDER BY c.name");
$stmt->bindValue(':cat', $catId, SQLITE3_INTEGER);
$res = $stmt->execute();
$catCourses = [];
while ($row = $res->fetchArray(SQLITE3_ASSOC)) { $catCourses[] = $row; }
if (empty($catCourses)) continue;
?>
<div class="card">
    <h2><?= htmlspecialchars($group['category_name']) ?> - Group <?= htmlspecialchars($group['name']) ?></h2>
    <table>
        <thead>
            <tr>
                <th>Course</th>
                <th>Teacher</th>
                <th style="width: 80px;">Required</th>
                <th style="width: 80px;">Done</th>
                <th style="width: 80px;">Left</th>
                <th style="width: 80px;">Cancelled</th>
                <th style="width: 200px;">Progress</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($catCourses as $cc): ?>
            <?php
            $stmt2 = $db->prepare("SELECT
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                FROM daily_schedule WHERE group_id = :g AND course_id = :c");
            $stmt2->bindValue(':g', $gid, SQLITE3_INTEGER);
            $stmt2->bindValue(':c', $cc['course_id'], SQLITE3_INTEGER);
            $row = $stmt2->execute()->fetchArray(SQLITE3_ASSOC);
            $done = (int)($row['done'] ?? 0);
            $cancelled = (int)($row['cancelled'] ?? 0);
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
                        <span class="tag tag-green">Done</span>
                    <?php endif; ?>
                </td>
                <td><?= $cancelled ?: '<span style="color:#ccc;">0</span>' ?></td>
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
