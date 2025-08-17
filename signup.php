
<?php
$page_title='Sign Up';
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';
include __DIR__.'/partials/header.php';

$errors = [];

function validate_password($p){
  // >=8 chars, at least one upper, one lower, one digit, and one of @ $ #
  return preg_match('/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$#])[A-Za-z\d@$#]{8,}$/', $p);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $name = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $dob_str = trim($_POST['dob'] ?? '');
  $password = $_POST['password'] ?? '';
  $confirm = $_POST['confirm'] ?? '';
  $csrf = $_POST['csrf'] ?? '';

  if (!verify_csrf($csrf)) $errors[] = 'Invalid CSRF token.';
  if ($username === '' || !preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) $errors[] = 'Choose a username (3–30 chars, letters, numbers, underscore).';
  if ($name === '') $errors[] = 'Full name is required.';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';

  // Validate DOB dd-mm-yyyy
  $dob = DateTime::createFromFormat('d-m-Y', $dob_str);
  $validDob = $dob && $dob->format('d-m-Y') === $dob_str;
  if (!$validDob) $errors[] = 'Date of birth must be in dd-mm-yyyy format.';

  if (!validate_password($password)) $errors[] = 'Password must be at least 8 characters and include at least one uppercase letter, one lowercase letter, one digit, and one of $, @, #.';
  if ($password !== $confirm) $errors[] = 'Passwords do not match.';

  // Check uniqueness
  if (!$errors) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    if ($stmt->fetch()) $errors[] = 'Username is already taken.';

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) $errors[] = 'An account with this email already exists.';
  }

  if (!$errors) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (username, name, email, dob, password_hash) VALUES (?,?,?,?,?)');
    $stmt->execute([$username, $name, $email, $dob->format('Y-m-d'), $hash]);
    $_SESSION['user'] = [
      'id' => $pdo->lastInsertId(),
      'username' => $username,
      'name' => $name,
      'email' => $email
    ];
    redirect('/dashboard.php');
  }
}
?>
<div class="card" style="max-width:620px;margin:0 auto">
  <h2>Create your account</h2>

  <?php if (!empty($errors)): ?>
    <div class="card" style="background:#fdecea;border-color:#f5c2c7">
      <p class="small" style="color:#b91c1c"><?= htmlspecialchars(implode(' ', $errors)); ?></p>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()); ?>">

    <label for="username">Username <span class="small">(unique)</span></label>
    <input id="username" name="username" type="text" placeholder="e.g., pijus_saha" required>

    <label for="name">Full name</label>
    <input id="name" name="name" type="text" placeholder="e.g., Pijus Saha" required>

    <label for="email">Email <span class="small">(unique)</span></label>
    <input id="email" name="email" type="email" placeholder="you@example.com" required>

    <label for="dob">Date of birth</label>
    <input id="dob" name="dob" type="text" placeholder="dd-mm-yyyy" required>

    <label for="password">Password</label>
    <input id="password" name="password" type="password" minlength="8" required placeholder="At least 8 chars, 1 upper, 1 lower, 1 digit & one of $, @, #">
    <p class="small">Password must be at least 8 characters and include: <strong>one uppercase</strong>, <strong>one lowercase</strong>, <strong>one digit</strong>, and <strong>one of $, @, #</strong>.</p>

    <label for="confirm">Confirm password</label>
    <input id="confirm" name="confirm" type="password" minlength="8" required>
    <div class="show-password">
      <input id="showpass" type="checkbox" onclick="togglePass()">Show passwords
  </div>
    <div class="actions">
      <button class="btn primary" type="submit">Create Account</button>
      <a class="btn" href="<?= base_path() ?>/login.php">I already have an account</a>
    </div>
  </form>
</div>

<script>
function togglePass(){
  const type = document.getElementById('showpass').checked ? 'text' : 'password';
  document.getElementById('password').type = type;
  document.getElementById('confirm').type = type;
}
</script>
<?php include __DIR__.'/partials/footer.php'; ?>
