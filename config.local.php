<?php
if (PHP_SAPI !== 'cli' && getenv('DB_USER') === false) {
    putenv('DB_USER=interview_app');
    putenv('DB_PASS=POOPOOKOOKOO');
}