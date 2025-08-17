
<?php
$page_title='Challenges';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';
require_login();
include __DIR__.'/partials/header.php';

// Admin check (IDs 1–3)
$is_admin = in_array((int)$_SESSION['user']['id'], [1,2,3], true);
$uid = (int)$_SESSION['user']['id'];

// Ensure tables exist (defensive)
$pdo->exec("CREATE TABLE IF NOT EXISTS challenges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(120) NOT NULL,
  description TEXT,
  xp INT NOT NULL DEFAULT 50,
  frequency ENUM('once','daily','weekly','monthly') NOT NULL DEFAULT 'once',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS user_challenges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  challenge_id INT NOT NULL,
  status ENUM('available','in_progress','completed') NOT NULL DEFAULT 'available',
  times_completed INT NOT NULL DEFAULT 0,
  last_completed_at DATETIME NULL,
  streak INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_chal (user_id, challenge_id),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS user_progress (
  user_id INT PRIMARY KEY,
  xp INT NOT NULL DEFAULT 0,
  level INT NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS challenge_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  challenge_id INT NOT NULL,
  xp_awarded INT NOT NULL,
  completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, completed_at),
  INDEX (challenge_id, completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


// Level helpers
function level_for_xp($xp) { return max(1, floor($xp / 100) + 1); } // 100 XP per level
function next_level_xp($level) { return ($level) * 100; }

$errors = []; $notice = null; $leveledUp = false; $levelReached = null;

// Admin CRUD
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
  if (!verify_csrf($_POST['csrf'] ?? '')) { $errors[] = 'Invalid CSRF token.'; }
  else {
    $act = $_POST['admin_action'];
    if ($act === 'create') {
      $title = trim($_POST['title'] ?? '');
      $desc = trim($_POST['description'] ?? '');
      $xp = max(0, (int)($_POST['xp'] ?? 50));
      $freq = $_POST['frequency'] ?? 'once';
      $active = isset($_POST['is_active']) ? 1 : 0;
      if ($title === '' || !in_array($freq, ['once','daily','weekly','monthly'], true)) $errors[]='Fill required fields.';
      if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO challenges (title, description, xp, frequency, is_active) VALUES (?,?,?,?,?)');
        $stmt->execute([$title, $desc, $xp, $freq, $active]);
        $notice = 'Challenge created.';
      }
    } elseif ($act === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $title = trim($_POST['title'] ?? '');
      $desc = trim($_POST['description'] ?? '');
      $xp = max(0, (int)($_POST['xp'] ?? 50));
      $freq = $_POST['frequency'] ?? 'once';
      $active = isset($_POST['is_active']) ? 1 : 0;
      if ($id<=0) $errors[]='Invalid challenge id.';
      if ($title === '' || !in_array($freq, ['once','daily','weekly','monthly'], true)) $errors[]='Fill required fields.';
      if (!$errors) {
        $stmt = $pdo->prepare('UPDATE challenges SET title=?, description=?, xp=?, frequency=?, is_active=? WHERE id=?');
        $stmt->execute([$title, $desc, $xp, $freq, $active, $id]);
        $notice = 'Challenge updated.';
      }
    } elseif ($act === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id>0) {
        $stmt = $pdo->prepare('DELETE FROM challenges WHERE id=?');
        $stmt->execute([$id]);
        $notice = 'Challenge deleted.';
      }
    }
  }
}

// Handle user actions: start/complete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_action'])) {
  if (!verify_csrf($_POST['csrf'] ?? '')) { $errors[] = 'Invalid CSRF token.'; }
  else {
    $act = $_POST['user_action'];
    $cid = (int)($_POST['challenge_id'] ?? 0);
    // Fetch challenge
    $c = null;
    if ($cid>0) {
      $st = $pdo->prepare('SELECT * FROM challenges WHERE id=? AND is_active=1');
      $st->execute([$cid]);
      $c = $st->fetch();
    }
    if (!$c) { $errors[] = 'Challenge not found.'; }
    else {
      // Ensure user_challenges row
      $uc = $pdo->prepare('SELECT * FROM user_challenges WHERE user_id=? AND challenge_id=?');
      $uc->execute([$uid,$cid]);
      $row = $uc->fetch();
      if (!$row) {
        $pdo->prepare('INSERT INTO user_challenges (user_id, challenge_id, status) VALUES (?,?,?)')->execute([$uid,$cid,'in_progress']);
        $uc->execute([$uid,$cid]);
        $row = $uc->fetch();
      }

      // Complete action
      if ($act === 'complete') {
        // Check frequency lockout
        $now = new DateTime('now');
        $ok = true;
        if (!empty($row['last_completed_at'])) {
          $last = new DateTime($row['last_completed_at']);
          if ($c['frequency']==='daily' && $last->format('Y-m-d') === $now->format('Y-m-d')) $ok=false;
          if ($c['frequency']==='weekly' && $last->format('oW') === $now->format('oW')) $ok=false;
          if ($c['frequency']==='monthly' && $last->format('Y-m') === $now->format('Y-m')) $ok=false;
          // 'once' allowed only once
          if ($c['frequency']==='once' && (int)$row['times_completed']>0) $ok=false;
        }
        if ($ok) {
          $times = (int)$row['times_completed'] + 1;
          $streak = (int)$row['streak'] + 1;
          $pdo->prepare('UPDATE user_challenges SET status=?, times_completed=?, last_completed_at=NOW(), streak=? WHERE id=?')
              ->execute(['completed', $times, $streak, (int)$row['id']]);
          // Award XP
          $pr = $pdo->prepare('SELECT xp, level FROM user_progress WHERE user_id=?');
          $pr->execute([$uid]);
          $p = $pr->fetch();
          if (!$p) { $xp=0; $level=1; } else { $xp=(int)$p['xp']; $level=(int)$p['level']; }
          $xp += (int)$c['xp'];
          $newLevel = level_for_xp($xp);
          if ($p) $pdo->prepare('UPDATE user_progress SET xp=?, level=? WHERE user_id=?')->execute([$xp,$newLevel,$uid]);
          else $pdo->prepare('INSERT INTO user_progress (user_id,xp,level) VALUES (?,?,?)')->execute([$uid,$xp,$newLevel]);
          $notice = 'Challenge completed! +' . (int)$c['xp'] . ' XP';
$pdo->prepare('INSERT INTO challenge_logs (user_id, challenge_id, xp_awarded) VALUES (?,?,?)')->execute([$uid, $cid, (int)$c['xp']]);
        } else {
          $errors[] = 'This challenge is not available right now based on its frequency.';
        }
      } elseif ($act === 'start') {
        $pdo->prepare('UPDATE user_challenges SET status=? WHERE id=?')->execute(['in_progress',(int)$row['id']]);
        $notice = 'Challenge started.';
      }
    }
  }
}

// Fetch progress
$pr = $pdo->prepare('SELECT xp, level FROM user_progress WHERE user_id=?');
$pr->execute([$uid]);
$progress = $pr->fetch();
if (!$progress) { $progress = ['xp'=>0, 'level'=>1]; }
$totalXp = (int)$progress['xp'];
$level = (int)$progress['level'];
$nextXp = next_level_xp($level);
$currBase = next_level_xp($level-1);
$toNext = max(0, $nextXp - $totalXp);
$percent = $nextXp>$currBase ? round((($totalXp - $currBase)/($nextXp-$currBase))*100) : 0;

// Load challenges and user's availability
$rows = $pdo->query('SELECT * FROM challenges WHERE is_active=1 ORDER BY id DESC')->fetchAll();
$userRows = [];
if ($rows) {
  $ids = array_column($rows, 'id');
  $in = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare("SELECT * FROM user_challenges WHERE user_id=? AND challenge_id IN ($in)");
  $st->execute(array_merge([$uid], $ids));
  foreach ($st->fetchAll() as $r) { $userRows[(int)$r['challenge_id']] = $r; }
}
?>

<?php if ($notice): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="error"><ul><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>


<div id="levelUpModal" class="modal" hidden>
  <div class="modal-backdrop"></div>
  <div class="modal-card" style="text-align:center">
    <h3>🎉 Congratulations!</h3>
    <p class="big">You leveled up to <strong>Level <?= (int)($levelReached ?? $level ?? 1) ?></strong></p>
    <canvas id="fw-canvas" width="800" height="600" style="width:100%;height:240px;display:block;border-radius:12px"></canvas>
    <div class="actions" style="margin-top:10px">
      <button class="btn" id="lvl-close">Awesome!</button>
    </div>
  </div>
</div>

<div class="card">
  <div class="levelbar">
    <div class="lvl">Level <?= (int)$level ?></div>
    <div class="xp">XP: <?= (int)$totalXp ?> / <?= (int)$nextXp ?> (<?= (int)$percent ?>%)</div>
    <div class="bar"><div class="fill" style="width: <?= (int)$percent ?>%"></div></div>
  </div>


<?php
// Recent activity (last 10 completions)
$actStmt = $pdo->prepare('SELECT cl.completed_at, cl.xp_awarded, ch.title FROM challenge_logs cl JOIN challenges ch ON ch.id=cl.challenge_id WHERE cl.user_id=? ORDER BY cl.completed_at DESC, cl.id DESC LIMIT 3');
$actStmt->execute([$uid]);
$recentActs = $actStmt->fetchAll();
?>
<div class="card">
  <h3>Recent activity</h3>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>When</th><th>Challenge</th><th>XP</th></tr></thead>
      <tbody>
      <?php if ($recentActs): foreach($recentActs as $ra): ?>
        <tr><td><?= htmlspecialchars($ra['completed_at']) ?></td><td><?= htmlspecialchars($ra['title']) ?></td><td>+<?= (int)$ra['xp_awarded'] ?></td></tr>
      <?php endforeach; else: ?>
        <tr><td colspan="3" class="small muted">No activity yet. Complete a challenge to see it here.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div>

<div class="grid gap-16 cols-12">
  <?php if ($is_admin): ?>
  <div class="card span-12">
    <h2>Challenges Admin</h2>
    <p class="small">Create, edit, and delete challenges.</p>
    <details class="panel" open>
      <summary class="panel-head"><strong>Add a new challenge</strong></summary>
      <form method="post" class="form-grid">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="admin_action" value="create">
        <div class="input-group"><label>Title</label><input name="title" required></div>
        <div class="input-group"><label>Description</label><textarea name="description" rows="3"></textarea></div>
        <div class="grid cols-4 gap-8">
          <div class="input-group"><label>XP</label><input type="number" name="xp" value="50" min="0" required></div>
          <div class="input-group"><label>Frequency</label>
            <select name="frequency">
              <option value="once">Once</option>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>
          <label class="input-group" style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_active" checked> Active</label>
          <div class="actions" style="align-items:end"><button class="btn primary">Create</button></div>
        </div>
      </form>
    </details>

    <div class="table-wrap" style="margin-top:10px">
      <table class="table table-compact">
        <thead><tr><th>ID</th><th>Title</th><th>XP</th><th>Frequency</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach($rows as $c): ?>
          <tr>
            <td><?= (int)$c['id'] ?></td>
            <td><?= htmlspecialchars($c['title']) ?><br><span class="small muted"><?= nl2br(htmlspecialchars($c['description'])) ?></span></td>
            <td><?= (int)$c['xp'] ?></td>
            <td><?= htmlspecialchars($c['frequency']) ?></td>
            <td><?= ((int)$c['is_active']===1)?'Active':'Hidden' ?></td>
            <td>
              <details>
                <summary class="btn">Edit</summary>
                <form method="post" class="form-grid form-compact" style="margin-top:6px">
                  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="admin_action" value="update">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <div class="grid cols-4 gap-8">
                    <input name="title" value="<?= htmlspecialchars($c['title']) ?>" required>
                    <input name="xp" type="number" min="0" value="<?= (int)$c['xp'] ?>">
                    <select name="frequency">
                      <?php foreach(['once','daily','weekly','monthly'] as $f): ?>
                        <option value="<?= $f ?>" <?= $c['frequency']===$f?'selected':'' ?>><?= ucfirst($f) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_active" <?= ((int)$c['is_active']===1)?'checked':''; ?>> Active</label>
                  </div>
                  <textarea name="description" rows="2"><?= htmlspecialchars($c['description']) ?></textarea>
                  <div class="actions"><button class="btn primary" type="submit">Save</button></div>
                </form>
              </details>
              <form method="post" onsubmit="return confirm('Delete this challenge?');" style="display:inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="admin_action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Player view -->
  <div class="card span-12">
    <h2>Available Challenges</h2>
    <div class="grid cols-3 gap-12">
      <?php foreach($rows as $c): 
        $r = $userRows[$c['id']] ?? null;
        $available = true;
        if ($r && !empty($r['last_completed_at'])) {
          $now = new DateTime('now'); $last = new DateTime($r['last_completed_at']);
          if ($c['frequency']==='daily' && $last->format('Y-m-d') === $now->format('Y-m-d')) $available=false;
          if ($c['frequency']==='weekly' && $last->format('oW') === $now->format('oW')) $available=false;
          if ($c['frequency']==='monthly' && $last->format('Y-m') === $now->format('Y-m')) $available=false;
          if ($c['frequency']==='once' && (int)$r['times_completed']>0) $available=false;
        }
      ?>
      <div class="challenge-card">
        <div class="c-head">
          <span class="badge <?= htmlspecialchars($c['frequency']) ?>"><?= ucfirst(htmlspecialchars($c['frequency'])) ?></span>
          <span class="xp-badge">+<?= (int)$c['xp'] ?> XP</span>
        </div>
        <h3><?= htmlspecialchars($c['title']) ?></h3>
        <p class="muted"><?= nl2br(htmlspecialchars($c['description'])) ?></p>
        <?php if ($r): ?>
          <div class="small muted">Completed <?= (int)$r['times_completed'] ?>× · Streak <?= (int)$r['streak'] ?></div>
        <?php endif; ?>
        <form method="post" class="actions" style="margin-top:10px">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="challenge_id" value="<?= (int)$c['id'] ?>">
          <input type="hidden" name="user_action" value="complete">
          <button class="btn primary" <?= $available ? '' : 'disabled' ?>><?= $available ? 'Complete' : 'Not available now' ?></button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>


<script>
/* level up fireworks */
(function(){
  var modal = document.getElementById('levelUpModal');
  if (!modal) return;
  var closeBtn = document.getElementById('lvl-close');
  var backdrop = modal.querySelector('.modal-backdrop');
  function close(){ modal.setAttribute('hidden',''); document.body.classList.remove('modal-open'); stopFireworks(); }
  closeBtn && closeBtn.addEventListener('click', close);
  backdrop && backdrop.addEventListener('click', close);

  <?php if (!empty($leveledUp) && $leveledUp): ?>
    modal.removeAttribute('hidden'); document.body.classList.add('modal-open');
    startFireworks();
    setTimeout(function(){ stopFireworks(); }, 4000);
  <?php endif; ?>

  var raf, ctx, canvas, particles = [], running = false;
  function rand(min, max){ return Math.random()*(max-min)+min; }
  function color(){ var h = Math.floor(rand(0,360)); return 'hsl('+h+', 90%, 60%)'; }
  function resize(){
    canvas.width = modal.querySelector('.modal-card').clientWidth - 32;
    canvas.height = 260;
  }
  function spawn(x, y){
    for (var i=0;i<140;i++){
      particles.push({
        x:x, y:y,
        vx: Math.cos(i)*rand(1,4) + rand(-2,2),
        vy: Math.sin(i)*rand(1,4) + rand(-2,2),
        life: rand(40,90),
        c: color(),
        r: rand(1,3)
      });
    }
  }
  function step(){
    ctx.clearRect(0,0,canvas.width,canvas.height);
    for (var i=particles.length-1;i>=0;i--){
      var p = particles[i];
      p.x += p.vx;
      p.y += p.vy;
      p.vy += 0.05; // gravity
      p.life--;
      ctx.globalAlpha = Math.max(0, p.life/90);
      ctx.beginPath();
      ctx.arc(p.x, p.y, p.r, 0, Math.PI*2);
      ctx.fillStyle = p.c;
      ctx.fill();
      if (p.life <= 0) particles.splice(i,1);
    }
    if (running) raf = requestAnimationFrame(step);
  }
  window.startFireworks = function(){
    canvas = document.getElementById('fw-canvas');
    if (!canvas) return;
    ctx = canvas.getContext('2d');
    resize();
    running = true;
    // spawn a few bursts
    var w = canvas.width, h = canvas.height;
    spawn(w*0.3, h*0.6);
    setTimeout(function(){ spawn(w*0.7, h*0.5); }, 400);
    setTimeout(function(){ spawn(w*0.5, h*0.4); }, 800);
    step();
  };
  window.stopFireworks = function(){
    running = false; cancelAnimationFrame(raf); particles = [];
    if (ctx && canvas) ctx.clearRect(0,0,canvas.width,canvas.height);
  };
  window.addEventListener('resize', function(){ if (canvas) resize(); });
})();
</script>

<?php include __DIR__.'/partials/footer.php'; ?>

