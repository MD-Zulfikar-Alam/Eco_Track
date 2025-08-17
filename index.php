
<?php $page_title='Home'; include __DIR__.'/partials/header.php'; ?>
<section class="hero">
  <h1>Track your <span style="color:#2E7D32">carbon footprint</span> and grow greener habits.</h1>
  <p style= "color: black">Eco Track helps you measure emissions, learn with quizzes, complete daily eco challenges, plant trees on a map, and watch your progress over time.</p>
  <?php if (!is_logged_in()): ?>
    <div class="actions" style="margin-top:14px">
      <a class="btn primary" href="<?= base_path() ?>/signup.php">Create an account</a>
      <a class="btn" href="<?= base_path() ?>/login.php">Log in</a>
    </div>
  <?php else: ?>
    <div class="actions" style="margin-top:14px">
      <a class="btn primary" href="<?= base_path() ?>/dashboard.php">Go to Dashboard</a>
    </div>
  <?php endif; ?>
</section>

<section class="grid">
  <div class="card span-6">
    <div class="section-title"><h2>Welcome</h2></div>
    <p class="small">
      Home is always public. Other pages are available after you log in.
      <?php if (is_logged_in()): ?>
        You are logged in as <strong><?= htmlspecialchars(current_user_name()); ?></strong>.
      <?php endif; ?>
    </p>
  </div>
  <div class="card span-6">
    <div class="section-title"><h2>Why sign in?</h2></div>
    <p class="small">Signing in lets you save your quiz scores, footprint history, and challenge progress to your account.</p>
  </div>
</section>
<?php include __DIR__.'/partials/footer.php'; ?>
