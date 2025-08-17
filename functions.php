<?php
require_once __DIR__ . '/config.php';

function is_logged_in(): bool {
  return isset($_SESSION['user']);
}

function current_user_name(): string {
  if (!is_logged_in()) return '';
  if (!empty($_SESSION['user']['username'])) return $_SESSION['user']['username'];
  return $_SESSION['user']['name'] ?? '';
}

function base_path(): string {
  $d = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
  return $d === '' ? '' : $d;
}

function redirect(string $path) {
  if (strlen($path) > 0 && $path[0] === '/') {
    $path = base_path() . $path;
  } else {
    $path = base_path() . '/' . ltrim($path, '/');
  }
  header('Location: ' . $path);
  exit;
}

function require_login() {
  if (!is_logged_in()) {
    redirect('/index.php');
  }
}

// CSRF helpers
function csrf_token() {
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf'];
}

function verify_csrf($token) {
  return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token ?? '');
}
