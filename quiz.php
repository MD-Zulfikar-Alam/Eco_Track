
<?php
$page_title='Quiz';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/db.php';
require_login();
include __DIR__.'/partials/header.php';

// --- Ensure table exists (defensive) ---
$pdo->exec("CREATE TABLE IF NOT EXISTS quiz_questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  question_text TEXT NOT NULL,
  option_a VARCHAR(255) NOT NULL,
  option_b VARCHAR(255) NOT NULL,
  option_c VARCHAR(255) NOT NULL,
  option_d VARCHAR(255) NOT NULL,
  correct_option ENUM('A','B','C','D') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Ensure attempts table exists
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



// --- SEED QUESTIONS (only if table is empty) ---
try {
  $count = (int)$pdo->query("SELECT COUNT(*) FROM quiz_questions")->fetchColumn();
  if ($count === 0) {
    $seed = $pdo->prepare('INSERT INTO quiz_questions (question_text, option_a, option_b, option_c, option_d, correct_option, is_active) VALUES (?,?,?,?,?,?,1)');
    $questions = [
      ["Which gas is primarily responsible for global warming?", "Oxygen", "Methane", "Carbon dioxide", "Nitrogen", "C"],
      ["What is a common renewable energy source?", "Coal", "Wind", "Diesel", "Petrol", "B"],
      ["Which practice reduces plastic waste?", "Using single-use bags", "Using reusable bags", "Burning plastic", "Throwing in rivers", "B"],
      ["Deforestation mainly increases which gas in the atmosphere?", "Carbon dioxide", "Helium", "Neon", "Hydrogen", "A"],
      ["Which of these is an example of public transport?", "Car", "Motorbike", "Bus", "Scooter", "C"],
      ["Which appliance typically uses the most household electricity?", "LED bulb", "Refrigerator", "Phone charger", "Toaster", "B"],
      ["Planting trees helps by:", "Increasing CO₂", "Reducing CO₂", "Creating more plastic", "Heating cities", "B"],
      ["Which diet change tends to lower your footprint?", "More red meat", "More plant-based meals", "More air-freighted fruit", "More dairy", "B"],
      ["What does kWh measure?", "Fuel volume", "Electrical energy", "Water pressure", "Air speed", "B"],
      ["Which transport emits the most CO₂ per km per person (typical)?", "Cycling", "Walking", "Commercial flight", "Train", "C"],
      ["Best way to dispose of e-waste?", "Landfill", "Burning", "Certified e-waste recycling", "Throw in regular trash", "C"],
      ["A smart thermostat saves energy by:", "Heating when windows open", "Maintaining constant high temp", "Optimizing heating/cooling schedules", "Always on", "C"],
      ["Which label indicates high energy efficiency?", "Energy Star", "High Watt", "Ultra Power", "Super Heat", "A"],
      ["Carbon footprint is measured mostly in:", "Liters", "Kilograms of CO₂ equivalent", "Watts", "Lumens", "B"],
      ["Which action saves water at home?", "Fixing leaks", "Longer showers", "Running half-empty dishwasher", "Watering at noon", "A"]
    ];
    foreach ($questions as $q) { $seed->execute($q); }
  }
} catch (Throwable $e) { /* ignore seed errors */ }



// --- Admin check: users with id 1-3 are admins ---
$is_admin = in_array((int)$_SESSION['user']['id'], [1,2,3], true);
// --- Handle admin CRUD actions ---
$errors = []; $notice = null;
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verify_csrf($_POST['csrf'] ?? '')) {
    $errors[] = 'Invalid CSRF token.';
  } else {
    $action = $_POST['admin_action'] ?? '';
    if ($action === 'create') {
      $q = trim($_POST['question_text'] ?? '');
      $a = trim($_POST['option_a'] ?? '');
      $b = trim($_POST['option_b'] ?? '');
      $c = trim($_POST['option_c'] ?? '');
      $d = trim($_POST['option_d'] ?? '');
      $corr = strtoupper(trim($_POST['correct_option'] ?? ''));
      $active = isset($_POST['is_active']) ? 1 : 0;
      if ($q === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($corr, ['A','B','C','D'], true)) {
        $errors[] = 'Please fill all fields and pick a valid correct option (A–D).';
      } else {
        $stmt = $pdo->prepare('INSERT INTO quiz_questions (question_text, option_a, option_b, option_c, option_d, correct_option, is_active) VALUES (?,?,?,?,?,?,?)');
        $stmt->execute([$q,$a,$b,$c,$d,$corr,$active]);
        $notice = 'Question added.';
      }
    } elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $q = trim($_POST['question_text'] ?? '');
      $a = trim($_POST['option_a'] ?? '');
      $b = trim($_POST['option_b'] ?? '');
      $c = trim($_POST['option_c'] ?? '');
      $d = trim($_POST['option_d'] ?? '');
      $corr = strtoupper(trim($_POST['correct_option'] ?? ''));
      $active = isset($_POST['is_active']) ? 1 : 0;
      if ($id <= 0) { $errors[] = 'Invalid question id.'; }
      if ($q === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($corr, ['A','B','C','D'], true)) {
        $errors[] = 'Please fill all fields and pick a valid correct option (A–D).';
      }
      if (!$errors) {
        $stmt = $pdo->prepare('UPDATE quiz_questions SET question_text=?, option_a=?, option_b=?, option_c=?, option_d=?, correct_option=?, is_active=? WHERE id=?');
        $stmt->execute([$q,$a,$b,$c,$d,$corr,$active,$id]);
        $notice = 'Question updated.';
      }
    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $stmt = $pdo->prepare('DELETE FROM quiz_questions WHERE id = ?');
        $stmt->execute([$id]);
        $notice = 'Question deleted.';
      } else {
        $errors[] = 'Invalid question id.';
      }
    }
  }
}

// --- Fetch questions for admin list ---
$allQuestions = [];
if ($is_admin) {
  $qstmt = $pdo->query('SELECT * FROM quiz_questions ORDER BY id DESC');
  $allQuestions = $qstmt->fetchAll();
}

// --- For user quiz: pick up to 10 random active questions (GET only) ---
$quizQuestions = $quizQuestions ?? [];
if (!isset($_POST['submit_quiz'])) {
  $qstmt2 = $pdo->query('SELECT * FROM quiz_questions WHERE is_active = 1 ORDER BY RAND() LIMIT 10');
  $quizQuestions = $qstmt2->fetchAll();
}
// --- Handle quiz submission (user) ---
$score = null; $total = 0; $selectedQuestions = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
  if (!verify_csrf($_POST['csrf'] ?? '')) { $errors[] = 'Invalid CSRF token.'; }
  else {
    $ids_raw = $_POST['quiz_ids'] ?? '';
    $ids = array_values(array_filter(array_map('intval', explode(',', $ids_raw)), fn($x)=>$x>0));
    if ($ids) {
      $in = implode(',', array_fill(0, count($ids), '?'));
      $stmt = $pdo->prepare("SELECT * FROM quiz_questions WHERE is_active = 1 AND id IN ($in)");
      $stmt->execute($ids);
      $selectedQuestions = $stmt->fetchAll();
      // Keep the same order as IDs received
      $map = []; foreach ($selectedQuestions as $row) { $map[(int)$row['id']] = $row; }
      $quizQuestions = []; foreach ($ids as $qqid) { if (isset($map[$qqid])) { $quizQuestions[] = $map[$qqid]; } }
      $score = 0; $total = count($quizQuestions);
      foreach ($quizQuestions as $qq) {
        $qid = (int)$qq['id']; $ans = strtoupper($_POST['ans_'.$qid] ?? '');
        if (in_array($ans, ['A','B','C','D'], true) && $ans === $qq['correct_option']) { $score++; }
      $percentage = ($total > 0) ? round(($score / $total) * 100, 2) : 0;
/* compliment and store */
$compliment = 'Nice try — keep learning!';
if ($percentage >= 90) $compliment = 'Outstanding! Eco Champion 🌿';
elseif ($percentage >= 70) $compliment = 'Great job! You really know your stuff ♻️';
elseif ($percentage >= 40) $compliment = 'Good start — keep going!';
$idsCsv = isset($ids) ? implode(',', $ids) : '';
$ins = $pdo->prepare('INSERT INTO quiz_attempts (user_id, score, total, percentage, question_ids) VALUES (?,?,?,?,?)');
$ins->execute([ (int)$_SESSION['user']['id'], (int)$score, (int)$total, $percentage, $idsCsv ]);
}
    } else { $errors[] = 'No questions submitted.'; }
  }
}


?>

<?php if ($notice): ?><div class="notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
<?php if ($errors): ?>
  <div class="error">
    <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>
<?php
// --- Attempts history & averages for the current user ---
try {
  $uid = (int)$_SESSION['user']['id'];
  // last 5 attempts
  $histStmt = $pdo->prepare("SELECT score, total, percentage, created_at FROM quiz_attempts WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 5");
  $histStmt->execute([$uid]);
  $myAttempts = $histStmt->fetchAll();

  // overall average
  $avgStmt = $pdo->prepare("SELECT AVG(percentage) AS avg_pct, COUNT(*) AS n, AVG(score) AS avg_score, AVG(total) AS avg_total FROM quiz_attempts WHERE user_id = ?");
  $avgStmt->execute([$uid]);
  $myAvg = $avgStmt->fetch();
} catch (Throwable $e) {
  $myAttempts = [];
  $myAvg = ['avg_pct'=>null,'n'=>0,'avg_score'=>null,'avg_total'=>null];
}
?>



<div class="card stats-card">
  <div class="stats-row">
    <div class="stat">
      <div class="stat-kicker">Overall average</div>
      <div class="stat-value">
        <?php if (!empty($myAvg) && $myAvg['n']>0): ?>
          <?= number_format((float)$myAvg['avg_pct'], 1) ?>%
        <?php else: ?>
          —
        <?php endif; ?>
      </div>
      <div class="stat-sub">Based on <?= (int)($myAvg['n'] ?? 0) ?> attempts</div>
    </div>
    <div class="stat">
      <div class="stat-kicker">Avg. score</div>
      <div class="stat-value">
        <?php if (!empty($myAvg) && $myAvg['n']>0): ?>
          <?= number_format((float)$myAvg['avg_score'], 1) ?> / <?= number_format((float)$myAvg['avg_total'], 1) ?>
        <?php else: ?>
          —
        <?php endif; ?>
      </div>
      <div class="stat-sub">All-time</div>
    </div>
  </div>

  <div class="table-wrap" style="margin-top:10px">
    <table class="table table-compact">
      <thead><tr><th>Date</th><th>Score</th><th>Percentage</th></tr></thead>
      <tbody>
        <?php if ($myAttempts): foreach ($myAttempts as $a): ?>
          <tr>
            <td><?= htmlspecialchars($a['created_at']) ?></td>
            <td><?= (int)$a['score'] ?> / <?= (int)$a['total'] ?></td>
            <td><?= number_format((float)$a['percentage'], 1) ?>%</td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="3" class="small muted">No attempts yet — take the quiz to see your history here.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid gap-16 cols-12">

  <?php if ($is_admin): ?>
  <!-- Admin Panel -->
  <div class="card span-12" id="admin">
    <h2>Quiz Admin</h2>
    <p class="small">Users with IDs 1–3 are admins. You can create, edit, and delete questions.</p>

    <details open class="panel"><summary class="panel-head"><strong>Add a new question</strong></summary>
      <form method="post" class="form-grid">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="admin_action" value="create">
        <div class="input-group"><label>Question</label><textarea name="question_text" rows="3" required style="width:100%"></textarea></div>
        <div class="grid cols-2 gap-8">
          <div class="input-group"><label>Option A</label><input name="option_a" required></div>
          <div class="input-group"><label>Option B</label><input name="option_b" required></div>
          <div class="input-group"><label>Option C</label><input name="option_c" required></div>
          <div class="input-group"><label>Option D</label><input name="option_d" required></div>
        </div>
        <div class="input-group"><label>Correct option</label>
          <select name="correct_option" required>
            <option value="">Select…</option>
            <option value="A">A</option>
            <option value="B">B</option>
            <option value="C">C</option>
            <option value="D">D</option>
          </select>
        </div>
        <label style="display:flex;align-items:center;gap:8px"><input style="width:16px;height:16px" type="checkbox" name="is_active" checked> Active</label>
        <div class="actions"><button class="btn primary" type="submit">Add question</button></div>
      </form>
    </details>
  </div>

  <div class="card span-12" id="take-quiz">
    <h3>All questions</h3>
    <div class="input-group mb-8"><input id="qFilter" type="text" placeholder="Filter questions... (text or option)"></div>
    <?php if ($allQuestions): ?>
      <div class="table-wrap">
        <table class="table table-compact">
          <thead><tr><th>ID</th><th>Question</th><th>Correct</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($allQuestions as $q): ?>
              <tr>
                <td><?= (int)$q['id'] ?></td>
                <td><?= nl2br(htmlspecialchars($q['question_text'])) ?><br>
                  <span class="small muted">A: <?= htmlspecialchars($q['option_a']) ?> · B: <?= htmlspecialchars($q['option_b']) ?> · C: <?= htmlspecialchars($q['option_c']) ?> · D: <?= htmlspecialchars($q['option_d']) ?></span>
                </td>
                <td><?= htmlspecialchars($q['correct_option']) ?></td>
                <td><?= ((int)$q['is_active'] === 1) ? 'Active' : 'Hidden' ?></td>
                <td style="white-space:nowrap">
                  <details>
                    <summary class="btn" style="display:inline-flex;cursor:pointer">Edit</summary>
                    <form method="post" class="form-grid form-compact">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
  <?php $idsStr = implode(',', array_map(fn($x)=>$x['id'], $quizQuestions)); ?>
  <input type="hidden" name="quiz_ids" value="<?= htmlspecialchars($idsStr) ?>">

                      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                      <input type="hidden" name="admin_action" value="update">
                      <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                      <textarea name="question_text" rows="3" required style="width:100%"><?= htmlspecialchars($q['question_text']) ?></textarea>
                      <div class="grid cols-2 gap-6">
                        <input name="option_a" value="<?= htmlspecialchars($q['option_a']) ?>" required>
                        <input name="option_b" value="<?= htmlspecialchars($q['option_b']) ?>" required>
                        <input name="option_c" value="<?= htmlspecialchars($q['option_c']) ?>" required>
                        <input name="option_d" value="<?= htmlspecialchars($q['option_d']) ?>" required>
                      </div>
                      <div><label>Correct
                        <select name="correct_option" required>
                          <?php foreach (['A','B','C','D'] as $op): ?>
                            <option value="<?= $op ?>" <?= $q['correct_option']===$op?'selected':'' ?>><?= $op ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label></div>
                      <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="is_active" <?= ((int)$q['is_active']===1)?'checked':''; ?>> Active</label>
                      <div class="actions">
                        <button class="btn primary" type="submit">Save</button>
                      </div>
                    </form>
                  </details>
                  <form method="post" onsubmit="return confirm('Delete this question?');" style="display:inline">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
                    <input type="hidden" name="admin_action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                    <button class="btn" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <p class="small">No questions yet.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- User Quiz -->
  <div class="card span-12">
    
<div id="quizResultModal" class="modal" hidden>
  <div class="modal-backdrop"></div>
  <div class="modal-card">
    <h3>Quiz Result</h3>
    <p class="big"><span id="qr-score"></span> / <span id="qr-total"></span> (<span id="qr-pct"></span>%)</p>
    <p id="qr-compliment" class="muted"></p>
    <div class="actions">
      <button class="btn" id="qr-close">Close</button>
    </div>
  </div>
</div>
<h2>Take the quiz</h2>
    <?php if (!$quizQuestions): ?>
      <p class="small">No active questions available yet. Please check back later.</p>
    <?php else: ?>
      
<?php if ($score !== null): ?>
  <div class="notice">You scored <strong><?= (int)$score ?></strong> out of <strong><?= (int)$total ?></strong>.</div>

  <div class="card review-card">
    <h3>Review your answers</h3>
    <div class="review-list">
      <?php foreach ($quizQuestions as $idx => $qq): 
        $qid = (int)$qq['id'];
        $userAns = strtoupper($_POST['ans_'.$qid] ?? '');
        $correct = $qq['correct_option'];
        $opts = [
          'A' => $qq['option_a'],
          'B' => $qq['option_b'],
          'C' => $qq['option_c'],
          'D' => $qq['option_d']
        ];
        $isRight = ($userAns === $correct);
      ?>
        <div class="review-item">
          <div class="review-q"><strong>Q<?= $idx+1 ?>.</strong> <?= nl2br(htmlspecialchars($qq['question_text'])) ?></div>
          <div class="review-a">
            <span class="badge <?= $isRight ? 'ok' : 'no' ?>">
              <?= $isRight ? 'Correct' : 'Incorrect' ?>
            </span>
            <div class="review-rows">
              <div><span class="muted">Your answer:</span> <strong><?= htmlspecialchars($userAns ?: '—') ?></strong> <?= $userAns ? '· '.htmlspecialchars($opts[$userAns] ?? '') : '' ?></div>
              <div><span class="muted">Correct answer:</span> <strong><?= htmlspecialchars($correct) ?></strong> · <?= htmlspecialchars($opts[$correct] ?? '') ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="actions">
      <form method="get">
        <button class="btn primary" type="submit" name="practice" value="1">Practice More</button>
      </form>
    </div>
  </div>
<?php endif; ?>

      <form method="post" class="grid" style="gap:16px">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
  <?php $idsStr = implode(',', array_map(fn($x)=>$x['id'], $quizQuestions)); ?>
  <input type="hidden" name="quiz_ids" value="<?= htmlspecialchars($idsStr) ?>">
<?php foreach ($quizQuestions as $idx => $q): $qid=(int)$q['id']; ?>
          <div class="card quiz-card">
            <h3>Q<?= $idx+1 ?>. <?= nl2br(htmlspecialchars($q['question_text'])) ?></h3>
            <div class="grid cols-2 gap-8">
              <?php foreach (['A','B','C','D'] as $op): 
                $field = 'option_'.strtolower($op); ?>
                <label class="option">
                  <input type="radio" name="ans_<?= $qid ?>" value="<?= $op ?>" required>
                  <span><strong><?= $op ?>.</strong> <?= htmlspecialchars($q[$field]) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <div class="actions"><button class="btn primary" type="submit" name="submit_quiz" value="1">Submit Quiz</button></div>
      </form>
    <?php endif; ?>
  </div>



</div>


<script>
(function(){
  const f = document.getElementById('qFilter');
  const table = document.querySelector('.table');
  if (!f || !table) return;
  const tbody = table.querySelector('tbody');
  f.addEventListener('input', ()=>{
    const q = f.value.toLowerCase();
    for (const tr of tbody.querySelectorAll('tr')) {
      tr.style.display = tr.innerText.toLowerCase().includes(q) ? '' : 'none';
    }
  });
})();
</script>

<script>
// /* quiz result modal */
(function(){
  var modal = document.getElementById('quizResultModal');
  if (!modal) return;
  var closeBtn = document.getElementById('qr-close');
  var backdrop = modal.querySelector('.modal-backdrop');
  function close(){ modal.setAttribute('hidden',''); document.body.classList.remove('modal-open'); }
  closeBtn && closeBtn.addEventListener('click', close);
  backdrop && backdrop.addEventListener('click', close);
  <?php if (isset($score) && $score !== null): ?>
    var score = <?= (int)$score ?>;
    var total = <?= (int)$total ?>;
    var pct = <?= isset($percentage) ? (float)$percentage : 0 ?>;
    var compliment = <?= json_encode(isset($compliment) ? $compliment : "") ?>;
    document.getElementById('qr-score').textContent = score;
    document.getElementById('qr-total').textContent = total;
    document.getElementById('qr-pct').textContent = pct;
    document.getElementById('qr-compliment').textContent = compliment;
    modal.removeAttribute('hidden');
    document.body.classList.add('modal-open');
  <?php endif; ?>
})();
</script>

<?php include __DIR__.'/partials/footer.php'; ?>
