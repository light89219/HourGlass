<?php
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO group_categories (name) VALUES (:n)");
            $stmt->bindValue(':n', $name, SQLITE3_TEXT);
            $stmt->execute();
            flash('success', "Category \"$name\" added.");
        }
        header('Location: ?page=categories');
        exit;
    }

    if ($action === 'edit_category') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        if ($name && $id) {
            $stmt = $db->prepare("UPDATE group_categories SET name = :n WHERE id = :id");
            $stmt->bindValue(':n', $name, SQLITE3_TEXT);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            flash('success', 'Category updated.');
        }
        header('Location: ?page=categories&manage=' . $id);
        exit;
    }

    if ($action === 'delete_category') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM group_categories WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Category deleted.');
        header('Location: ?page=categories');
        exit;
    }

    if ($action === 'assign_course') {
        $catId = (int)$_POST['category_id'];
        $courseId = (int)$_POST['course_id'];
        $teacherId = (int)$_POST['teacher_id'];
        $lectureCount = max(1, (int)($_POST['lecture_count'] ?? 1));

        $pair = $courseId . '_' . $teacherId;
        $checkStmt = $db->prepare("SELECT id FROM category_courses WHERE category_id = :cat AND course_id = :c AND teacher_id = :t");
        $checkStmt->bindValue(':cat', $catId, SQLITE3_INTEGER);
        $checkStmt->bindValue(':c', $courseId, SQLITE3_INTEGER);
        $checkStmt->bindValue(':t', $teacherId, SQLITE3_INTEGER);
        if ($checkStmt->execute()->fetchArray()) {
            flash('error', 'This course+teacher combination is already assigned.');
        } else {
            $stmt = $db->prepare("INSERT INTO category_courses (category_id, course_id, teacher_id, lecture_count) VALUES (:cat, :c, :t, :lc)");
            $stmt->bindValue(':cat', $catId, SQLITE3_INTEGER);
            $stmt->bindValue(':c', $courseId, SQLITE3_INTEGER);
            $stmt->bindValue(':t', $teacherId, SQLITE3_INTEGER);
            $stmt->bindValue(':lc', $lectureCount, SQLITE3_INTEGER);
            $stmt->execute();
            flash('success', 'Course assigned to category.');
        }
        header('Location: ?page=categories&manage=' . $catId);
        exit;
    }

    if ($action === 'unassign_course') {
        $ccId = (int)$_POST['cc_id'];
        $catId = (int)$_POST['category_id'];
        $stmt = $db->prepare("DELETE FROM category_courses WHERE id = :id");
        $stmt->bindValue(':id', $ccId, SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Course removed from category.');
        header('Location: ?page=categories&manage=' . $catId);
        exit;
    }
}

$categories = [];
$result = $db->query("SELECT * FROM group_categories ORDER BY name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $categories[] = $row;
}

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

$manageCategory = null;
$assignedCourses = [];
if (isset($_GET['manage'])) {
    $mid = (int)$_GET['manage'];
    $stmt = $db->prepare("SELECT * FROM group_categories WHERE id = :id");
    $stmt->bindValue(':id', $mid, SQLITE3_INTEGER);
    $manageCategory = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($manageCategory) {
        $stmt2 = $db->prepare("SELECT cc.*, c.name as course_name, t.name as teacher_name
            FROM category_courses cc
            JOIN courses c ON cc.course_id = c.id
            JOIN teachers t ON cc.teacher_id = t.id
            WHERE cc.category_id = :cat ORDER BY c.name");
        $stmt2->bindValue(':cat', $mid, SQLITE3_INTEGER);
        $res = $stmt2->execute();
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $assignedCourses[] = $row;
        }
    }
}
$assignedPairs = array_map(function($ac) { return $ac['course_id'] . '_' . $ac['teacher_id']; }, $assignedCourses);
?>

<div class="page-header">
    <h1>Group Categories</h1>
</div>

<div class="card">
    <h2>Add Category</h2>
    <form method="POST" class="form-row">
        <input type="hidden" name="action" value="add_category">
        <div class="form-group" style="flex: 1;">
            <label>Category Name (e.g., Year 1, Year 2)</label>
            <input type="text" name="name" required placeholder="Enter category name">
        </div>
        <button type="submit" class="btn btn-primary">Add Category</button>
    </form>
</div>

<div class="card">
    <h2>Categories (<?= count($categories) ?>)</h2>
    <?php if (empty($categories)): ?>
        <p class="empty">No categories added yet.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Courses</th>
                <th>Groups</th>
                <th style="width: 200px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $cat): ?>
            <?php
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM category_courses WHERE category_id = :id");
            $stmt->bindValue(':id', $cat['id'], SQLITE3_INTEGER);
            $ccCount = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM groups WHERE category_id = :id");
            $stmt->bindValue(':id', $cat['id'], SQLITE3_INTEGER);
            $gCount = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];
            ?>
            <tr>
                <td><?= htmlspecialchars($cat['name']) ?></td>
                <td><span class="tag tag-blue"><?= $ccCount ?> courses</span></td>
                <td><span class="tag tag-green"><?= $gCount ?> groups</span></td>
                <td>
                    <div class="inline-actions">
                        <a href="?page=categories&manage=<?= $cat['id'] ?>" class="btn btn-sm">Manage Courses</a>
                        <form method="POST" onsubmit="return confirm('Delete this category and all its groups?')">
                            <input type="hidden" name="action" value="delete_category">
                            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
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

<?php if ($manageCategory): ?>
<div class="card">
    <div class="page-header" style="margin-bottom: 12px;">
        <h2>Manage Courses: <?= htmlspecialchars($manageCategory['name']) ?></h2>
        <form method="POST" style="display:inline;">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="id" value="<?= $manageCategory['id'] ?>">
            <div class="form-row" style="gap: 6px;">
                <input type="text" name="name" value="<?= htmlspecialchars($manageCategory['name']) ?>" style="width: 200px;">
                <button type="submit" class="btn btn-sm btn-primary">Rename</button>
            </div>
        </form>
    </div>

    <?php if (!empty($courses) && !empty($teachers)): ?>
    <form method="POST" class="form-row" style="margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--border);">
        <input type="hidden" name="action" value="assign_course">
        <input type="hidden" name="category_id" value="<?= $manageCategory['id'] ?>">
        <div class="form-group">
            <label>Course</label>
            <select name="course_id" required>
                <option value="">Select course...</option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Teacher</label>
            <select name="teacher_id" required>
                <option value="">Select teacher...</option>
                <?php foreach ($teachers as $t): ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Required Lectures</label>
            <input type="number" name="lecture_count" min="1" value="1" required style="width: 100px;">
        </div>
        <button type="submit" class="btn btn-primary">Assign</button>
    </form>
    <?php elseif (empty($courses)): ?>
        <p style="color: var(--text-light); font-size: 13px; margin-bottom: 12px;">Add courses first before assigning them.</p>
    <?php else: ?>
        <p style="color: var(--text-light); font-size: 13px; margin-bottom: 12px;">Add teachers first before assigning courses.</p>
    <?php endif; ?>

    <?php if (empty($assignedCourses)): ?>
        <p class="empty">No courses assigned to this category yet.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Course</th>
                <th>Teacher</th>
                <th>Required Lectures</th>
                <th style="width: 80px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($assignedCourses as $ac): ?>
            <tr>
                <td><?= htmlspecialchars($ac['course_name']) ?></td>
                <td><?= htmlspecialchars($ac['teacher_name']) ?></td>
                <td><?= $ac['lecture_count'] ?></td>
                <td>
                    <form method="POST" onsubmit="return confirm('Remove this course?')">
                        <input type="hidden" name="action" value="unassign_course">
                        <input type="hidden" name="cc_id" value="<?= $ac['id'] ?>">
                        <input type="hidden" name="category_id" value="<?= $manageCategory['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div style="margin-top: 12px;">
        <a href="?page=categories" class="btn">Close</a>
    </div>
</div>
<?php endif; ?>
