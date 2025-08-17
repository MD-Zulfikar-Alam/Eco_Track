


<?php
$page_title='Dashboard';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';
require_login(); // gate
include __DIR__.'/partials/header.php';

/* trees (dashboard) */
// Ensure map_markers table & columns exist for tree stats
$pdo->exec("CREATE TABLE IF NOT EXISTS map_markers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  title VARCHAR(140) NOT NULL,
  description TEXT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  is_global TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (user_id, is_global),
  INDEX (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
try { $pdo->query("ALTER TABLE map_markers ADD COLUMN is_tree TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->query("ALTER TABLE map_markers ADD COLUMN tree_count INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}

$uid = (int)$_SESSION['user']['id'];
$sumGlobal = $pdo->query("SELECT COALESCE(SUM(tree_count),0) FROM map_markers WHERE is_tree=1 AND is_global=1")->fetchColumn();
$sumMine = $pdo->prepare("SELECT COALESCE(SUM(tree_count),0) FROM map_markers WHERE is_tree=1 AND is_global=0 AND user_id=?");
$sumMine->execute([$uid]);
$trees_global = (int)$sumGlobal;
$trees_mine = (int)$sumMine->fetchColumn();


/* tip-of-day (dashboard) */
// Ensure eco_tips exists
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
// Choose a deterministic daily tip (same for everyone on a given day)
$tipOfDay = null;
$cnt = (int)$pdo->query("SELECT COUNT(*) FROM eco_tips WHERE is_active=1")->fetchColumn();
if ($cnt > 0) {
  $offset = (int)date('z') % $cnt; // 0..365
  $st = $pdo->prepare("SELECT id, title, tip_text, category FROM eco_tips WHERE is_active=1 ORDER BY id LIMIT 1 OFFSET ?");
  $st->bindValue(1, $offset, PDO::PARAM_INT);
  $st->execute();
  $tipOfDay = $st->fetch();
}
$is_admin = in_array((int)$_SESSION['user']['id'], [1,2,3], true);
/* progress level (dashboard) */
// Defensive: ensure user_progress table
$pdo->exec("CREATE TABLE IF NOT EXISTS user_progress (user_id INT PRIMARY KEY, xp INT NOT NULL DEFAULT 0, level INT NOT NULL DEFAULT 1, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$uid = (int)$_SESSION['user']['id'];
$pr = $pdo->prepare('SELECT xp, level FROM user_progress WHERE user_id=?');
$pr->execute([$uid]);
$progress = $pr->fetch();
if (!$progress) { $progress = ['xp'=>0, 'level'=>1]; }
$xp = (int)$progress['xp'];
$level = (int)$progress['level'];
// Level math: 100 XP per level (same as Challenges)
$nextXp = max(100, $level * 100);
$baseXp = max(0, ($level-1) * 100);
$pct = $nextXp > $baseXp ? round((($xp - $baseXp) / ($nextXp - $baseXp)) * 100) : 0;

// Compute averages for this user
$uid = $_SESSION['user']['id'];
// All-time averages
$avgStmt = $pdo->prepare('SELECT COUNT(*) as n, AVG(total_kg) as avg_total, AVG(electricity_kg) as avg_elec, AVG(transport_kg) as avg_trans, AVG(meat_kg) as avg_meat, AVG(flights_kg) as avg_fly FROM footprints WHERE user_id = ?');
$avgStmt->execute([$uid]);
$averages = $avgStmt->fetch();

// Last 30 days averages
$recentStmt = $pdo->prepare('SELECT COUNT(*) as n, AVG(total_kg) as avg_total FROM footprints WHERE user_id = ? AND recorded_on >= (CURRENT_DATE - INTERVAL 30 DAY)');
$recentStmt->execute([$uid]);
$recentAvg = $recentStmt->fetch();
?>

<?php
// --- Quiz history & averages (dashboard) ---
try {
  $uid = (int)$_SESSION['user']['id'];
  // (defensive) ensure attempts table exists
  $pdo->exec("CREATE TABLE IF NOT EXISTS quiz_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    score INT NOT NULL,
    total INT NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    question_ids TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $qAvgStmt = $pdo->prepare("SELECT AVG(percentage) AS avg_pct, COUNT(*) AS n FROM quiz_attempts WHERE user_id = ?");
  $qAvgStmt->execute([$uid]);
  $quizAvg = $qAvgStmt->fetch();

  $qHistStmt = $pdo->prepare("SELECT score, total, percentage, created_at FROM quiz_attempts WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 5");
  $qHistStmt->execute([$uid]);
  $quizLast = $qHistStmt->fetchAll();
} catch (Throwable $e) {
  $quizAvg = ['avg_pct'=>null,'n'=>0];
  $quizLast = [];
}
?>


<div class="card"><h2>Dashboard</h2><p class='small'>Welcome back! Here's a snapshot of your uses history.</p></div>

<div class="grid" style="gap:16px">
  
<div class="card span-6">
  <h3>Your average (all saved entries)</h3>
  <?php if ($averages && (int)$averages['n'] > 0): ?>
    <div class="table-wrap"><table class="table">
      <tr><th style="width:40%">Entries counted</th><td><?= (int)$averages['n'] ?></td></tr>
      <tr><th>Total</th><td><strong><?= number_format($averages['avg_total'], 2) ?></strong> kg/mo</td></tr>
      <tr><th>Electricity</th><td><?= number_format($averages['avg_elec'], 2) ?> kg/mo</td></tr>
      <tr><th>Transport</th><td><?= number_format($averages['avg_trans'], 2) ?> kg/mo</td></tr>
      <tr><th>Meat</th><td><?= number_format($averages['avg_meat'], 2) ?> kg/mo</td></tr>
      <tr><th>Flights</th><td><?= number_format($averages['avg_fly'], 2) ?> kg/mo</td></tr>
    </table></div>
  <?php else: ?>
    <p class="small">No saved entries yet. Head to <a href="calculator.php">Calculator</a> and click <strong>Calculate &amp; Save</strong> to build your history.</p>
  <?php endif; ?>
</div>

  </div>

  <div class="card span-6">
    <h3>Last 30 days</h3>
    <?php if ($recentAvg && (int)$recentAvg['n'] > 0): ?>
      <p>Your average total in the last 30 days: <strong><?= number_format($recentAvg['avg_total'], 2) ?></strong> kg/mo</p>
      <p class="small muted">Based on <?= (int)$recentAvg['n'] ?> entries recorded since <?= date('Y-m-d', strtotime('-30 days')) ?>.</p>
    <?php else: ?>
      <p class="small">No entries in the last 30 days.</p>
    <?php endif; ?>
    <div class="actions">
      <a class="btn primary" href="calculator.php">Add a new entry</a>
    </div>
  </div>
</div>


<div class="card span-12" style="margin-top:16px">
  
<div class="card span-12" style="margin-top:16px">
  <h3>Tip of the day</h3>
  <?php if ($tipOfDay): ?>
    <h2 style="margin-top:6px"><?= htmlspecialchars($tipOfDay['title']) ?></h2>
    <p class="muted"><?= nl2br(htmlspecialchars($tipOfDay['tip_text'])) ?></p>
    <?php if (!empty($tipOfDay['category'])): ?><div class="badge"><?= htmlspecialchars($tipOfDay['category']) ?></div><?php endif; ?>
    <div class="actions" style="margin-top:8px">
      <a class="btn" href="tips.php">More tips</a>
      <?php if ($is_admin): ?><a class="btn" href="tips.php">Manage tips</a><?php endif; ?>
    </div>
  <?php else: ?>
    <p class="small muted">No tips yet. <a href="tips.php">Add some tips</a> to get started.</p>
  <?php endif; ?>
</div>

  
<div class="card span-12" style="margin-top:16px">
  <h3>Trees planted</h3>
  <div class="stats-row">
    <div class="stat"><div class="kpi"><?= (int)$trees_mine ?></div><div class="label">Your trees</div></div>
    <div class="stat"><div class="kpi"><?= (int)$trees_global ?></div><div class="label">Community trees</div></div>
    <div class="stat"><div class="kpi"><?= (int)($trees_mine + $trees_global) ?></div><div class="label">Total (you + community)</div></div>
    <div class="actions" style="align-self:end"><a class="btn" href="map.php">View on map</a></div>
  </div>
</div>

  <h3>Your progress</h3>
  <div class="levelbar" style="margin-top:6px">
    <div class="lvl">Level <?= (int)$level ?></div>
    <div class="xp">XP: <?= (int)$xp ?> / <?= (int)$nextXp ?> (<?= (int)$pct ?>%)</div>
    <div class="bar"><div class="fill" style="width: <?= (int)$pct ?>%"></div></div>
  </div>
  <div class="actions" style="margin-top:10px">
    <a class="btn" href="challenges.php">Open Challenges</a>
  </div>
</div>
<div class="card span-12" style="margin-top:16px">
  <h3>Your quiz performance</h3>
  <div class="stats-row" style="margin:6px 0 10px 0">
    <div class="stat">
      <div class="stat-kicker">Overall average</div>
      <div class="stat-value">
        <?php if (!empty($quizAvg) && (int)$quizAvg['n']>0): ?>
          <?= number_format((float)$quizAvg['avg_pct'], 1) ?>%
        <?php else: ?>
          —
        <?php endif; ?>
      </div>
      <div class="stat-sub">Across <?= (int)($quizAvg['n'] ?? 0) ?> attempts</div>
    </div>
  </div>

  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Date</th><th>Score</th><th>Percentage</th></tr></thead>
      <tbody>
        <?php if (!empty($quizLast)): foreach ($quizLast as $a): ?>
          <tr>
            <td><?= htmlspecialchars($a['created_at']) ?></td>
            <td><?= (int)$a['score'] ?> / <?= (int)$a['total'] ?></td>
            <td><?= number_format((float)$a['percentage'], 1) ?>%</td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="3" class="small muted">You haven't taken any quizzes yet. <a href="quiz.php">Take a quiz</a>.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="actions" style="margin-top:10px">
    <a class="btn primary" href="quiz.php">Go to Quiz</a>
  </div>
</div>


</div>

<?php include __DIR__.'/partials/footer.php'; ?>
