<?php
$page_title='Forgot Password';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
include __DIR__.'/partials/header.php';

$info = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $identifier = trim($_POST['identifier'] ?? '');
  $csrf = $_POST['csrf'] ?? '';
  if (!verify_csrf($csrf)) $errors[] = 'Invalid CSRF token.';
  if ($identifier === '') $errors[] = 'Please enter your email or username.';

  if (!$errors) {
    // find user by email or username
    $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ? OR username = ? LIMIT 1');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();
    if ($user) {
      // create token valid for 30 minutes
      $token = bin2hex(random_bytes(32));
      $expires = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');
      $stmt = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)');
      $stmt->execute([$user['id'], $token, $expires]);

      // In production: send email with the reset link to $user['email'].
      // For local dev, we show the link below:
      $info = 'A reset link has been generated. For local testing, use the link below.';
      $resetLink = base_path() . '/reset_password.php?token=' . urlencode($token);
    } else {
      // Don't reveal whether user exists
      $info = 'If the account exists, a reset link will be sent.';
    }
  }
}
?>
<div class="card" style="max-width:520px;margin:0 auto">
  <h2>Forgot Password</h2>

  <?php if (!empty($errors)): ?>
    <div class="card" style="background:#fdecea;border-color:#f5c2c7">
      <p class="small" style="color:#b91c1c"><?= htmlspecialchars(implode(' ', $errors)); ?></p>
    </div>
  <?php endif; ?>

  <?php if ($info): ?>
    <div class="card" style="background:#e0f2fe;border-color:#bae6fd">
      <p class="small" style="color:#075985"><?= htmlspecialchars($info); ?></p>
      <?php if (!empty($resetLink)): ?>
        <p class="small"><strong>Dev link:</strong> <a href="<?= htmlspecialchars($resetLink) ?>"><?= htmlspecialchars($resetLink) ?></a></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
    <label>Email or Username</label>
    <input type="text" name="identifier" required placeholder="you@example.com or yourusername">
    <div class="actions">
      <button class="btn primary" type="submit">Send reset link</button>
      <a class="btn" href="<?= base_path() ?>/login.php">Back to login</a>
    </div>
  </form>
</div>
<?php include __DIR__.'/partials/footer.php'; ?>
