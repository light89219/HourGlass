<?php
$db = getDB();
$settings = getSettings();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $subStart = $_POST['sub_start_date'] ?? '' ?: null;
        $subEnd = $_POST['sub_end_date'] ?? '' ?: null;
        if ($name) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO courses (name, sub_start_date, sub_end_date) VALUES (:n, :ss, :se)");
            $stmt->bindValue(':n', $name, SQLITE3_TEXT);
            $stmt->bindValue(':ss', $subStart, $subStart ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':se', $subEnd, $subEnd ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->execute();
            flash('success', "Course \"$name\" added.");
        }
        header('Location: ?page=courses');
        exit;
    }

    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $subStart = $_POST['sub_start_date'] ?? '' ?: null;
        $subEnd = $_POST['sub_end_date'] ?? '' ?: null;
        if ($name && $id) {
            $stmt = $db->prepare("UPDATE courses SET name = :n, sub_start_date = :ss, sub_end_date = :se WHERE id = :id");
            $stmt->bindValue(':n', $name, SQLITE3_TEXT);
            $stmt->bindValue(':ss', $subStart, $subStart ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':se', $subEnd, $subEnd ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            flash('success', "Course updated.");
        }
        header('Location: ?page=courses');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM courses WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Course deleted.');
        header('Location: ?page=courses');
        exit;
    }
}

$courses = [];
$result = $db->query("SELECT * FROM courses ORDER BY name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $courses[] = $row;
}

$editCourse = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM courses WHERE id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], SQLITE3_INTEGER);
    $editCourse = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}
?>

<div class="page-header">
    <h1>Courses</h1>
</div>

<div class="card">
    <h2><?= $editCourse ? 'Edit Course' : 'Add Course' ?></h2>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $editCourse ? 'edit' : 'add' ?>">
        <?php if ($editCourse): ?>
            <input type="hidden" name="id" value="<?= $editCourse['id'] ?>">
        <?php endif; ?>
        <div class="form-row">
            <div class="form-group" style="flex: 2;">
                <label>Course Name</label>
                <input type="text" name="name" value="<?= htmlspecialchars($editCourse['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label>Sub-range Start (optional)</label>
                <input type="date" name="sub_start_date" value="<?= htmlspecialchars($editCourse['sub_start_date'] ?? '') ?>"
                    <?php if ($settings): ?>min="<?= htmlspecialchars($settings['start_date']) ?>" max="<?= htmlspecialchars($settings['end_date']) ?>"<?php endif; ?>>
            </div>
            <div class="form-group">
                <label>Sub-range End (optional)</label>
                <input type="date" name="sub_end_date" value="<?= htmlspecialchars($editCourse['sub_end_date'] ?? '') ?>"
                    <?php if ($settings): ?>min="<?= htmlspecialchars($settings['start_date']) ?>" max="<?= htmlspecialchars($settings['end_date']) ?>"<?php endif; ?>>
            </div>
            <button type="submit" class="btn btn-primary"><?= $editCourse ? 'Update' : 'Add' ?></button>
            <?php if ($editCourse): ?>
                <a href="?page=courses" class="btn">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h2>Course List (<?= count($courses) ?>)</h2>
    <?php if (empty($courses)): ?>
        <p class="empty">No courses added yet.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Sub-range Start</th>
                <th>Sub-range End</th>
                <th style="width: 120px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($courses as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['name']) ?></td>
                <td><?= $c['sub_start_date'] ? htmlspecialchars($c['sub_start_date']) : '<span class="tag tag-gray">Full range</span>' ?></td>
                <td><?= $c['sub_end_date'] ? htmlspecialchars($c['sub_end_date']) : '<span class="tag tag-gray">Full range</span>' ?></td>
                <td>
                    <div class="inline-actions">
                        <a href="?page=courses&edit=<?= $c['id'] ?>" class="btn btn-sm">Edit</a>
                        <form method="POST" onsubmit="return confirm('Delete this course?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
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
