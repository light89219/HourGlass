<?php
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $catId = (int)$_POST['category_id'];
        if ($name && $catId) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO groups (name, category_id) VALUES (:n, :c)");
            $stmt->bindValue(':n', $name, SQLITE3_TEXT);
            $stmt->bindValue(':c', $catId, SQLITE3_INTEGER);
            $stmt->execute();
            flash('success', "Group \"$name\" added.");
        }
        header('Location: ?page=groups');
        exit;
    }

    if ($action === 'edit') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name'] ?? '');
        $catId = (int)$_POST['category_id'];
        if ($name && $id && $catId) {
            $stmt = $db->prepare("UPDATE groups SET name = :n, category_id = :c WHERE id = :id");
            $stmt->bindValue(':n', $name, SQLITE3_TEXT);
            $stmt->bindValue(':c', $catId, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
            flash('success', 'Group updated.');
        }
        header('Location: ?page=groups');
        exit;
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $db->prepare("DELETE FROM groups WHERE id = :id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Group deleted.');
        header('Location: ?page=groups');
        exit;
    }
}

$categories = [];
$result = $db->query("SELECT * FROM group_categories ORDER BY name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $categories[] = $row;
}

$groups = [];
$result = $db->query("SELECT g.*, gc.name as category_name FROM groups g JOIN group_categories gc ON g.category_id = gc.id ORDER BY gc.name, g.name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $groups[] = $row;
}

$editGroup = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM groups WHERE id = :id");
    $stmt->bindValue(':id', (int)$_GET['edit'], SQLITE3_INTEGER);
    $editGroup = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}
?>

<div class="page-header">
    <h1>Groups</h1>
</div>

<?php if (empty($categories)): ?>
<div class="card">
    <p class="empty">Add categories first before creating groups. <a href="?page=categories">Go to Categories</a></p>
</div>
<?php else: ?>

<div class="card">
    <h2><?= $editGroup ? 'Edit Group' : 'Add Group' ?></h2>
    <form method="POST" class="form-row">
        <input type="hidden" name="action" value="<?= $editGroup ? 'edit' : 'add' ?>">
        <?php if ($editGroup): ?>
            <input type="hidden" name="id" value="<?= $editGroup['id'] ?>">
        <?php endif; ?>
        <div class="form-group">
            <label>Group Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($editGroup['name'] ?? '') ?>" required placeholder="e.g., A, B, C">
        </div>
        <div class="form-group">
            <label>Category</label>
            <select name="category_id" required>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= ($editGroup && $editGroup['category_id'] == $cat['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary"><?= $editGroup ? 'Update' : 'Add' ?></button>
        <?php if ($editGroup): ?>
            <a href="?page=groups" class="btn">Cancel</a>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h2>Group List (<?= count($groups) ?>)</h2>
    <?php if (empty($groups)): ?>
        <p class="empty">No groups added yet.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Category</th>
                <th style="width: 150px;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groups as $g): ?>
            <tr>
                <td><?= htmlspecialchars($g['name']) ?></td>
                <td><span class="tag tag-blue"><?= htmlspecialchars($g['category_name']) ?></span></td>
                <td>
                    <div class="inline-actions">
                        <a href="?page=groups&edit=<?= $g['id'] ?>" class="btn btn-sm">Edit</a>
                        <form method="POST" onsubmit="return confirm('Delete this group?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $g['id'] ?>">
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
