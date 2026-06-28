<?php
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $startDate = $_POST['start_date'] ?? '';
        $endDate = $_POST['end_date'] ?? '';
        $dailySlots = max(1, (int)($_POST['daily_slots'] ?? 6));
        $weekends = isset($_POST['weekends']) ? implode(',', $_POST['weekends']) : '';

        $existing = getSettings();
        if ($existing) {
            $stmt = $db->prepare("UPDATE settings SET start_date = :sd, end_date = :ed, daily_slots = :ds, weekends = :w WHERE id = 1");
        } else {
            $stmt = $db->prepare("INSERT INTO settings (id, start_date, end_date, daily_slots, weekends) VALUES (1, :sd, :ed, :ds, :w)");
        }
        $stmt->bindValue(':sd', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':ed', $endDate, SQLITE3_TEXT);
        $stmt->bindValue(':ds', $dailySlots, SQLITE3_INTEGER);
        $stmt->bindValue(':w', $weekends, SQLITE3_TEXT);
        $stmt->execute();
        flash('success', 'Settings saved.');
        header('Location: ?page=settings');
        exit;
    }

    if ($action === 'add_holiday') {
        $date = $_POST['holiday_date'] ?? '';
        if ($date) {
            $settings = getSettings();
            $holidays = $settings ? json_decode($settings['holidays'], true) : [];
            if (!in_array($date, $holidays)) {
                $holidays[] = $date;
                sort($holidays);
                $stmt = $db->prepare("UPDATE settings SET holidays = :h WHERE id = 1");
                $stmt->bindValue(':h', json_encode($holidays), SQLITE3_TEXT);
                $stmt->execute();
                flash('success', "Holiday $date added.");
            }
        }
        header('Location: ?page=settings');
        exit;
    }

    if ($action === 'remove_holiday') {
        $date = $_POST['holiday_date'] ?? '';
        $settings = getSettings();
        $holidays = $settings ? json_decode($settings['holidays'], true) : [];
        $holidays = array_values(array_filter($holidays, function($h) use ($date) { return $h !== $date; }));
        $stmt = $db->prepare("UPDATE settings SET holidays = :h WHERE id = 1");
        $stmt->bindValue(':h', json_encode($holidays), SQLITE3_TEXT);
        $stmt->execute();
        flash('success', "Holiday $date removed.");
        header('Location: ?page=settings');
        exit;
    }
}

$settings = getSettings();
$weekends = $settings ? explode(',', $settings['weekends']) : ['0', '6'];
$holidays = $settings ? json_decode($settings['holidays'], true) : [];
?>

<div class="page-header">
    <h1>Schedule Settings</h1>
</div>

<div class="card">
    <h2>Date Range & Slots</h2>
    <form method="POST">
        <input type="hidden" name="action" value="save_settings">
        <div class="form-row">
            <div class="form-group">
                <label>Start Date</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($settings['start_date'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>End Date</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($settings['end_date'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Daily Lecture Slots</label>
                <input type="number" name="daily_slots" min="1" max="12" value="<?= $settings['daily_slots'] ?? 6 ?>" required>
            </div>
        </div>
        <div class="form-group" style="margin-top: 14px;">
            <label>Weekend Days (no lectures)</label>
            <div class="checkbox-grid">
                <?php for ($i = 0; $i < 7; $i++): ?>
                <label>
                    <input type="checkbox" name="weekends[]" value="<?= $i ?>" <?= in_array((string)$i, $weekends) ? 'checked' : '' ?>>
                    <?= getDayName($i) ?>
                </label>
                <?php endfor; ?>
            </div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top: 8px;">Save Settings</button>
    </form>
</div>

<?php if ($settings): ?>
<div class="card">
    <h2>Holidays</h2>
    <form method="POST" class="form-row" style="margin-bottom: 12px;">
        <input type="hidden" name="action" value="add_holiday">
        <div class="form-group">
            <label>Add Holiday Date</label>
            <input type="date" name="holiday_date" required
                   min="<?= htmlspecialchars($settings['start_date']) ?>"
                   max="<?= htmlspecialchars($settings['end_date']) ?>">
        </div>
        <button type="submit" class="btn btn-primary">Add Holiday</button>
    </form>

    <?php if (empty($holidays)): ?>
        <p style="color: var(--text-light); font-size: 13px;">No holidays added yet.</p>
    <?php else: ?>
        <div class="holiday-list">
            <?php foreach ($holidays as $h): ?>
            <span class="holiday-item">
                <?= htmlspecialchars($h) ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="remove_holiday">
                    <input type="hidden" name="holiday_date" value="<?= htmlspecialchars($h) ?>">
                    <button type="submit" title="Remove">&times;</button>
                </form>
            </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
