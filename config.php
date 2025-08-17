
<?php
// --- Update these for your MySQL server ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'ecotrack');
define('DB_USER', 'root');
define('DB_PASS', '');

// Cookie & session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
session_name('ecotrack_sess');
session_start();

// If you deploy over HTTPS, also set session.cookie_secure=1 in php.ini or here.
?>
