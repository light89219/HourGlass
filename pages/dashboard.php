<?php
$db = getDB();
$settings = getSettings();

// Count entities
$teacherCount = $db->querySingle("SELECT COUNT(*) FROM teachers");
$courseCount = $db->querySingle("SELECT COUNT(*) FROM courses");
$categoryCount = $db->querySingle("SELECT COUNT(*) FROM group_categories");
$groupCount = $db->querySingle("SELECT COUNT(*) FROM groups");
$scheduleCount = $db->querySingle("SELECT COUNT(*) FROM schedule_slots WHERE course_id IS NOT NULL");
$logCount = $db->querySingle("SELECT COUNT(DISTINCT lecture_date) FROM lecture_log");
?>

<div class="page-header">
    <h1>Dashboard</h1>
</div>

<?php if (!$settings): ?>
<div class="card">
    <h2>Welcome!</h2>
    <p style="margin-bottom: 12px;">Get started by configuring your schedule settings.</p>
    <a href="?page=settings" class="btn btn-primary">Configure Settings</a>
</div>
<?php else: ?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= $teacherCount ?></div>
        <div class="stat-label">Teachers</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $courseCount ?></div>
        <div class="stat-label">Courses</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $categoryCount ?></div>
        <div class="stat-label">Categories</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $groupCount ?></div>
        <div class="stat-label">Groups</div>
    </div>
</div>

<div class="card">
    <h2>Schedule Period</h2>
    <table>
        <tr>
            <td><strong>Start Date</strong></td>
            <td><?= htmlspecialchars($settings['start_date']) ?></td>
        </tr>
        <tr>
            <td><strong>End Date</strong></td>
            <td><?= htmlspecialchars($settings['end_date']) ?></td>
        </tr>
        <tr>
            <td><strong>Daily Slots</strong></td>
            <td><?= $settings['daily_slots'] ?></td>
        </tr>
        <tr>
            <td><strong>Weekend Days</strong></td>
            <td>
                <?php
                $wDays = array_map('intval', array_filter(explode(',', $settings['weekends']), fn($v) => $v !== ''));
                echo implode(', ', array_map(fn($d) => getDayName($d), $wDays)) ?: 'None';
                ?>
            </td>
        </tr>
        <tr>
            <td><strong>Holidays</strong></td>
            <td>
                <?php
                $hols = json_decode($settings['holidays'], true) ?: [];
                echo count($hols) . ' day(s)';
                ?>
            </td>
        </tr>
        <tr>
            <td><strong>Schedule Generated</strong></td>
            <td>
                <?php if ($scheduleCount > 0): ?>
                    <span class="tag tag-green">Yes (<?= $scheduleCount ?> slots)</span>
                <?php else: ?>
                    <span class="tag tag-gray">Not yet</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td><strong>Lectures Logged</strong></td>
            <td><?= $logCount ?> day(s) marked</td>
        </tr>
    </table>
</div>

<div class="card">
    <h2>Quick Actions</h2>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="?page=settings" class="btn">Settings</a>
        <a href="?page=teachers" class="btn">Manage Teachers</a>
        <a href="?page=courses" class="btn">Manage Courses</a>
        <a href="?page=categories" class="btn">Manage Categories</a>
        <a href="?page=groups" class="btn">Manage Groups</a>
        <a href="?page=schedule" class="btn btn-primary">View Schedule</a>
        <a href="?page=statistics" class="btn btn-success">Statistics</a>
    </div>
</div>

<?php if ($groupCount > 0 && $scheduleCount > 0): ?>
<div class="card">
    <h2>Today's Schedule (<?= date('l, Y-m-d') ?>)</h2>
    <?php
    $todayDow = (int)date('w');
    $wDays2 = array_map('intval', array_filter(explode(',', $settings['weekends']), fn($v) => $v !== ''));
    $hols2 = json_decode($settings['holidays'], true) ?: [];

    if (in_array($todayDow, $wDays2)):
    ?>
        <p style="color: var(--text-light);">Today is a weekend day. No lectures.</p>
    <?php elseif (in_array(date('Y-m-d'), $hols2)): ?>
        <p style="color: var(--text-light);">Today is a holiday. No lectures.</p>
    <?php elseif (date('Y-m-d') < $settings['start_date'] || date('Y-m-d') > $settings['end_date']): ?>
        <p style="color: var(--text-light);">Today is outside the schedule period.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Slot</th>
                    <?php
                    $groups2 = [];
                    $result = $db->query("SELECT g.*, gc.name as category_name FROM groups g JOIN group_categories gc ON g.category_id = gc.id ORDER BY gc.name, g.name");
                    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                        $groups2[] = $row;
                    }
                    foreach ($groups2 as $g):
                    ?>
                        <th><?= htmlspecialchars($g['category_name']) ?>-<?= htmlspecialchars($g['name']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php for ($s = 1; $s <= $settings['daily_slots']; $s++): ?>
                <tr>
                    <td><strong><?= $s ?></strong></td>
                    <?php foreach ($groups2 as $g): ?>
                        <?php
                        $stmt = $db->prepare("SELECT c.name FROM schedule_slots ss LEFT JOIN courses c ON ss.course_id = c.id
                            WHERE ss.group_id = :g AND ss.day_of_week = :d AND ss.slot_number = :s");
                        $stmt->bindValue(':g', $g['id'], SQLITE3_INTEGER);
                        $stmt->bindValue(':d', $todayDow, SQLITE3_INTEGER);
                        $stmt->bindValue(':s', $s, SQLITE3_INTEGER);
                        $slot = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                        ?>
                        <td><?= $slot && $slot['name'] ? htmlspecialchars($slot['name']) : '<span style="color:#ccc;">&mdash;</span>' ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>
