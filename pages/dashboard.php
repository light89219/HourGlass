<?php
$db = getDB();
$settings = getSettings();

$teacherCount = $db->querySingle("SELECT COUNT(*) FROM teachers");
$courseCount = $db->querySingle("SELECT COUNT(*) FROM courses");
$categoryCount = $db->querySingle("SELECT COUNT(*) FROM group_categories");
$groupCount = $db->querySingle("SELECT COUNT(*) FROM groups");
$scheduleCount = $db->querySingle("SELECT COUNT(*) FROM schedule_slots WHERE course_id IS NOT NULL");
$dailyCount = $db->querySingle("SELECT COUNT(*) FROM daily_schedule WHERE course_id IS NOT NULL");
$doneLectures = $db->querySingle("SELECT COUNT(*) FROM daily_schedule WHERE status = 'done' AND course_id IS NOT NULL");
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
        <tr><td><strong>Start Date</strong></td><td><?= htmlspecialchars($settings['start_date']) ?></td></tr>
        <tr><td><strong>End Date</strong></td><td><?= htmlspecialchars($settings['end_date']) ?></td></tr>
        <tr><td><strong>Daily Slots</strong></td><td><?= $settings['daily_slots'] ?></td></tr>
        <tr>
            <td><strong>Weekend Days</strong></td>
            <td>
                <?php
                $wDays = array_map('intval', array_filter(explode(',', $settings['weekends']), function($v) { return $v !== ''; }));
                echo implode(', ', array_map(function($d) { return getDayName($d); }, $wDays)) ?: 'None';
                ?>
            </td>
        </tr>
        <tr>
            <td><strong>Holidays</strong></td>
            <td><?= count(json_decode($settings['holidays'], true) ?: []) ?> day(s)</td>
        </tr>
        <tr>
            <td><strong>Weekly Template</strong></td>
            <td>
                <?php if ($scheduleCount > 0): ?>
                    <span class="tag tag-green">Generated (<?= $scheduleCount ?> slots)</span>
                <?php else: ?>
                    <span class="tag tag-gray">Not yet</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td><strong>Daily Schedule</strong></td>
            <td>
                <?php if ($dailyCount > 0): ?>
                    <span class="tag tag-green"><?= $dailyCount ?> lecture slots</span>
                <?php else: ?>
                    <span class="tag tag-gray">Not yet</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td><strong>Lectures Done</strong></td>
            <td><?= $doneLectures ?></td>
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
        <a href="?page=schedule&view=daily" class="btn btn-primary">Daily Schedule</a>
        <a href="?page=statistics" class="btn btn-success">Statistics</a>
    </div>
</div>

<?php if ($groupCount > 0 && $dailyCount > 0): ?>
<div class="card">
    <h2>Today's Schedule (<?= date('l, Y-m-d') ?>)</h2>
    <?php
    $todayStr = date('Y-m-d');
    $todayDow = (int)date('w');
    $wDays2 = array_map('intval', array_filter(explode(',', $settings['weekends']), function($v) { return $v !== ''; }));
    $hols2 = json_decode($settings['holidays'], true) ?: [];

    if (in_array($todayDow, $wDays2)):
    ?>
        <p style="color: var(--text-light);">Today is a weekend day. No lectures.</p>
    <?php elseif (in_array($todayStr, $hols2)): ?>
        <p style="color: var(--text-light);">Today is a holiday. No lectures.</p>
    <?php elseif ($todayStr < $settings['start_date'] || $todayStr > $settings['end_date']): ?>
        <p style="color: var(--text-light);">Today is outside the schedule period.</p>
    <?php else: ?>
        <?php
        $groups2 = [];
        $result = $db->query("SELECT g.*, gc.name as category_name FROM groups g JOIN group_categories gc ON g.category_id = gc.id ORDER BY gc.name, g.name");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) { $groups2[] = $row; }
        ?>
        <table>
            <thead>
                <tr>
                    <th>Slot</th>
                    <?php foreach ($groups2 as $g): ?>
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
                        $stmt = $db->prepare("SELECT c.name, ds.status FROM daily_schedule ds
                            LEFT JOIN courses c ON ds.course_id = c.id
                            WHERE ds.schedule_date = :dt AND ds.group_id = :g AND ds.slot_number = :s");
                        $stmt->bindValue(':dt', $todayStr, SQLITE3_TEXT);
                        $stmt->bindValue(':g', $g['id'], SQLITE3_INTEGER);
                        $stmt->bindValue(':s', $s, SQLITE3_INTEGER);
                        $slot = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                        ?>
                        <td style="<?= ($slot && $slot['status'] === 'cancelled') ? 'text-decoration: line-through; opacity: 0.4;' : '' ?>
                                   <?= ($slot && $slot['status'] === 'done') ? 'background: #dcfce7;' : '' ?>">
                            <?= $slot && $slot['name'] ? htmlspecialchars($slot['name']) : '<span style="color:#ccc;">&mdash;</span>' ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
        <div style="margin-top: 8px;">
            <a href="?page=schedule&view=daily&date=<?= $todayStr ?>" class="btn btn-sm btn-primary">Edit Today's Schedule</a>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php endif; ?>
