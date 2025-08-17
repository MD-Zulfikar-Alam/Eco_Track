
<?php
$page_title='Eco Tips';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';
require_login();
include __DIR__.'/partials/header.php';

// Admins are users with IDs 1–3
$is_admin = in_array((int)$_SESSION['user']['id'], [1,2,3], true);
$uid = (int)$_SESSION['user']['id'];

// Ensure table exists (defensive)
$pdo->exec("CREATE TABLE IF NOT EXISTS eco_tips (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  tip_text TEXT NOT NULL,
  category VARCHAR(60) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  author_user_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (is_active, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$errors = []; $notice = null;

// Admin actions
if ($is_admin && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['tips_action'])) {
  if (!verify_csrf($_POST['csrf'] ?? '')) { $errors[] = 'Invalid CSRF token.'; }
  else {
    $act = $_POST['tips_action'];
    if ($act==='create') {
      $title = trim($_POST['title'] ?? '');
      $tip = trim($_POST['tip_text'] ?? '');
      $cat = trim($_POST['category'] ?? '');
      $active = isset($_POST['is_active']) ? 1 : 0;
      if ($title==='' || $tip==='') { $errors[]='Please enter a title and the tip text.'; }
      if (!$errors) {
        $st = $pdo->prepare('INSERT INTO eco_tips (title, tip_text, category, is_active, author_user_id) VALUES (?,?,?,?,?)');
        $st->execute([$title, $tip, ($cat!==''?$cat:null), $active, $uid]);
        $notice = 'Tip added.';
      }
    } elseif ($act==='update') {
      $id = (int)($_POST['id'] ?? 0);
      $title = trim($_POST['title'] ?? '');
      $tip = trim($_POST['tip_text'] ?? '');
      $cat = trim($_POST['category'] ?? '');
      $active = isset($_POST['is_active']) ? 1 : 0;
      if ($id<=0) { $errors[]='Invalid tip id.'; }
      if ($title==='' || $tip==='') { $errors[]='Please enter a title and the tip text.'; }
      if (!$errors) {
        $st = $pdo->prepare('UPDATE eco_tips SET title=?, tip_text=?, category=?, is_active=? WHERE id=?');
        $st->execute([$title, $tip, ($cat!==''?$cat:null), $active, $id]);
        $notice = 'Tip updated.';
      }
    } elseif ($act==='delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id>0) {
        $pdo->prepare('DELETE FROM eco_tips WHERE id=?')->execute([$id]);
        $notice = 'Tip deleted.';
      }
    }
  }
}

// Filters
$filter = trim($_GET['q'] ?? '');
$where = ' WHERE is_active=1 ';
$args = [];
if ($is_admin && isset($_GET['show']) && $_GET['show']==='all') {
  $where = ' WHERE 1=1 ';
}
if ($filter!=='') {
  $where .= " AND (title LIKE ? OR tip_text LIKE ? OR category LIKE ?)";
  $args[] = '%'.$filter.'%'; $args[] = '%'.$filter.'%'; $args[] = '%'.$filter.'%';
}
$sql = 'SELECT * FROM eco_tips' . $where . ' ORDER BY id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($args);
$tips = $stmt->fetchAll();

// Tip of the day (random active)
$randStmt = $pdo->query("SELECT * FROM eco_tips WHERE is_active=1 ORDER BY RAND() LIMIT 1");
$tipOfDay = $randStmt->fetch();
?>

<?php if ($notice): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error"><ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="grid cols-12 gap-16">
  <div class="card span-6">
    <h3>Tip of the day</h3>
    <?php if ($tipOfDay): ?>
      <h2 style="margin-top:6px"><?= htmlspecialchars($tipOfDay['title']) ?></h2>
      <p class="muted"><?= nl2br(htmlspecialchars($tipOfDay['tip_text'])) ?></p>
      <?php if (!empty($tipOfDay['category'])): ?><div class="badge"><?= htmlspecialchars($tipOfDay['category']) ?></div><?php endif; ?>
      <div class="actions" style="margin-top:8px">
        <a class="btn" href="?shuffle=1">Show another</a>
      </div>
    <?php else: ?>
      <p class="small muted">No tips yet.</p>
    <?php endif; ?>
  </div>

  <div class="card span-6">
    <h3>All tips</h3>
    <form method="get" class="form-grid" style="margin:6px 0 10px 0">
      <div class="input-group">
        <label for="q">Search</label>
        <input id="q" name="q" value="<?= htmlspecialchars($filter) ?>" placeholder="Search text or category...">
      </div>
      <?php if ($is_admin): ?>
      <label class="input-group" style="display:flex;align-items:center;gap:6px; margin-top:2px">
        <input style="width:16px;height:16px" type="checkbox" name="show" value="all" <?= (($_GET['show'] ?? '')==='all')?'checked':''; ?>> Show inactive too
      </label>
      <?php endif; ?>
      <div class="actions"><button class="btn">Apply</button></div>
    </form>
    <div class="table-wrap">
      <table class="table table-compact eco-tips-table">
        <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Status</th><?php if ($is_admin): ?><th>Actions</th><?php endif; ?></tr></thead>
        <tbody>
          <?php if ($tips): foreach($tips as $t): ?>
            <tr>
              <td><?= (int)$t['id'] ?></td>
              <td><?= htmlspecialchars($t['title']) ?><br><span class="small muted"><?= nl2br(htmlspecialchars(mb_strimwidth($t['tip_text'],0,120,'…'))) ?></span></td>
              <td><?= htmlspecialchars($t['category'] ?? '') ?></td>
              <td><?= ((int)$t['is_active']===1)?'Active':'Hidden' ?></td>
              <?php if ($is_admin): ?>
              <td>
                <details>
                  <summary class="btn">Edit</summary>
                  <form method="post" class="form-grid form-compact" style="margin-top:6px">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="tips_action" value="update">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <div class="grid tips-grid gap-12">
                      <div class="input-group"><label>Title</label><input name="title" value="<?= htmlspecialchars($t['title']) ?>"></div>
                      <div class="input-group"><label>Category</label><input name="category" value="<?= htmlspecialchars($t['category'] ?? '') ?>"></div>
                      <label class="input-group" style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_active" <?= ((int)$t['is_active']===1)?'checked':''; ?>> Active</label>
                    </div>
                    <div class="input-group"><label>Tip text</label><textarea name="tip_text" rows="3"><?= htmlspecialchars($t['tip_text']) ?></textarea></div>
                    <div class="actions"><button class="btn primary">Save</button></div>
                  </form>
                </details>
                <form method="post" onsubmit="return confirm('Delete this tip?');" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="tips_action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <button class="btn">Delete</button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="<?= $is_admin?5:4 ?>" class="small muted">No tips found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($is_admin): ?>
  <div class="card span-12">
    <h3>Admin · Add a new tip</h3>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="tips_action" value="create">
      <div class="grid tips-grid gap-12">
        <div class="input-group"><label>Title</label><input name="title" required></div>
        <div class="input-group"><label>Category</label><input name="category" placeholder="energy / water / food / waste ..."></div>
        <label class="input-group" style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_active" checked> Active</label>
      </div>
      <div class="input-group"><label>Tip text</label><textarea name="tip_text" rows="3" required></textarea></div>
      <div class="actions"><button class="btn primary">Add tip</button></div>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__.'/partials/footer.php'; ?>
