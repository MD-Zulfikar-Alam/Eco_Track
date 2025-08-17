
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

$id = (int)($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT p.*, u.name, u.username FROM blog_posts p LEFT JOIN users u ON u.id=p.user_id WHERE p.id=?');
$st->execute([$id]);
$post = $st->fetch();
if (!$post) { http_response_code(404); echo 'Post not found.'; exit; }

$errors=[]; $notice=null;
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['act'])) {
  if (!verify_csrf($_POST['csrf'] ?? '')) { $errors[]='Invalid CSRF token.'; }
  else {
    if ($_POST['act']==='comment') {
      $content = trim($_POST['content'] ?? '');
      if ($content==='') { $errors[]='Write something.'; }
      else {
        $pdo->prepare('INSERT INTO blog_comments (blog_id, user_id, content) VALUES (?,?,?)')->execute([$id, $uid, $content]);
        $notice='Comment added.';
      }
    } elseif ($_POST['act']==='delete_comment' && $is_admin) {
      $cid = (int)($_POST['comment_id'] ?? 0);
      if ($cid>0) { $pdo->prepare('DELETE FROM blog_comments WHERE id=?')->execute([$cid]); $notice='Comment deleted.'; }
    }
  }
}

$cmt = $pdo->prepare('SELECT c.*, u.name, u.username FROM blog_comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.blog_id=? AND c.is_deleted=0 ORDER BY c.created_at ASC, c.id ASC');
$cmt->execute([$id]);
$comments = $cmt->fetchAll();

include __DIR__.'/partials/header.php';
?>
<?php if ($notice): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error"><ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="grid cols-12 gap-16">
  <article class="card span-12">
    <h2><?= htmlspecialchars($post['title']) ?></h2>
    <div class="meta small muted">By <?= htmlspecialchars($post['name'] ?: $post['username'] ?: ('User #'.$post['user_id'])) ?> · <?= htmlspecialchars($post['created_at']) ?></div>
    <?php if ($post['image_path']): ?><img src="<?= htmlspecialchars($post['image_path']) ?>" alt="" style="max-width:100%;border-radius:12px;margin:10px 0"><?php endif; ?>
    <div class="prose"><?= nl2br(htmlspecialchars($post['body'])) ?></div>
    <div class="actions"><a class="btn" href="blog.php">Back to blog</a></div>
  </article>

  <div class="card span-12">
    <h3>Comments</h3>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="act" value="comment">
      <div class="input-group"><label>Add a comment</label><textarea name="content" rows="3" required></textarea></div>
      <div class="actions"><button class="btn primary">Post comment</button></div>
    </form>
    <div class="comments">
      <?php if (!$comments): ?><p class="small muted">No comments yet.</p><?php endif; ?>
      <?php foreach ($comments as $c): ?>
        <div class="comment">
          <div class="meta small muted"><?= htmlspecialchars($c['name'] ?: $c['username'] ?: ('User #'.$c['user_id'])) ?> · <?= htmlspecialchars($c['created_at']) ?></div>
          <div class="content"><?= nl2br(htmlspecialchars($c['content'])) ?></div>
          <?php if ($is_admin): ?>
          <form method="post" onsubmit="return confirm('Delete this comment?');" style="display:inline">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
            <input type="hidden" name="act" value="delete_comment">
            <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
            <button class="btn danger small">Delete</button>
          </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php include __DIR__.'/partials/footer.php'; ?>
