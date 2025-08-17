
<?php
$page_title='Community';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';
require_login();
include __DIR__.'/partials/header.php';

$uid = (int)$_SESSION['user']['id'];
$is_admin = in_array($uid, [1,2,3], true);
$errors=[]; $notice=null;

// ensure table
$pdo->exec("CREATE TABLE IF NOT EXISTS community_posts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  parent_id INT NULL,
  content TEXT NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_parent (parent_id),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Actions
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) { $errors[]='Invalid CSRF token.'; }
  else {
    $act = $_POST['action'] ?? '';
    if ($act==='create') {
      $parent = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
      $content = trim($_POST['content'] ?? '');
      if ($content==='') $errors[]='Please write something.';
      if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO community_posts (user_id, parent_id, content) VALUES (?,?,?)');
        $stmt->execute([$uid, $parent ?: null, $content]);
        $notice = $parent ? 'Reply posted.' : 'Post created.';
      }
    } elseif ($act==='delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id>0) {
        $st = $pdo->prepare('SELECT user_id FROM community_posts WHERE id=?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) { $errors[]='Post not found.'; }
        else {
          if ($is_admin || (int)$row['user_id'] === $uid) {
            // soft-delete to keep replies
            $pdo->prepare('UPDATE community_posts SET is_deleted=1, content=NULL WHERE id=?')->execute([$id]);
            $notice = 'Message deleted.';
          } else {
            $errors[] = "You can only delete your own message.";
          }
        }
      }
    }
  }
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$pp = 15;
$offset = ($page-1) * $pp;

// Fetch root threads
$roots = $pdo->prepare('SELECT * FROM community_posts WHERE parent_id IS NULL ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?');
$roots->bindValue(1, $pp, PDO::PARAM_INT);
$roots->bindValue(2, $offset, PDO::PARAM_INT);
$roots->execute();
$threads = $roots->fetchAll();

// Count for pager
$total = (int)$pdo->query('SELECT COUNT(*) FROM community_posts WHERE parent_id IS NULL')->fetchColumn();
$pages = max(1, (int)ceil($total / $pp));

function display_author($postUid, $sessionUid, $pdo) {
  static $cache = [];
  if (isset($cache[$postUid])) { $name = $cache[$postUid]; }
  else {
    $st = $pdo->prepare('SELECT name, username FROM users WHERE id=?');
    $st->execute([$postUid]);
    $u = $st->fetch();
    $name = $u ? (trim($u['name']) !== '' ? $u['name'] : $u['username']) : ('User #'.$postUid);
    $cache[$postUid] = $name;
  }
  if ($postUid === $sessionUid) return $name . ' (You)';
  return $name;
}

function show_post($p, $uid, $is_admin, $pdo){
  $content = $p['is_deleted'] ? '<em class="muted">[deleted]</em>' : nl2br(htmlspecialchars($p['content'] ?? ''));
  $who = display_author((int)$p['user_id'], $uid, $pdo);
  $ts = htmlspecialchars($p['created_at']);
  echo '<div class="post"><div class="meta"><span class="who">'. $who .'</span> · <span class="when">'.$ts.'</span></div>';
  echo '<div class="body">'.$content.'</div>';
  // actions
  echo '<div class="post-actions">';
  echo '<a href="#reply-'.$p['id'].'" class="btn small">Reply</a>';
  if (!$p['is_deleted'] && ($is_admin || (int)$p['user_id']===$uid)) {
    echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this message?\');">';
    echo '<input type="hidden" name="csrf" value="'.htmlspecialchars(csrf_token()).'">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<input type="hidden" name="id" value="'.(int)$p['id'].'">';
    echo '<button class="btn small danger">Delete</button>';
    echo '</form>';
  }
  echo '</div>';

  // reply form
  echo '<form id="reply-'.$p['id'].'" method="post" class="reply-form">';
  echo '<input type="hidden" name="csrf" value="'.htmlspecialchars(csrf_token()).'">';
  echo '<input type="hidden" name="action" value="create">';
  echo '<input type="hidden" name="parent_id" value="'.(int)$p['id'].'">';
  echo '<textarea name="content" rows="2" placeholder="Write a reply..." required></textarea>';
  echo '<div class="actions"><button class="btn primary">Reply</button></div>';
  echo '</form>';
  echo '</div>';
}
?>

<?php if ($notice): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error"><ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="grid cols-12 gap-16">
  <div class="card span-12">
    <h3>Create a post</h3>
    <form method="post" class="form-grid">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="action" value="create">
      <div class="input-group"><label>Say something</label><textarea name="content" rows="3" maxlength="1000" required placeholder="Share an idea, ask a question, or start a discussion..."></textarea></div>
      <div class="actions"><button class="btn primary">Post</button></div>
    </form>
  </div>

  <div class="card span-12">
    <h3>Community feed</h3>
    <?php if (!$threads): ?>
      <p class="small muted">No posts yet. Be the first to start a conversation!</p>
    <?php else: ?>
      <div class="community-list">
        <?php foreach ($threads as $t): ?>
          <div class="thread">
            <?php show_post($t, $uid, $is_admin, $pdo); ?>
            <?php
              // load replies for this thread
              $st = $pdo->prepare('SELECT * FROM community_posts WHERE parent_id=? ORDER BY created_at ASC, id ASC');
              $st->execute([$t['id']]);
              $replies = $st->fetchAll();
            ?>
            <?php if ($replies): ?>
              <div class="replies">
                <?php foreach ($replies as $r): ?>
                  <div class="reply">
                    <?php show_post($r, $uid, $is_admin, $pdo); ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($pages>1): ?>
      <div class="pager">
        <?php for($i=1;$i<=$pages;$i++): ?>
          <a class="btn <?= $i===$page?'primary':'' ?>" href="?page=<?= $i ?>"><?= $i ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__.'/partials/footer.php'; ?>
