
<?php
$page_title='Log In';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
include __DIR__.'/partials/header.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $identifier = trim($_POST['identifier'] ?? '');
  $password = $_POST['password'] ?? '';
  $csrf = $_POST['csrf'] ?? '';

  if (!verify_csrf($csrf)) $errors[] = 'Invalid CSRF token.';
  if ($identifier === '') $errors[] = 'Email or username is required.';
  if ($password === '') $errors[] = 'Password is required.';

  if (!$errors) {
    $stmt = $pdo->prepare('SELECT id, username, name, email, password_hash FROM users WHERE email = ? OR username = ? LIMIT 1');
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
      $_SESSION['user'] = ['id'=>$user['id'], 'username'=>$user['username'] ?? null, 'name'=>$user['name'], 'email'=>$user['email']];
      redirect('/dashboard.php');
    } else {
      $errors[] = 'Invalid credentials.';
    }
  }
}
?>
<div class="card" style="max-width:520px;margin:0 auto">
  <h2>Log in</h2>
  <?php if ($errors): ?>
    <div class="card" style="background:#fdecea;border-color:#f5c2c7">
      <p class="small" style="color:#b91c1c"><?= htmlspecialchars(implode(' ', $errors)); ?></p>
    </div>
  <?php endif; ?>
  <form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">
    <label>Email or Username</label>
    <input type="text" name="identifier" required placeholder="you@example.com or yourusername">
    <label>Password</label>
    <input type="password" id="loginpw" name="password" required placeholder="••••••">
    <div class="show-password"><input type="checkbox" onclick="toggleLoginPass()"> <label>Show password</label></div>
    <div class="actions">
      <a class="btn" href="<?= base_path() ?>/forgot_password.php">Forgot password?</a>
      <button class="btn primary" type="submit">Log in</button>
      <a class="btn" href="<?= base_path() ?>/signup.php">Create account</a>
    </div>
  </form>
</div>
<?php include __DIR__.'/partials/footer.php'; ?>


<script>
function toggleLoginPass(){
  const el = document.getElementById('loginpw');
  el.type = el.type === 'password' ? 'text' : 'password';
}
</script>
