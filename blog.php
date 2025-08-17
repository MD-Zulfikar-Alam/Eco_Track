
<?php
$page_title='Blog';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';
require_login();

$uid = (int)$_SESSION['user']['id'];
$is_admin = in_array($uid, [1,2,3], true);

// Ensure tables exist
$pdo->exec("CREATE TABLE IF NOT EXISTS blog_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(200) NOT NULL UNIQUE,
  body MEDIUMTEXT NOT NULL,
  image_path VARCHAR(255) DEFAULT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS blog_comments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  blog_id INT NOT NULL,
  user_id INT NOT NULL,
  content TEXT NOT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (blog_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function slugify($text){
  $text = preg_replace('~[^\pL\d]+~u', '-', $text);
  $text = trim($text, '-');
  $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
  $text = strtolower($text);
  $text = preg_replace('~[^-\w]+~', '', $text);
  if (empty($text)) { return 'post'; }
  return $text;
}
function handle_upload($field){
  if (!isset($_FILES[$field]) || $_FILES[$field]['error']!==UPLOAD_ERR_OK) return null;
  $f = $_FILES[$field];
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = $finfo->file($f['tmp_name']);
  $ext = null;
  if ($mime==='image/jpeg') $ext='jpg';
  elseif ($mime==='image/png') $ext='png';
  elseif ($mime==='image/webp') $ext='webp';
  else return null;
  if ($f['size'] > 5*1024*1024) return null; // 5MB cap
  $name = uniqid('b_', true).'.'.$ext;
  $destDir = __DIR__ . '/uploads/blogs';
  if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
  $dest = $destDir . '/' . $name;
  if (move_uploaded_file($f['tmp_name'], $dest)) {
    return 'uploads/blogs/'.$name;
  }
  return null;
}

$errors=[]; $notice=null;
if ($is_admin && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['blog_action'])) {
  if (!verify_csrf($_POST['csrf'] ?? '')) { $errors[]='Invalid CSRF token.'; }
  else {
    $act = $_POST['blog_action'];
    if ($act==='create') {
      $title = trim($_POST['title'] ?? '');
      $body = trim($_POST['body'] ?? '');
      $img  = handle_upload('image');
      if ($title==='' || $body==='') { $errors[]='Please fill in the title and content.'; }
      if (!$errors) {
        $slug = slugify($title);
        // ensure unique
        $base = $slug; $i=1;
        while ($pdo->prepare('SELECT 1 FROM blog_posts WHERE slug=?')->execute([$slug]) && $pdo->prepare('SELECT 1 FROM blog_posts WHERE slug=?')->fetch()) { $slug = $base.'-'.$i++; }
        $st = $pdo->prepare('INSERT INTO blog_posts (user_id, title, slug, body, image_path) VALUES (?,?,?,?,?)');
        $st->execute([$uid, $title, $slug, $body, $img]);
        $notice='Post created.';
      }
    } elseif ($act==='update') {
      $id = (int)($_POST['id'] ?? 0);
      $title = trim($_POST['title'] ?? '');
      $body = trim($_POST['body'] ?? '');
      $img  = handle_upload('image');
      if ($id<=0) { $errors[]='Invalid post id.'; }
      if ($title==='' || $body==='') { $errors[]='Please fill in the title and content.'; }
      if (!$errors) {
        if ($img) {
          $st = $pdo->prepare('UPDATE blog_posts SET title=?, body=?, image_path=? WHERE id=?');
          $st->execute([$title, $body, $img, $id]);
        } else {
          $st = $pdo->prepare('UPDATE blog_posts SET title=?, body=? WHERE id=?');
          $st->execute([$title, $body, $id]);
        }
        $notice='Post updated.';
      }
    } elseif ($act==='delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id>0) {
        $pdo->prepare('DELETE FROM blog_comments WHERE blog_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM blog_posts WHERE id=?')->execute([$id]);
        $notice='Post deleted.';
      }
    }
  }
}

// Fetch posts
$posts = $pdo->query('SELECT p.*, u.name, u.username FROM blog_posts p LEFT JOIN users u ON u.id=p.user_id ORDER BY p.created_at DESC, p.id DESC')->fetchAll();

include __DIR__.'/partials/header.php';
?>
<?php if ($notice): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error"><ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="grid cols-12 gap-16">
  <?php if ($is_admin): ?>
  <div class="card span-12">
    <h3>Admin · Create a new post</h3>
    <form method="post" class="form-grid" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="blog_action" value="create">
      <div class="grid cols-2 gap-12">
        <div class="input-group"><label>Title</label><input name="title" required></div>
        <div class="input-group"><label>Image</label><input type="file" name="image" accept="image/*" required></div>
      </div>
      <div class="input-group"><label>Content</label><textarea name="body" rows="6" required></textarea></div>
      <div class="actions"><button class="btn primary">Publish</button></div>
    </form>
  </div>
  <?php endif; ?>

  <div class="card span-12">
    <h3>Latest posts</h3>
    <div class="blog-list">
      <?php if (!$posts): ?>
        <p class="small muted">No blog posts yet.</p>
      <?php else: foreach ($posts as $p): ?>
        <article class="blog-card">
          <?php if ($p['image_path']): ?><img class="thumb" src="<?= htmlspecialchars($p['image_path']) ?>" alt=""><?php endif; ?>
          <div class="content">
            <h4><a href="blog_view.php?id=<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['title']) ?></a></h4>
            <div class="meta small muted">By <?= htmlspecialchars($p['name'] ?: $p['username'] ?: ('User #'.$p['user_id'])) ?> · <?= htmlspecialchars($p['created_at']) ?></div>
            <p class="excerpt"><?= nl2br(htmlspecialchars(mb_strimwidth($p['body'], 0, 220, '…'))) ?></p>
            <div class="actions">
              <a class="btn" href="blog_view.php?id=<?= (int)$p['id'] ?>">Read</a>
              <?php if ($is_admin): ?>
              <details>
                <summary class="btn">Edit</summary>
                <form method="post" class="form-grid form-compact" enctype="multipart/form-data" style="margin-top:8px">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="blog_action" value="update">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <div class="grid cols-2 gap-12">
                    <div class="input-group"><label>Title</label><input name="title" value="<?= htmlspecialchars($p['title']) ?>"></div>
                    <div class="input-group"><label>Replace image</label><input type="file" name="image" accept="image/*"></div>
                  </div>
                  <div class="input-group"><label>Content</label><textarea name="body" rows="5"><?= htmlspecialchars($p['body']) ?></textarea></div>
                  <div class="actions"><button class="btn primary">Save</button></div>
                </form>
                <form method="post" onsubmit="return confirm('Delete this post?');" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="blog_action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn danger">Delete</button>
                </form>
              </details>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__.'/partials/footer.php'; ?>
