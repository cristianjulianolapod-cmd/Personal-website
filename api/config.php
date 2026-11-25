<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'gadgethub';
const DB_USER = 'root';
const DB_PASS = '';

const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
define('SMTP_USERNAME', getenv('GADGETHUB_SMTP_USERNAME') ?: 'cristianjulianolapod@gmail.com');
define('SMTP_PASSWORD', getenv('GADGETHUB_SMTP_PASSWORD') ?: 'sfmvjspxwvgujflb');
const SMTP_ENCRYPTION = 'tls';
const SMTP_FROM_NAME = 'GadgetHub';
const SMTP_TIMEOUT_SECONDS = 15;

const OTP_EXPIRY_MINUTES = 10;
const OTP_MAX_ATTEMPTS = 5;
const OTP_RESEND_INTERVAL_SECONDS = 60;
const OTP_LENGTH = 6;
const PASSWORD_RESET_EXPIRY_MINUTES = 30;
const PASSWORD_RESET_MAX_ATTEMPTS = 5;
const PASSWORD_RESET_RESEND_INTERVAL_SECONDS = 60;

const LOGIN_MAX_FAILED_ATTEMPTS = 3;
const LOGIN_LOCKOUT_SECONDS = 60;
const LOGIN_SECOND_LOCKOUT_SECONDS = 120;

function database(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $connection = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $connection;
}
