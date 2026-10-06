<?php
// Move this file OUTSIDE the web root when you publish, and update the
// require_once path in db.php / login.php accordingly.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');      // production: use a limited MySQL user, not root
define('DB_PASS', '');          // production: set a real password
define('DB_NAME', 'interview_rating');

define('DEBUG', true);          // set to false when published

define('ADMIN_HASH', '$2y$10$Ze88BYCaMEOtrUEgOZmlKeU9GQdB9B482Gqo7GnoK5fib16pBAZam');