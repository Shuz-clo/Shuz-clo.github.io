<?php
// Move this file OUTSIDE the web root when you publish, and update the
// require_once path in db.php / login.php / register.php / logout.php accordingly.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');      // production: use a limited MySQL user, not root
define('DB_PASS', '');          // production: set a real password
define('DB_NAME', 'interview_rating');

date_default_timezone_set('Asia/Manila');

// LOCAL: true shows error details on screen.
// PUBLISHED: you MUST set this to false.
define('DEBUG', true);

// Anyone signing up on register.php must type this code.
// Change it to a long random string and share it only with people you trust.
define('REGISTRATION_CODE', 'poopoo');

define('ADMIN_HASH', '$2y$10$Ze88BYCaMEOtrUEgOZmlKeU9GQdB9B482Gqo7GnoK5fib16pBAZam');
