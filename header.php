
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= isset($page_title) ? htmlspecialchars($page_title) . ' · ' : '' ?>Eco Track</title>
  <?php $base = base_path(); ?>
  <link rel="stylesheet" href="<?= $base ?>/assets/styles.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
  <nav class="navbar">
    <div class="container">
      <div class="brand"><span>🌱</span>Eco Track</div>
      <div class="navlinks">
        <a data-nav href="<?= $base ?>/index.php">Home</a>
        <?php if (is_logged_in()): ?>
          <a data-nav href="<?= $base ?>/dashboard.php">Dashboard</a>
          <a data-nav href="<?= $base ?>/calculator.php">Calculator</a>
          <a data-nav href="<?= $base ?>/quiz.php">Quiz</a>
          <a data-nav href="<?= $base ?>/challenges.php">Challenges</a>
          <a data-nav href="<?= $base ?>/tips.php">Eco Tips</a>
          <a data-nav href="<?= $base ?>/community.php">Community</a>
          <a data-nav href="<?= $base ?>/map.php">Map</a>
          <a data-nav href="<?= $base ?>/blog.php">Blog</a>
        <?php endif; ?>
      </div>
      <div class="navlinks" style="margin-left:auto">
        <?php if (!is_logged_in()): ?>
          <a data-nav href="<?= $base ?>/signup.php">Sign Up</a>
          <a data-nav href="<?= $base ?>/login.php">Log In</a>
        <?php else: ?>
          <span class="user-chip">Hello, <?= htmlspecialchars(current_user_name()); ?></span>
          <a data-nav href="<?= $base ?>/logout.php">Logout</a>
        <?php endif; ?>
      </div>
    </div>
    </nav>
  <main class="container">
