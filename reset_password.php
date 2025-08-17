<?php
$page_title='Reset Password';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
include __DIR__.'/partials/header.php';

$errors = [];
$token = $_GET['token'] ?? '';

function validate_password($p){
  // >=8, at least one upper, one lower, one digit, and one of @ $ #
  return preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$#])[A-Za-z\d@$#]{8,}$/', $p);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['token'] ?? '';
  $password = $_POST['password'] ?? '';
  $confirm = $_POST['confirm'] ?? '';
  $csrf = $_POST['csrf'] ?? '';

  if (!verify_csrf($csrf)) $errors[] = 'Invalid CSRF token.';
  if (!validate_password($password)) $errors[] = 'Password must be at least 8 characters and include at least one uppercase letter, one lowercase letter, one digit, and one of $, @, #.';
  if ($password !== $confirm) $errors[] = 'Passwords do not match.';

  if (!$errors) {
    // Look up token
    $stmt = $pdo->prepare('SELECT pr.id, pr.user_id, pr.expires_at FROM password_resets pr WHERE pr.token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if ($row) {
      $now = new DateTime();
      $exp = new DateTime($row['expires_at']);
      if ($now <= $exp) {
        // Update password
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->beginTransaction();
        try {
          $u = $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?');
          $u->execute([$hash, $row['user_id']]);
          // Invalidate this token
          $d = $pdo->prepare('DELETE FROM password_resets WHERE id=?');
          $d->execute([$row['id']]);
          $pdo->commit();
          // Redirect to login
          redirect('/login.php');
        } catch (Throwable $e) {
          $pdo->rollBack();
          $errors[] = 'Failed to reset password. Please try again.';
        }
      } else {
        $errors[] = 'Reset link has expired. Please request a new one.';
      }
    } else {
      $errors[] = 'Invalid or already used reset link.';
    }
  }
}
?>
<div class="card" style="max-width:520px;margin:0 auto">
  <h2>Reset Password</h2>

  <?php if (!empty($errors)): ?>
    <div class="card" style="background:#fdecea;border-color:#f5c2c7">
      <p class="small" style="color:#b91c1c"><?= htmlspecialchars(implode(' ', $errors)); ?></p>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <label>New password</label>
    <input type="password" id="pw" name="password" minlength="8" required placeholder="At least 8 chars, 1 upper, 1 lower, 1 digit & one of $, @, #">
    <p class="small">Password must be at least 8 characters and include: <strong>one uppercase</strong>, <strong>one lowercase</strong>, <strong>one digit</strong>, and <strong>one of $, @, #</strong>.</p>
    <label>Confirm password</label>
    <input type="password" id="pw2" name="confirm" minlength="8" required>
    <div class="show-password"><input type="checkbox" onclick="toggleResetPass()" id="showpwreset"><label for="showpwreset">Show password</label></div>
    <div class="actions">
      <button class="btn primary" type="submit">Reset password</button>
      <a class="btn" href="<?= base_path() ?>/login.php">Back to login</a>
    </div>
  </form>
</div>
<script>
function toggleResetPass(){
  const type = document.querySelector('input#pw').type === 'password' ? 'text' : 'password';
  document.querySelector('input#pw').type = type;
  document.querySelector('input#pw2').type = type;
}
</script>
<?php include __DIR__.'/partials/footer.php'; ?>
