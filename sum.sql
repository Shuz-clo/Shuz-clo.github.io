CREATE USER IF NOT EXISTS 'interview_app'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON interview_rating.* TO 'interview_app'@'localhost';
FLUSH PRIVILEGES;