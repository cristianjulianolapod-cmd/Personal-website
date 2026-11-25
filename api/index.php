<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestBody(): array
{
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        respond(400, ['error' => 'Format JSON tidak valid.']);
    }
    return $body;
}

function requireCsrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        respond(403, ['error' => 'Sesi keamanan tidak valid. Muat ulang halaman lalu coba lagi.']);
    }
}

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id' => (int) $_SESSION['user_id'],
        'name' => (string) $_SESSION['user_name'],
        'role' => (string) ($_SESSION['user_role'] ?? 'user'),
    ];
}

function refreshCurrentUser(): ?array
{
    $user = currentUser();
    if ($user === null) {
        return null;
    }
    $statement = database()->prepare('SELECT name, email, role, is_active FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$user['id']]);
    $account = $statement->fetch();
    if (!$account || !(int) $account['is_active']) {
        unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_role']);
        return null;
    }
    $_SESSION['user_name'] = (string) $account['name'];
    $_SESSION['user_role'] = (string) $account['role'];
    return [
        'id' => $user['id'],
        'name' => (string) $account['name'],
        'email' => (string) $account['email'],
        'role' => (string) $account['role'],
    ];
}

function requireUser(): array
{
    $user = refreshCurrentUser();
    if ($user === null) {
        respond(401, ['error' => 'Silakan masuk sebelum melanjutkan.']);
    }
    return $user;
}

function requireAdmin(): array
{
    $user = requireUser();
    if (!in_array($user['role'], ['admin', 'super_admin'], true)) {
        respond(403, ['error' => 'Akses khusus administrator.']);
    }
    return $user;
}

function requireSuperAdmin(): array
{
    $user = requireUser();
    if ($user['role'] !== 'super_admin') {
        respond(403, ['error' => 'Fitur ini hanya dapat digunakan super admin.']);
    }
    return $user;
}

function validateProductPayload(array $body): array
{
    $name = $body['name'] ?? null;
    $category = $body['category'] ?? null;
    $image = $body['image'] ?? null;
    $price = filter_var($body['price'] ?? null, FILTER_VALIDATE_INT);
    $stock = filter_var($body['stock'] ?? null, FILTER_VALIDATE_INT);
    $isActive = filter_var($body['isActive'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

    if (!is_string($name) || strlen(trim($name)) < 2 || strlen($name) > 180
        || !is_string($category) || strlen(trim($category)) < 1 || strlen($category) > 60
        || $price === false || $price < 0 || $price > 4294967295
        || $stock === false || $stock < 0 || $stock > 1000000
        || !is_string($image) || strlen($image) > 500
        || !filter_var($image, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string) parse_url($image, PHP_URL_SCHEME)), ['http', 'https'], true)
        || $isActive === null) {
        respond(422, ['error' => 'Periksa nama, kategori, harga, stok, gambar, dan status produk.']);
    }

    return [
        'name' => trim($name),
        'category' => trim($category),
        'price' => $price,
        'oldPrice' => null,
        'badge' => '',
        'image' => $image,
        'stock' => $stock,
        'isActive' => $isActive ? 1 : 0,
    ];
}

function validLocalDateTime(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
    return $date !== false && $date->format('Y-m-d\TH:i') === $value;
}

function sendGadgetHubEmail(string $email, string $name, string $subject, string $htmlBody, string $textBody): void
{
    if (SMTP_USERNAME === '' || SMTP_PASSWORD === '') {
        throw new RuntimeException('GadgetHub SMTP credentials are not configured.');
    }
    if (!in_array(SMTP_ENCRYPTION, ['tls', 'ssl'], true)) {
        throw new RuntimeException('GadgetHub SMTP encryption must be tls or ssl.');
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PHPMailer dependencies are not installed.');
    }
    require_once $autoload;

    $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = SMTP_HOST;
    $mailer->SMTPAuth = true;
    $mailer->Username = SMTP_USERNAME;
    $mailer->Password = SMTP_PASSWORD;
    $mailer->SMTPSecure = SMTP_ENCRYPTION === 'ssl'
        ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Port = SMTP_PORT;
    $mailer->Timeout = SMTP_TIMEOUT_SECONDS;
    $mailer->CharSet = 'UTF-8';
    $mailer->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
    $mailer->addAddress($email, $name);
    $mailer->isHTML(true);
    $mailer->Subject = $subject;
    $mailer->Body = $htmlBody;
    $mailer->AltBody = $textBody;
    $mailer->send();
}

function sendRegistrationOtp(string $email, string $name, string $code): void
{
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $htmlBody = '<p>Halo ' . $safeName . ',</p><p>Kode verifikasi akun GadgetHub kamu:</p>'
        . '<p style="font-size:28px;font-weight:bold;letter-spacing:8px">' . $code . '</p>'
        . '<p>Kode berlaku selama ' . OTP_EXPIRY_MINUTES . ' menit. Jika kamu tidak meminta kode ini, abaikan email ini.</p>';
    $textBody = "Halo {$name}, kode verifikasi akun GadgetHub kamu: {$code}. Kode berlaku selama "
        . OTP_EXPIRY_MINUTES . ' menit.';
    sendGadgetHubEmail($email, $name, 'Kode verifikasi akun GadgetHub', $htmlBody, $textBody);
}

function sendLoginOtp(string $email, string $name, string $code): void
{
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $htmlBody = '<p>Halo ' . $safeName . ',</p><p>Kode OTP untuk masuk ke akun GadgetHub kamu:</p>'
        . '<p style="font-size:28px;font-weight:bold;letter-spacing:8px">' . $code . '</p>'
        . '<p>Kode berlaku selama ' . OTP_EXPIRY_MINUTES . ' menit. Jika kamu tidak mencoba masuk, segera ganti kata sandi akunmu.</p>';
    $textBody = "Halo {$name}, kode OTP untuk masuk ke akun GadgetHub kamu: {$code}. Kode berlaku selama "
        . OTP_EXPIRY_MINUTES . ' menit. Jika kamu tidak mencoba masuk, segera ganti kata sandi akunmu.';
    sendGadgetHubEmail($email, $name, 'Kode OTP login GadgetHub', $htmlBody, $textBody);
}

function sendPasswordResetEmail(string $email, string $name, string $code): void
{
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $htmlBody = '<p>Halo ' . $safeName . ',</p><p>Kami menerima permintaan untuk mengatur ulang kata sandi akun GadgetHub kamu.</p>'
        . '<p>Kode konfirmasi: <strong style="font-size:24px;letter-spacing:6px">' . $code . '</strong></p>'
        . '<p>Masukkan kode ini di website untuk mengonfirmasi bahwa akun tersebut milikmu, lalu buat kata sandi baru langsung di website. '
        . 'Kode berlaku selama ' . PASSWORD_RESET_EXPIRY_MINUTES . ' menit dan hanya dapat digunakan sekali. '
        . 'Jika kamu tidak meminta pengaturan ulang, abaikan email ini.</p>';
    $textBody = "Halo {$name}, kode konfirmasi pengaturan ulang kata sandi GadgetHub kamu adalah {$code}. "
        . 'Masukkan kode ini di website untuk mengonfirmasi bahwa akun tersebut milikmu dan membuat kata sandi baru di website. '
        . 'Kode berlaku selama ' . PASSWORD_RESET_EXPIRY_MINUTES . ' menit dan hanya dapat digunakan sekali.';
    sendGadgetHubEmail($email, $name, 'Kode konfirmasi pengaturan ulang kata sandi GadgetHub', $htmlBody, $textBody);
}

function generateRegistrationOtp(): string
{
    if (OTP_LENGTH < 6 || OTP_LENGTH > 10) {
        throw new RuntimeException('OTP_LENGTH must be between 6 and 10.');
    }
    $minimum = 10 ** (OTP_LENGTH - 1);
    return (string) random_int($minimum, ($minimum * 10) - 1);
}

function createSession(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['name'];
    $_SESSION['user_role'] = (string) $user['role'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$route = (string) ($_GET['route'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($route === 'products' && $method === 'GET') {
        $statement = database()->query(
            'SELECT id, name, category, price, rating, reviews, image_url AS image, stock
             FROM products WHERE is_active = 1 ORDER BY id'
        );
        respond(200, ['products' => $statement->fetchAll()]);
    }

    if ($route === 'session' && $method === 'GET') {
        $user = refreshCurrentUser();
        respond(200, [
            'user' => $user,
            'csrfToken' => (string) $_SESSION['csrf_token'],
        ]);
    }

    if ($route === 'register' && $method === 'POST') {
        requireCsrf();
        $body = requestBody();
        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if (strlen($name) < 2 || strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 190 || strlen($password) < 8 || strlen($password) > 4096) {
            respond(422, ['error' => 'Periksa nama, email, dan kata sandi (minimal 8 karakter).']);
        }

        $pdo = database();
        $existingUser = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $existingUser->execute([$email]);
        if ($existingUser->fetchColumn()) {
            respond(409, ['error' => 'Email ini sudah terdaftar. Silakan masuk.']);
        }

        $existingPending = $pdo->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS seconds_since_send
             FROM pending_registrations WHERE email = ? LIMIT 1'
        );
        $existingPending->execute([$email]);
        $pending = $existingPending->fetch();
        if ($pending && (int) $pending['seconds_since_send'] < OTP_RESEND_INTERVAL_SECONDS) {
            respond(202, [
                'otpRequired' => true,
                'email' => $email,
                'codeLength' => OTP_LENGTH,
                'expiresInMinutes' => OTP_EXPIRY_MINUTES,
                'resendAfterSeconds' => OTP_RESEND_INTERVAL_SECONDS - (int) $pending['seconds_since_send'],
                'message' => 'Kode verifikasi sebelumnya masih berlaku. Periksa email kamu atau kirim ulang setelah jeda berakhir.',
            ]);
        }

        $otp = generateRegistrationOtp();
        try {
            sendRegistrationOtp($email, $name, $otp);
        } catch (RuntimeException $error) {
            error_log('[GadgetHub OTP] ' . $error->getMessage());
            respond(503, ['error' => 'Email OTP gagal dikirim. Periksa konfigurasi Gmail SMTP lalu coba lagi.']);
        } catch (\PHPMailer\PHPMailer\Exception $error) {
            error_log('[GadgetHub OTP] ' . $error->getMessage());
            respond(503, ['error' => 'Email OTP gagal dikirim. Periksa konfigurasi Gmail SMTP lalu coba lagi.']);
        }

        $statement = $pdo->prepare(
            'INSERT INTO pending_registrations (email, name, password_hash, otp_hash, expires_at, attempts, last_sent_at)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . OTP_EXPIRY_MINUTES . ' MINUTE), 0, NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash),
               otp_hash = VALUES(otp_hash), expires_at = DATE_ADD(NOW(), INTERVAL ' . OTP_EXPIRY_MINUTES . ' MINUTE),
               attempts = 0, last_sent_at = NOW()'
        );
        try {
            $statement->execute([$email, $name, password_hash($password, PASSWORD_DEFAULT), password_hash($otp, PASSWORD_DEFAULT)]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                respond(409, ['error' => 'Email ini sudah terdaftar. Silakan masuk.']);
            }
            throw $error;
        }

        respond(202, [
            'otpRequired' => true,
            'email' => $email,
            'codeLength' => OTP_LENGTH,
            'expiresInMinutes' => OTP_EXPIRY_MINUTES,
            'message' => 'Kode verifikasi telah dikirim ke email kamu.',
        ]);
    }

    if ($route === 'verify-registration' && $method === 'POST') {
        requireCsrf();
        $body = requestBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $otp = trim((string) ($body['otp'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{' . OTP_LENGTH . '}$/D', $otp)) {
            respond(422, ['error' => 'Masukkan email dan kode OTP ' . OTP_LENGTH . ' digit yang valid.']);
        }

        $pdo = database();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'SELECT name, password_hash, otp_hash, expires_at, expires_at <= NOW() AS is_expired, attempts
                 FROM pending_registrations WHERE email = ? FOR UPDATE'
            );
            $statement->execute([$email]);
            $pending = $statement->fetch();
            if (!$pending) {
                $pdo->rollBack();
                respond(404, ['error' => 'Pendaftaran tidak ditemukan. Silakan daftar kembali.']);
            }
            if ((int) $pending['is_expired'] === 1) {
                $delete = $pdo->prepare('DELETE FROM pending_registrations WHERE email = ?');
                $delete->execute([$email]);
                $pdo->commit();
                respond(410, ['error' => 'Kode verifikasi sudah kedaluwarsa. Silakan daftar kembali.']);
            }
            if ((int) $pending['attempts'] >= OTP_MAX_ATTEMPTS) {
                $pdo->rollBack();
                respond(429, ['error' => 'Terlalu banyak percobaan. Minta kode baru dengan mendaftar kembali.']);
            }
            if (!password_verify($otp, (string) $pending['otp_hash'])) {
                $updateAttempts = $pdo->prepare('UPDATE pending_registrations SET attempts = attempts + 1 WHERE email = ?');
                $updateAttempts->execute([$email]);
                $pdo->commit();
                respond(422, ['error' => 'Kode verifikasi salah. Periksa email dan coba lagi.']);
            }

            $createUser = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            $createUser->execute([$pending['name'], $email, $pending['password_hash']]);
            $userId = (int) $pdo->lastInsertId();
            $delete = $pdo->prepare('DELETE FROM pending_registrations WHERE email = ?');
            $delete->execute([$email]);
            $user = ['id' => $userId, 'name' => (string) $pending['name'], 'email' => $email, 'role' => 'user'];
            $pdo->commit();
        } catch (PDOException $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error->getCode() === '23000') {
                respond(409, ['error' => 'Email ini sudah terdaftar. Silakan masuk.']);
            }
            throw $error;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        createSession($user);
        respond(201, ['user' => $user, 'csrfToken' => $_SESSION['csrf_token']]);
    }

    if ($route === 'resend-registration-otp' && $method === 'POST') {
        requireCsrf();
        $body = requestBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            respond(422, ['error' => 'Email tidak valid.']);
        }

        $pdo = database();
        $findPending = $pdo->prepare(
            'SELECT name, TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS seconds_since_send
             FROM pending_registrations WHERE email = ? LIMIT 1'
        );
        $findPending->execute([$email]);
        $pending = $findPending->fetch();
        if (!$pending) {
            respond(404, ['error' => 'Pendaftaran tidak ditemukan. Silakan daftar kembali.']);
        }
        if ((int) $pending['seconds_since_send'] < OTP_RESEND_INTERVAL_SECONDS) {
            respond(429, ['error' => 'Tunggu ' . OTP_RESEND_INTERVAL_SECONDS . ' detik sebelum meminta kode baru.']);
        }

        $otp = generateRegistrationOtp();
        try {
            sendRegistrationOtp($email, (string) $pending['name'], $otp);
        } catch (RuntimeException $error) {
            error_log('[GadgetHub OTP] ' . $error->getMessage());
            respond(503, ['error' => 'Email OTP gagal dikirim. Periksa konfigurasi Gmail SMTP lalu coba lagi.']);
        } catch (\PHPMailer\PHPMailer\Exception $error) {
            error_log('[GadgetHub OTP] ' . $error->getMessage());
            respond(503, ['error' => 'Email OTP gagal dikirim. Periksa konfigurasi Gmail SMTP lalu coba lagi.']);
        }

        $update = $pdo->prepare(
            'UPDATE pending_registrations SET otp_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL ' . OTP_EXPIRY_MINUTES . ' MINUTE),
             attempts = 0, last_sent_at = NOW() WHERE email = ?'
        );
        $update->execute([password_hash($otp, PASSWORD_DEFAULT), $email]);
        respond(200, ['message' => 'Kode verifikasi baru telah dikirim.']);
    }

    if ($route === 'password-reset-request' && $method === 'POST') {
        requireCsrf();
        $body = requestBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $genericMessage = 'Jika email tersebut terdaftar, kode konfirmasi akan dikirim. Masukkan kode dan kata sandi baru di website.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            respond(422, ['error' => 'Masukkan alamat email yang valid.']);
        }

        $pdo = database();
        $pdo->beginTransaction();
        try {
            $findUser = $pdo->prepare(
                'SELECT id, name, email FROM users
                 WHERE email = ? AND is_active = 1 LIMIT 1 FOR UPDATE'
            );
            $findUser->execute([$email]);
            $user = $findUser->fetch();
            if ($user) {
                $findExisting = $pdo->prepare(
                    'SELECT TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS seconds_since_send
                     FROM password_reset_tokens WHERE user_id = ? FOR UPDATE'
                );
                $findExisting->execute([$user['id']]);
                $existingToken = $findExisting->fetch();
                if (!$existingToken
                    || (int) $existingToken['seconds_since_send'] >= PASSWORD_RESET_RESEND_INTERVAL_SECONDS) {
                    $code = generateRegistrationOtp();
                    try {
                        sendPasswordResetEmail((string) $user['email'], (string) $user['name'], $code);
                    } catch (RuntimeException $error) {
                        $pdo->rollBack();
                        error_log('[GadgetHub password reset email] ' . $error->getMessage());
                        respond(202, ['message' => $genericMessage]);
                    } catch (\PHPMailer\PHPMailer\Exception $error) {
                        $pdo->rollBack();
                        error_log('[GadgetHub password reset email] ' . $error->getMessage());
                        respond(202, ['message' => $genericMessage]);
                    }

                    $saveToken = $pdo->prepare(
                        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, last_sent_at)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ' . PASSWORD_RESET_EXPIRY_MINUTES . ' MINUTE), NOW())
                         ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash),
                           expires_at = DATE_ADD(NOW(), INTERVAL ' . PASSWORD_RESET_EXPIRY_MINUTES . ' MINUTE),
                           attempts = 0, last_sent_at = NOW()'
                    );
                    $saveToken->execute([(int) $user['id'], password_hash($code, PASSWORD_DEFAULT)]);
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        respond(202, ['message' => $genericMessage]);
    }

    if ($route === 'password-reset' && $method === 'POST') {
        requireCsrf();
        $body = requestBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $code = trim((string) ($body['code'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190
            || !preg_match('/^\d{' . OTP_LENGTH . '}$/D', $code)
            || strlen($password) < 8 || strlen($password) > 4096) {
            respond(422, ['error' => 'Masukkan email, kode konfirmasi yang valid, dan kata sandi minimal 8 karakter.']);
        }

        $pdo = database();
        $pdo->beginTransaction();
        try {
            $findToken = $pdo->prepare(
                'SELECT reset.user_id, reset.token_hash, reset.attempts,
                        reset.expires_at <= NOW() AS is_expired
                 FROM password_reset_tokens reset
                 JOIN users ON users.id = reset.user_id
                 WHERE users.email = ? AND users.is_active = 1 FOR UPDATE'
            );
            $findToken->execute([$email]);
            $reset = $findToken->fetch();
            if (!$reset) {
                $pdo->rollBack();
                respond(400, [
                    'error' => 'Kode konfirmasi tidak valid atau sudah kedaluwarsa. Minta kode baru.',
                ]);
            }
            if ((int) $reset['is_expired'] === 1) {
                $pdo->rollBack();
                respond(400, ['error' => 'Kode konfirmasi sudah kedaluwarsa. Minta kode baru.']);
            }
            if ((int) $reset['attempts'] >= PASSWORD_RESET_MAX_ATTEMPTS) {
                $pdo->rollBack();
                respond(429, ['error' => 'Terlalu banyak percobaan kode. Tunggu sebelum meminta kode baru.']);
            }
            if (!password_verify($code, (string) $reset['token_hash'])) {
                $incrementAttempts = $pdo->prepare(
                    'UPDATE password_reset_tokens SET attempts = attempts + 1 WHERE user_id = ?'
                );
                $incrementAttempts->execute([(int) $reset['user_id']]);
                $pdo->commit();
                respond(422, ['error' => 'Kode konfirmasi salah. Periksa email dan coba lagi.']);
            }

            $updatePassword = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND is_active = 1');
            $updatePassword->execute([password_hash($password, PASSWORD_DEFAULT), (int) $reset['user_id']]);
            if ($updatePassword->rowCount() !== 1) {
                $pdo->rollBack();
                respond(400, ['error' => 'Akun tidak dapat diperbarui. Minta kode konfirmasi baru.']);
            }
            $deleteToken = $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
            $deleteToken->execute([(int) $reset['user_id']]);
            $deleteLoginChallenge = $pdo->prepare('DELETE FROM login_otp_challenges WHERE user_id = ?');
            $deleteLoginChallenge->execute([(int) $reset['user_id']]);
            $clearAttempts = $pdo->prepare(
                'DELETE FROM login_attempts WHERE email = (SELECT email FROM users WHERE id = ?)'
            );
            $clearAttempts->execute([(int) $reset['user_id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        respond(200, ['message' => 'Kata sandi berhasil diperbarui. Silakan masuk dengan kata sandi baru.']);
    }

    if ($route === 'change-password' && $method === 'POST') {
        requireCsrf();
        $user = requireUser();
        $body = requestBody();
        $currentPassword = (string) ($body['currentPassword'] ?? '');
        $newPassword = (string) ($body['newPassword'] ?? '');
        if (strlen($currentPassword) > 4096 || strlen($newPassword) < 8 || strlen($newPassword) > 4096) {
            respond(422, ['error' => 'Kata sandi baru harus memiliki minimal 8 karakter.']);
        }

        $pdo = database();
        $pdo->beginTransaction();
        try {
            $findUser = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND is_active = 1 FOR UPDATE');
            $findUser->execute([$user['id']]);
            $account = $findUser->fetch();
            if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
                $pdo->rollBack();
                respond(422, ['error' => 'Kata sandi saat ini tidak sesuai.']);
            }
            $updatePassword = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $updatePassword->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
            $deleteResetToken = $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?');
            $deleteResetToken->execute([$user['id']]);
            $deleteLoginChallenge = $pdo->prepare('DELETE FROM login_otp_challenges WHERE user_id = ?');
            $deleteLoginChallenge->execute([$user['id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        respond(200, ['message' => 'Kata sandi berhasil diganti.']);
    }

    if ($route === 'login' && $method === 'POST') {
        requireCsrf();
        $body = requestBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');
        $expectedRole = $body['expectedRole'] ?? null;
        if ($expectedRole !== null && !in_array($expectedRole, ['admin', 'super_admin'], true)) {
            respond(422, ['error' => 'Jenis akun administrator tidak valid.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            respond(401, ['error' => 'Email atau kata sandi yang kamu masukkan salah.']);
        }

        $pdo = database();
        $pdo->beginTransaction();
        try {
            $insertAttempt = $pdo->prepare(
                'INSERT IGNORE INTO login_attempts (email, failed_attempts, lock_until, lockout_level) VALUES (?, 0, NULL, 0)'
            );
            $insertAttempt->execute([$email]);
            $attemptQuery = $pdo->prepare(
                'SELECT failed_attempts, lock_until, lockout_level, lock_until > NOW() AS is_locked,
                        GREATEST(1, TIMESTAMPDIFF(SECOND, NOW(), lock_until)) AS retry_after_seconds
                 FROM login_attempts WHERE email = ? FOR UPDATE'
            );
            $attemptQuery->execute([$email]);
            $attempt = $attemptQuery->fetch();

            if ((int) $attempt['is_locked'] === 1) {
                $pdo->rollBack();
                $retryAfter = (int) $attempt['retry_after_seconds'];
                respond(429, [
                    'error' => 'Terlalu banyak percobaan login.',
                    'retryAfterSeconds' => $retryAfter,
                ]);
            }
            if ($attempt['lock_until'] !== null) {
                $resetAttempt = $pdo->prepare(
                    'UPDATE login_attempts SET failed_attempts = 0, lock_until = NULL WHERE email = ?'
                );
                $resetAttempt->execute([$email]);
                $attempt['failed_attempts'] = 0;
            }

            $statement = $pdo->prepare('SELECT id, name, role, is_active, password_hash FROM users WHERE email = ? LIMIT 1');
            $statement->execute([$email]);
            $user = $statement->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $failedAttempts = (int) $attempt['failed_attempts'] + 1;
                $isLocked = $failedAttempts >= LOGIN_MAX_FAILED_ATTEMPTS;
                $lockoutLevel = min(2, (int) $attempt['lockout_level'] + 1);
                $lockoutSeconds = $lockoutLevel === 1
                    ? LOGIN_LOCKOUT_SECONDS
                    : LOGIN_SECOND_LOCKOUT_SECONDS;
                $updateAttempt = $pdo->prepare(
                    'UPDATE login_attempts SET failed_attempts = ?, lock_until = '
                    . ($isLocked ? 'DATE_ADD(NOW(), INTERVAL ' . $lockoutSeconds . ' SECOND)' : 'NULL')
                    . ', lockout_level = ?'
                    . ' WHERE email = ?'
                );
                $updateAttempt->execute([
                    $failedAttempts,
                    $isLocked ? $lockoutLevel : (int) $attempt['lockout_level'],
                    $email,
                ]);
                $pdo->commit();
                if ($isLocked) {
                    respond(429, [
                        'error' => 'Terlalu banyak percobaan login. Coba lagi dalam ' . $lockoutSeconds . ' detik.',
                        'retryAfterSeconds' => $lockoutSeconds,
                    ]);
                }
                respond(401, [
                    'error' => 'Email atau kata sandi yang kamu masukkan salah.',
                    'remainingAttempts' => LOGIN_MAX_FAILED_ATTEMPTS - $failedAttempts,
                ]);
            }

            if (!(int) $user['is_active']) {
                $clearAttempts = $pdo->prepare('DELETE FROM login_attempts WHERE email = ?');
                $clearAttempts->execute([$email]);
                $pdo->commit();
                respond(403, ['error' => 'Akun ini sedang dinonaktifkan. Hubungi administrator.']);
            }
            if ($expectedRole !== null && $user['role'] !== $expectedRole) {
                $pdo->rollBack();
                respond(403, [
                    'error' => $expectedRole === 'super_admin'
                        ? 'Akun ini bukan super admin. Gunakan halaman login yang sesuai dengan peran akun.'
                        : 'Akun ini bukan admin. Gunakan halaman login yang sesuai dengan peran akun.',
                ]);
            }

            $findChallenge = $pdo->prepare(
                'SELECT TIMESTAMPDIFF(SECOND, last_sent_at, NOW()) AS seconds_since_send
                 FROM login_otp_challenges WHERE email = ? AND user_id = ? FOR UPDATE'
            );
            $findChallenge->execute([$email, (int) $user['id']]);
            $challenge = $findChallenge->fetch();
            if ($challenge && (int) $challenge['seconds_since_send'] < OTP_RESEND_INTERVAL_SECONDS) {
                $pdo->rollBack();
                respond(202, [
                    'otpRequired' => true,
                    'email' => $email,
                    'codeLength' => OTP_LENGTH,
                    'expiresInMinutes' => OTP_EXPIRY_MINUTES,
                    'resendAfterSeconds' => OTP_RESEND_INTERVAL_SECONDS - (int) $challenge['seconds_since_send'],
                    'message' => 'Kode OTP sudah dikirim ke email kamu.',
                ]);
            }

            $otp = generateRegistrationOtp();
            try {
                sendLoginOtp($email, (string) $user['name'], $otp);
            } catch (RuntimeException $error) {
                $pdo->rollBack();
                error_log('[GadgetHub login OTP] ' . $error->getMessage());
                respond(503, ['error' => 'Email OTP gagal dikirim. Periksa konfigurasi Gmail SMTP lalu coba lagi.']);
            } catch (\PHPMailer\PHPMailer\Exception $error) {
                $pdo->rollBack();
                error_log('[GadgetHub login OTP] ' . $error->getMessage());
                respond(503, ['error' => 'Email OTP gagal dikirim. Periksa konfigurasi Gmail SMTP lalu coba lagi.']);
            }

            $saveChallenge = $pdo->prepare(
                'INSERT INTO login_otp_challenges (email, user_id, otp_hash, expires_at, attempts, last_sent_at)
                 VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . OTP_EXPIRY_MINUTES . ' MINUTE), 0, NOW())
                 ON DUPLICATE KEY UPDATE email = VALUES(email), user_id = VALUES(user_id), otp_hash = VALUES(otp_hash),
                   expires_at = DATE_ADD(NOW(), INTERVAL ' . OTP_EXPIRY_MINUTES . ' MINUTE),
                   attempts = 0, last_sent_at = NOW()'
            );
            $saveChallenge->execute([$email, (int) $user['id'], password_hash($otp, PASSWORD_DEFAULT)]);
            $clearAttempts = $pdo->prepare('DELETE FROM login_attempts WHERE email = ?');
            $clearAttempts->execute([$email]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        respond(202, [
            'otpRequired' => true,
            'email' => $email,
            'codeLength' => OTP_LENGTH,
            'expiresInMinutes' => OTP_EXPIRY_MINUTES,
            'resendAfterSeconds' => OTP_RESEND_INTERVAL_SECONDS,
            'message' => 'Kode OTP telah dikirim ke email kamu.',
        ]);
    }

    if ($route === 'verify-login-otp' && $method === 'POST') {
        requireCsrf();
        $body = requestBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $otp = trim((string) ($body['otp'] ?? ''));
        $expectedRole = $body['expectedRole'] ?? null;
        if ($expectedRole !== null && !in_array($expectedRole, ['admin', 'super_admin'], true)) {
            respond(422, ['error' => 'Jenis akun administrator tidak valid.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190
            || !preg_match('/^\d{' . OTP_LENGTH . '}$/D', $otp)) {
            respond(422, ['error' => 'Masukkan email dan kode OTP ' . OTP_LENGTH . ' digit yang valid.']);
        }

        $pdo = database();
        $pdo->beginTransaction();
        try {
            $findChallenge = $pdo->prepare(
                'SELECT challenge.user_id, challenge.otp_hash, challenge.attempts,
                        challenge.expires_at <= NOW() AS is_expired,
                        users.name, users.email, users.role
                 FROM login_otp_challenges challenge
                 JOIN users ON users.id = challenge.user_id AND users.is_active = 1
                    AND users.email = challenge.email
                 WHERE challenge.email = ? FOR UPDATE'
            );
            $findChallenge->execute([$email]);
            $challenge = $findChallenge->fetch();
            if (!$challenge) {
                $pdo->rollBack();
                respond(404, ['error' => 'Permintaan login tidak ditemukan. Silakan masuk kembali.']);
            }
            if ($expectedRole !== null && $challenge['role'] !== $expectedRole) {
                $pdo->rollBack();
                respond(403, [
                    'error' => $expectedRole === 'super_admin'
                        ? 'Akun ini bukan super admin. Login dibatalkan.'
                        : 'Akun ini bukan admin. Login dibatalkan.',
                ]);
            }
            if ((int) $challenge['is_expired'] === 1) {
                $deleteChallenge = $pdo->prepare('DELETE FROM login_otp_challenges WHERE email = ?');
                $deleteChallenge->execute([$email]);
                $pdo->commit();
                respond(410, ['error' => 'Kode OTP sudah kedaluwarsa. Silakan masuk kembali.']);
            }
            if ((int) $challenge['attempts'] >= OTP_MAX_ATTEMPTS) {
                $pdo->rollBack();
                respond(429, ['error' => 'Terlalu banyak percobaan kode. Kirim ulang OTP setelah jeda pengiriman.']);
            }
            if (!password_verify($otp, (string) $challenge['otp_hash'])) {
                $updateAttempts = $pdo->prepare(
                    'UPDATE login_otp_challenges SET attempts = attempts + 1 WHERE email = ?'
                );
                $updateAttempts->execute([$email]);
                $pdo->commit();
                respond(422, ['error' => 'Kode OTP salah. Periksa email dan coba lagi.']);
            }

            $deleteChallenge = $pdo->prepare('DELETE FROM login_otp_challenges WHERE email = ?');
            $deleteChallenge->execute([$email]);
            $publicUser = [
                'id' => (int) $challenge['user_id'],
                'name' => (string) $challenge['name'],
                'email' => (string) $challenge['email'],
                'role' => (string) $challenge['role'],
            ];
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        createSession($publicUser);
        respond(200, ['user' => $publicUser, 'csrfToken' => $_SESSION['csrf_token']]);
    }

    if ($route === 'resend-login-otp' && $method === 'POST') {
        requireCsrf();
        $body = requestBody();
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            respond(422, ['error' => 'Email tidak valid.']);
        }

        $pdo = database();
        $pdo->beginTransaction();
        try {
            $findChallenge = $pdo->prepare(
                'SELECT challenge.user_id, users.name,
                        TIMESTAMPDIFF(SECOND, challenge.last_sent_at, NOW()) AS seconds_since_send
                 FROM login_otp_challenges challenge
                 JOIN users ON users.id = challenge.user_id AND users.is_active = 1
                    AND users.email = challenge.email
                 WHERE challenge.email = ? FOR UPDATE'
            );
            $findChallenge->execute([$email]);
            $challenge = $findChallenge->fetch();
            if (!$challenge) {
                $pdo->rollBack();
                respond(404, ['error' => 'Permintaan login tidak ditemukan. Silakan masuk kembali.']);
            }
            $waitSeconds = OTP_RESEND_INTERVAL_SECONDS - (int) $challenge['seconds_since_send'];
            if ($waitSeconds > 0) {
                $pdo->rollBack();
                respond(429, [
                    'error' => 'Tunggu sebelum meminta kode baru.',
                    'retryAfterSeconds' => $waitSeconds,
                ]);
            }

            $otp = generateRegistrationOtp();
            try {
                sendLoginOtp($email, (string) $challenge['name'], $otp);
            } catch (RuntimeException $error) {
                $pdo->rollBack();
                error_log('[GadgetHub login OTP] ' . $error->getMessage());
                respond(503, ['error' => 'Email OTP gagal dikirim. Periksa konfigurasi Gmail SMTP lalu coba lagi.']);
            } catch (\PHPMailer\PHPMailer\Exception $error) {
                $pdo->rollBack();
                error_log('[GadgetHub login OTP] ' . $error->getMessage());
                respond(503, ['error' => 'Email OTP gagal dikirim. Periksa konfigurasi Gmail SMTP lalu coba lagi.']);
            }

            $updateChallenge = $pdo->prepare(
                'UPDATE login_otp_challenges
                 SET otp_hash = ?, expires_at = DATE_ADD(NOW(), INTERVAL ' . OTP_EXPIRY_MINUTES . ' MINUTE),
                     attempts = 0, last_sent_at = NOW()
                 WHERE email = ?'
            );
            $updateChallenge->execute([password_hash($otp, PASSWORD_DEFAULT), $email]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        respond(200, [
            'message' => 'Kode OTP baru telah dikirim.',
            'resendAfterSeconds' => OTP_RESEND_INTERVAL_SECONDS,
        ]);
    }

    if ($route === 'logout' && $method === 'POST') {
        requireCsrf();
        unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_role']);
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(200, ['message' => 'Kamu sudah keluar dari akun.', 'csrfToken' => $_SESSION['csrf_token']]);
    }

    if ($route === 'admin-dashboard' && $method === 'GET') {
        $user = requireAdmin();
        $pdo = database();
        $stats = $pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM users) AS userCount,
                (SELECT COUNT(*) FROM users WHERE role IN ('admin', 'super_admin')) AS adminCount,
                (SELECT COUNT(*) FROM users WHERE is_active = 0) AS inactiveUserCount,
                (SELECT COUNT(*) FROM products WHERE is_active = 1) AS productCount,
                (SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'paid', 'processing', 'shipped')) AS openOrderCount,
                (SELECT COUNT(*) FROM orders) AS orderCount,
                (SELECT COUNT(*) FROM orders WHERE payment_status = 'pending') AS pendingPaymentCount,
                (SELECT COUNT(*) FROM orders WHERE payment_status = 'paid') AS transactionCount,
                (SELECT COUNT(*) FROM orders WHERE payment_status IN ('failed', 'refunded')) AS failedPaymentCount,
                (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE payment_status = 'paid') AS revenue"
        )->fetch();
        $orders = $pdo->query(
            'SELECT o.id, o.user_id AS userId,
                    COALESCE(NULLIF(o.recipient_name, \'\'), u.name) AS customer,
                    COALESCE(NULLIF(o.recipient_email, \'\'), u.email) AS email,
                    COALESCE(o.recipient_phone, \'\') AS phone, o.total_amount AS total,
                    o.shipping_address AS shippingAddress, o.status, o.created_at AS createdAt,
                    o.payment_method AS paymentMethod,
                    o.payment_status AS paymentStatus,
                    o.payment_status_updated_at AS paymentStatusUpdatedAt,
                    payment_admin.name AS paymentStatusUpdatedBy,
                    COALESCE((
                        SELECT GROUP_CONCAT(CONCAT(oi.product_name, \' × \', oi.quantity) ORDER BY oi.id SEPARATOR \', \')
                        FROM order_items oi WHERE oi.order_id = o.id
                    ), \'\') AS itemsSummary
             FROM orders o
             JOIN users u ON u.id = o.user_id
             LEFT JOIN users payment_admin ON payment_admin.id = o.payment_status_updated_by
             ORDER BY o.created_at DESC LIMIT 200'
        )->fetchAll();
        $transactionActivities = [];
        $management = null;
        $reports = null;
        if ($user['role'] === 'super_admin') {
            $transactionActivities = $pdo->query(
                'SELECT activity.id, activity.order_id AS orderId,
                        activity.activity_type AS activityType,
                        activity.previous_status AS previousStatus,
                        activity.new_status AS newStatus,
                        activity.created_at AS createdAt,
                        COALESCE(NULLIF(o.recipient_name, \'\'), customer.name) AS customer,
                        COALESCE(NULLIF(o.recipient_email, \'\'), customer.email) AS email,
                        o.total_amount AS total,
                        o.payment_method AS paymentMethod,
                        actor.name AS actorName
                 FROM transaction_activity activity
                 JOIN orders o ON o.id = activity.order_id
                 JOIN users customer ON customer.id = o.user_id
                 LEFT JOIN users actor ON actor.id = activity.actor_user_id
                 ORDER BY activity.created_at DESC, activity.id DESC
                 LIMIT 200'
            )->fetchAll();
            $management = [
                'coupons' => $pdo->query(
                    'SELECT id, code, discount_type AS discountType, discount_value AS discountValue,
                            starts_at AS startsAt, ends_at AS endsAt, usage_limit AS usageLimit,
                            is_active AS isActive
                     FROM coupons ORDER BY created_at DESC LIMIT 100'
                )->fetchAll(),
                'flashSales' => $pdo->query(
                    'SELECT id, title, product_id AS productId, sale_price AS salePrice,
                            starts_at AS startsAt, ends_at AS endsAt, is_active AS isActive
                     FROM flash_sales ORDER BY starts_at DESC LIMIT 100'
                )->fetchAll(),
                'campaigns' => $pdo->query(
                    'SELECT id, channel, subject, body, status, scheduled_at AS scheduledAt
                     FROM notification_campaigns ORDER BY created_at DESC LIMIT 100'
                )->fetchAll(),
                'reviews' => $pdo->query(
                    'SELECT id, customer_name AS customerName, rating, review_text AS reviewText,
                            status, created_at AS createdAt
                     FROM product_reviews ORDER BY created_at DESC LIMIT 100'
                )->fetchAll(),
                'complaints' => $pdo->query(
                    'SELECT id, customer_name AS customerName, customer_email AS customerEmail,
                            subject, message, status, created_at AS createdAt
                     FROM customer_complaints ORDER BY created_at DESC LIMIT 100'
                )->fetchAll(),
                'abuseReports' => $pdo->query(
                    'SELECT id, reporter_email AS reporterEmail, target_type AS targetType,
                            target_reference AS targetReference, reason, status, created_at AS createdAt
                     FROM abuse_reports ORDER BY created_at DESC LIMIT 100'
                )->fetchAll(),
            ];
            $reports = [
                'monthlySales' => $pdo->query(
                    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS period,
                            COUNT(*) AS orderCount, COALESCE(SUM(total_amount), 0) AS revenue
                     FROM orders
                     WHERE payment_status = 'paid'
                       AND created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
                     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                     ORDER BY period"
                )->fetchAll(),
                'topProducts' => $pdo->query(
                    'SELECT oi.product_name AS productName, SUM(oi.quantity) AS quantity,
                            SUM(oi.line_total) AS revenue
                     FROM order_items oi JOIN orders o ON o.id = oi.order_id
                     WHERE o.payment_status = \'paid\'
                     GROUP BY oi.product_id, oi.product_name
                     ORDER BY quantity DESC, revenue DESC LIMIT 10'
                )->fetchAll(),
                'topCustomers' => $pdo->query(
                    "SELECT COALESCE(NULLIF(o.recipient_name, ''), u.name) AS customerName,
                            COALESCE(NULLIF(o.recipient_email, ''), u.email) AS customerEmail,
                            COUNT(*) AS orderCount, SUM(o.total_amount) AS revenue
                     FROM orders o JOIN users u ON u.id = o.user_id
                     WHERE o.payment_status = 'paid'
                     GROUP BY u.id, customerName, customerEmail
                     ORDER BY revenue DESC LIMIT 10"
                )->fetchAll(),
                'stock' => $pdo->query(
                    'SELECT id, name, category, stock, price, is_active AS isActive
                     FROM products ORDER BY stock ASC, name LIMIT 200'
                )->fetchAll(),
            ];
        }
        $products = $pdo->query(
            'SELECT id, name, category, price, image_url AS image, stock, is_active AS isActive
             FROM products ORDER BY name'
        )->fetchAll();
        $users = $pdo->query(
            'SELECT id, name, email, role, is_active AS isActive, created_at AS createdAt
             FROM users ORDER BY created_at DESC'
        )->fetchAll();
        respond(200, [
            'stats' => $stats,
            'orders' => $orders,
            'transactionActivities' => $transactionActivities,
            'management' => $management,
            'reports' => $reports,
            'products' => $products,
            'users' => $users,
        ]);
    }

    if ($route === 'admin-tool-save' && $method === 'POST') {
        requireCsrf();
        $admin = requireSuperAdmin();
        $body = requestBody();
        $type = $body['type'] ?? null;
        $data = $body['data'] ?? null;
        if (!is_string($type) || !is_array($data)) {
            respond(422, ['error' => 'Jenis atau data pengaturan tidak valid.']);
        }

        $pdo = database();
        if ($type === 'coupon') {
            $code = strtoupper(trim((string) ($data['code'] ?? '')));
            $discountType = $data['discountType'] ?? null;
            $discountValue = filter_var($data['discountValue'] ?? null, FILTER_VALIDATE_INT);
            $usageLimit = ($data['usageLimit'] ?? '') === ''
                ? null
                : filter_var($data['usageLimit'], FILTER_VALIDATE_INT);
            if (!preg_match('/^[A-Z0-9_-]{3,40}$/D', $code)
                || !is_string($discountType) || !in_array($discountType, ['percent', 'fixed'], true)
                || $discountValue === false || $discountValue < 1
                || ($discountType === 'percent' && $discountValue > 100)
                || ($usageLimit !== null && ($usageLimit === false || $usageLimit < 1))) {
                respond(422, ['error' => 'Periksa kode, tipe, nilai diskon, dan batas penggunaan kupon.']);
            }
            $startsAt = trim((string) ($data['startsAt'] ?? ''));
            $endsAt = trim((string) ($data['endsAt'] ?? ''));
            if (($startsAt !== '' && !validLocalDateTime($startsAt))
                || ($endsAt !== '' && !validLocalDateTime($endsAt))
                || ($startsAt !== '' && $endsAt !== '' && $startsAt >= $endsAt)) {
                respond(422, ['error' => 'Masa berlaku kupon tidak valid.']);
            }
            $statement = $pdo->prepare(
                'INSERT INTO coupons (code, discount_type, discount_value, starts_at, ends_at, usage_limit)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            try {
                $statement->execute([
                    $code, $discountType, $discountValue,
                    $startsAt === '' ? null : str_replace('T', ' ', $startsAt) . ':00',
                    $endsAt === '' ? null : str_replace('T', ' ', $endsAt) . ':00',
                    $usageLimit,
                ]);
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') {
                    respond(409, ['error' => 'Kode kupon sudah digunakan.']);
                }
                throw $error;
            }
            respond(201, ['message' => 'Kupon berhasil dibuat.']);
        }

        if ($type === 'flash_sale') {
            $title = trim((string) ($data['title'] ?? ''));
            $productId = ($data['productId'] ?? '') === ''
                ? null
                : filter_var($data['productId'], FILTER_VALIDATE_INT);
            $salePrice = filter_var($data['salePrice'] ?? null, FILTER_VALIDATE_INT);
            $startsAt = trim((string) ($data['startsAt'] ?? ''));
            $endsAt = trim((string) ($data['endsAt'] ?? ''));
            if (strlen($title) < 2 || strlen($title) > 180
                || ($productId !== null && ($productId === false || $productId < 1))
                || $salePrice === false || $salePrice < 0
                || !validLocalDateTime($startsAt)
                || !validLocalDateTime($endsAt)
                || $startsAt >= $endsAt) {
                respond(422, ['error' => 'Judul, produk, harga, dan periode flash sale tidak valid.']);
            }
            if ($productId !== null) {
                $findProduct = $pdo->prepare('SELECT 1 FROM products WHERE id = ?');
                $findProduct->execute([$productId]);
                if (!$findProduct->fetchColumn()) {
                    respond(422, ['error' => 'Produk flash sale tidak ditemukan.']);
                }
            }
            $statement = $pdo->prepare(
                'INSERT INTO flash_sales (title, product_id, sale_price, starts_at, ends_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $title,
                $productId,
                $salePrice,
                str_replace('T', ' ', $startsAt) . ':00',
                str_replace('T', ' ', $endsAt) . ':00',
            ]);
            respond(201, ['message' => 'Jadwal flash sale berhasil disimpan.']);
        }

        if ($type === 'campaign') {
            $channel = $data['channel'] ?? null;
            $subject = trim((string) ($data['subject'] ?? ''));
            $message = trim((string) ($data['body'] ?? ''));
            $status = $data['status'] ?? 'draft';
            $scheduledAt = trim((string) ($data['scheduledAt'] ?? ''));
            if (!is_string($channel) || !in_array($channel, ['notification', 'email'], true)
                || strlen($subject) < 2 || strlen($subject) > 180
                || $message === '' || strlen($message) > 5000
                || !is_string($status) || !in_array($status, ['draft', 'scheduled'], true)
                || ($scheduledAt !== '' && !validLocalDateTime($scheduledAt))
                || ($status === 'scheduled' && $scheduledAt === '')) {
                respond(422, ['error' => 'Periksa kanal, subjek, isi, dan jadwal notifikasi/email.']);
            }
            $statement = $pdo->prepare(
                'INSERT INTO notification_campaigns (channel, subject, body, status, scheduled_at)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $channel,
                $subject,
                $message,
                $status,
                $scheduledAt === '' ? null : str_replace('T', ' ', $scheduledAt) . ':00',
            ]);
            respond(201, ['message' => 'Draft kampanye berhasil disimpan. Pengiriman belum diaktifkan.']);
        }

        if ($type === 'review') {
            $customerName = trim((string) ($data['customerName'] ?? ''));
            $productId = ($data['productId'] ?? '') === ''
                ? null
                : filter_var($data['productId'], FILTER_VALIDATE_INT);
            $rating = filter_var($data['rating'] ?? null, FILTER_VALIDATE_INT);
            $review = trim((string) ($data['reviewText'] ?? ''));
            if (strlen($customerName) < 2 || strlen($customerName) > 120
                || ($productId !== null && ($productId === false || $productId < 1))
                || $rating === false || $rating < 1 || $rating > 5
                || $review === '' || strlen($review) > 5000) {
                respond(422, ['error' => 'Nama, rating 1–5, atau isi ulasan tidak valid.']);
            }
            if ($productId !== null) {
                $findProduct = $pdo->prepare('SELECT 1 FROM products WHERE id = ?');
                $findProduct->execute([$productId]);
                if (!$findProduct->fetchColumn()) {
                    respond(422, ['error' => 'Produk ulasan tidak ditemukan.']);
                }
            }
            $statement = $pdo->prepare(
                'INSERT INTO product_reviews (product_id, customer_name, rating, review_text)
                 VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$productId, $customerName, $rating, $review]);
            respond(201, ['message' => 'Ulasan masuk ke antrean moderasi.']);
        }

        if ($type === 'complaint') {
            $customerName = trim((string) ($data['customerName'] ?? ''));
            $customerEmail = strtolower(trim((string) ($data['customerEmail'] ?? '')));
            $subject = trim((string) ($data['subject'] ?? ''));
            $message = trim((string) ($data['message'] ?? ''));
            if (strlen($customerName) < 2 || strlen($customerName) > 120
                || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL) || strlen($customerEmail) > 190
                || strlen($subject) < 2 || strlen($subject) > 180
                || $message === '' || strlen($message) > 5000) {
                respond(422, ['error' => 'Periksa nama, email, subjek, dan pesan komplain.']);
            }
            $statement = $pdo->prepare(
                'INSERT INTO customer_complaints (customer_name, customer_email, subject, message)
                 VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$customerName, $customerEmail, $subject, $message]);
            respond(201, ['message' => 'Komplain berhasil dicatat.']);
        }

        if ($type === 'abuse_report') {
            $email = strtolower(trim((string) ($data['reporterEmail'] ?? '')));
            $targetType = trim((string) ($data['targetType'] ?? ''));
            $targetReference = trim((string) ($data['targetReference'] ?? ''));
            $reason = trim((string) ($data['reason'] ?? ''));
            if (($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190))
                || strlen($targetType) < 2 || strlen($targetType) > 40
                || strlen($targetReference) < 1 || strlen($targetReference) > 190
                || strlen($reason) < 3 || strlen($reason) > 5000) {
                respond(422, ['error' => 'Periksa jenis target, referensi, dan alasan laporan.']);
            }
            $statement = $pdo->prepare(
                'INSERT INTO abuse_reports (reporter_email, target_type, target_reference, reason)
                 VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$email, $targetType, $targetReference, $reason]);
            respond(201, ['message' => 'Laporan penyalahgunaan masuk ke antrean.']);
        }

        respond(422, ['error' => 'Jenis pengelolaan tidak didukung.']);
    }

    if ($route === 'admin-tool-status' && $method === 'PATCH') {
        requireCsrf();
        requireSuperAdmin();
        $body = requestBody();
        $type = $body['type'] ?? null;
        $recordId = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
        $status = $body['status'] ?? null;
        $statusColumns = [
            'coupon' => ['coupons', 'is_active', ['0', '1']],
            'flash_sale' => ['flash_sales', 'is_active', ['0', '1']],
            'campaign' => ['notification_campaigns', 'status', ['draft', 'scheduled']],
            'review' => ['product_reviews', 'status', ['pending', 'published', 'rejected']],
            'complaint' => ['customer_complaints', 'status', ['open', 'in_progress', 'resolved', 'closed']],
            'abuse_report' => ['abuse_reports', 'status', ['open', 'investigating', 'resolved', 'dismissed']],
        ];
        if (!is_string($type) || !isset($statusColumns[$type])
            || $recordId === false || $recordId < 1
            || (!is_string($status) && !is_int($status))
            || !in_array((string) $status, $statusColumns[$type][2], true)) {
            respond(422, ['error' => 'Jenis data, ID, atau status tidak valid.']);
        }
        [$table, $column] = $statusColumns[$type];
        $statement = database()->prepare("UPDATE {$table} SET {$column} = ? WHERE id = ?");
        $statement->execute([(string) $status, $recordId]);
        if ($statement->rowCount() === 0) {
            $exists = database()->prepare("SELECT 1 FROM {$table} WHERE id = ?");
            $exists->execute([$recordId]);
            if (!$exists->fetchColumn()) {
                respond(404, ['error' => 'Data tidak ditemukan.']);
            }
        }
        respond(200, ['message' => 'Status data berhasil diperbarui.']);
    }

    if ($route === 'admin-tool-delete' && $method === 'DELETE') {
        requireCsrf();
        requireSuperAdmin();
        $body = requestBody();
        $type = $body['type'] ?? null;
        $recordId = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
        $tables = [
            'coupon' => 'coupons',
            'flash_sale' => 'flash_sales',
            'campaign' => 'notification_campaigns',
            'review' => 'product_reviews',
            'complaint' => 'customer_complaints',
            'abuse_report' => 'abuse_reports',
        ];
        if (!is_string($type) || !isset($tables[$type]) || $recordId === false || $recordId < 1) {
            respond(422, ['error' => 'Jenis data atau ID tidak valid.']);
        }
        $statement = database()->prepare('DELETE FROM ' . $tables[$type] . ' WHERE id = ?');
        $statement->execute([$recordId]);
        if ($statement->rowCount() !== 1) {
            respond(404, ['error' => 'Data tidak ditemukan.']);
        }
        respond(200, ['message' => 'Data berhasil dihapus.']);
    }

    if ($route === 'admin-user-role' && $method === 'PATCH') {
        requireCsrf();
        $admin = requireSuperAdmin();
        $body = requestBody();
        $userId = filter_var($body['userId'] ?? null, FILTER_VALIDATE_INT);
        $role = $body['role'] ?? null;
        if ($userId === false || $userId < 1 || !is_string($role) || !in_array($role, ['user', 'admin'], true)) {
            respond(422, ['error' => 'Akun atau peran tidak valid.']);
        }
        if ($userId === $admin['id']) {
            respond(422, ['error' => 'Peran akun yang sedang digunakan tidak dapat diubah.']);
        }

        $pdo = database();
        $pdo->beginTransaction();
        try {
            $users = $pdo->query('SELECT id, role FROM users FOR UPDATE')->fetchAll();
            $target = null;
            $adminCount = 0;
            foreach ($users as $user) {
                if ((int) $user['id'] === $userId) {
                    $target = $user;
                }
                if (in_array($user['role'], ['admin', 'super_admin'], true)) {
                    $adminCount++;
                }
            }
            if ($target === null) {
                $pdo->rollBack();
                respond(404, ['error' => 'Akun tidak ditemukan.']);
            }
            if ($target['role'] === 'super_admin') {
                $pdo->rollBack();
                respond(403, ['error' => 'Peran super admin hanya dapat diubah melalui akses database langsung.']);
            }
            if ($target['role'] === 'admin' && $role === 'user' && $adminCount <= 1) {
                $pdo->rollBack();
                respond(409, ['error' => 'Minimal harus ada satu akun administrator.']);
            }
            $statement = $pdo->prepare('UPDATE users SET role = ? WHERE id = ?');
            $statement->execute([$role, $userId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        respond(200, ['message' => 'Peran akun berhasil diperbarui.']);
    }

    if ($route === 'admin-user-status' && $method === 'PATCH') {
        requireCsrf();
        $admin = requireSuperAdmin();
        $body = requestBody();
        $userId = filter_var($body['userId'] ?? null, FILTER_VALIDATE_INT);
        $isActive = filter_var($body['isActive'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($userId === false || $userId < 1 || $isActive === null) {
            respond(422, ['error' => 'Akun atau status tidak valid.']);
        }
        if ($userId === $admin['id']) {
            respond(422, ['error' => 'Akun super admin yang sedang digunakan tidak dapat dinonaktifkan.']);
        }
        $pdo = database();
        $target = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $target->execute([$userId]);
        $targetRole = $target->fetchColumn();
        if ($targetRole === false) {
            respond(404, ['error' => 'Akun tidak ditemukan.']);
        }
        if ($targetRole === 'super_admin') {
            respond(403, ['error' => 'Status akun super admin hanya dapat diubah melalui akses database langsung.']);
        }
        $statement = $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ?');
        $statement->execute([$isActive ? 1 : 0, $userId]);
        respond(200, ['message' => $isActive ? 'Akun berhasil diaktifkan.' : 'Akun berhasil dinonaktifkan.']);
    }

    if ($route === 'admin-product-save' && in_array($method, ['POST', 'PATCH'], true)) {
        requireCsrf();
        requireSuperAdmin();
        $body = requestBody();
        $product = validateProductPayload($body);
        $pdo = database();
        if ($method === 'POST') {
            $statement = $pdo->prepare(
                'INSERT INTO products (name, category, price, old_price, badge, image_url, stock, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $product['name'], $product['category'], $product['price'], $product['oldPrice'],
                $product['badge'], $product['image'], $product['stock'], $product['isActive'],
            ]);
            respond(201, ['productId' => (int) $pdo->lastInsertId(), 'message' => 'Produk berhasil ditambahkan ke katalog.']);
        }

        $productId = filter_var($body['productId'] ?? null, FILTER_VALIDATE_INT);
        if ($productId === false || $productId < 1) {
            respond(422, ['error' => 'ID produk tidak valid.']);
        }
        $statement = $pdo->prepare(
            'UPDATE products SET name = ?, category = ?, price = ?, old_price = ?, badge = ?,
             image_url = ?, stock = ?, is_active = ? WHERE id = ?'
        );
        $statement->execute([
            $product['name'], $product['category'], $product['price'], $product['oldPrice'],
            $product['badge'], $product['image'], $product['stock'], $product['isActive'], $productId,
        ]);
        if ($statement->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM products WHERE id = ?');
            $exists->execute([$productId]);
            if (!$exists->fetchColumn()) {
                respond(404, ['error' => 'Produk tidak ditemukan.']);
            }
        }
        respond(200, ['message' => 'Produk katalog berhasil diperbarui.']);
    }

    if ($route === 'admin-order-status' && $method === 'PATCH') {
        requireCsrf();
        requireAdmin();
        $body = requestBody();
        $orderId = filter_var($body['orderId'] ?? null, FILTER_VALIDATE_INT);
        $status = $body['status'] ?? null;
        $allowedStatuses = ['pending', 'paid', 'processing', 'shipped', 'completed', 'cancelled'];
        if ($orderId === false || $orderId < 1 || !is_string($status) || !in_array($status, $allowedStatuses, true)) {
            respond(422, ['error' => 'Pesanan atau status tidak valid.']);
        }
        $statement = database()->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $statement->execute([$status, $orderId]);
        if ($statement->rowCount() === 0) {
            $exists = database()->prepare('SELECT 1 FROM orders WHERE id = ?');
            $exists->execute([$orderId]);
            if (!$exists->fetchColumn()) {
                respond(404, ['error' => 'Pesanan tidak ditemukan.']);
            }
        }
        respond(200, ['message' => 'Status pesanan berhasil diperbarui.']);
    }

    if ($route === 'admin-payment-status' && $method === 'PATCH') {
        requireCsrf();
        $admin = requireSuperAdmin();
        $body = requestBody();
        $orderId = filter_var($body['orderId'] ?? null, FILTER_VALIDATE_INT);
        $paymentStatus = $body['paymentStatus'] ?? null;
        $allowedPaymentStatuses = ['pending', 'paid', 'failed', 'refunded'];
        if ($orderId === false || $orderId < 1
            || !is_string($paymentStatus)
            || !in_array($paymentStatus, $allowedPaymentStatuses, true)) {
            respond(422, ['error' => 'Pesanan atau status pembayaran tidak valid.']);
        }

        $pdo = database();
        $pdo->beginTransaction();
        try {
            $findOrder = $pdo->prepare('SELECT payment_status FROM orders WHERE id = ? FOR UPDATE');
            $findOrder->execute([$orderId]);
            $previousStatus = $findOrder->fetchColumn();
            if ($previousStatus === false) {
                $pdo->rollBack();
                respond(404, ['error' => 'Pesanan tidak ditemukan.']);
            }
            if ($previousStatus !== $paymentStatus) {
                $updateOrder = $pdo->prepare(
                    'UPDATE orders
                     SET payment_status = ?, payment_status_updated_at = NOW(), payment_status_updated_by = ?
                     WHERE id = ?'
                );
                $updateOrder->execute([$paymentStatus, $admin['id'], $orderId]);
                $logActivity = $pdo->prepare(
                    'INSERT INTO transaction_activity
                     (order_id, actor_user_id, activity_type, previous_status, new_status)
                     VALUES (?, ?, \'payment_status_changed\', ?, ?)'
                );
                $logActivity->execute([$orderId, $admin['id'], $previousStatus, $paymentStatus]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        respond(200, ['message' => 'Status pembayaran berhasil diperbarui secara manual.']);
    }

    if ($route === 'admin-product-stock' && $method === 'PATCH') {
        requireCsrf();
        requireAdmin();
        $body = requestBody();
        $productId = filter_var($body['productId'] ?? null, FILTER_VALIDATE_INT);
        $stock = filter_var($body['stock'] ?? null, FILTER_VALIDATE_INT);
        if ($productId === false || $productId < 1 || $stock === false || $stock < 0 || $stock > 1000000) {
            respond(422, ['error' => 'Produk atau jumlah stok tidak valid.']);
        }
        $statement = database()->prepare('UPDATE products SET stock = ? WHERE id = ?');
        $statement->execute([$stock, $productId]);
        if ($statement->rowCount() === 0) {
            $exists = database()->prepare('SELECT 1 FROM products WHERE id = ?');
            $exists->execute([$productId]);
            if (!$exists->fetchColumn()) {
                respond(404, ['error' => 'Produk tidak ditemukan.']);
            }
        }
        respond(200, ['message' => 'Stok produk berhasil diperbarui.']);
    }

    if ($route === 'orders' && $method === 'POST') {
        requireCsrf();
        $user = requireUser();
        $body = requestBody();
        $recipientName = trim((string) ($body['recipientName'] ?? ''));
        $recipientEmail = strtolower(trim((string) ($body['recipientEmail'] ?? '')));
        $recipientPhone = trim((string) ($body['recipientPhone'] ?? ''));
        $address = trim((string) ($body['shippingAddress'] ?? ''));
        $paymentMethod = $body['paymentMethod'] ?? null;
        $items = $body['items'] ?? null;

        $phoneDigits = preg_replace('/\D/', '', $recipientPhone);
        if (strlen($recipientName) < 2 || strlen($recipientName) > 120
            || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) || strlen($recipientEmail) > 190
            || !preg_match('/^[0-9+().\-\s]{8,30}$/', $recipientPhone) || strlen($phoneDigits) < 8
            || strlen($address) < 10 || strlen($address) > 2000
            || !is_string($paymentMethod) || !in_array($paymentMethod, ['cod', 'bank_transfer'], true)
            || !is_array($items) || count($items) < 1 || count($items) > 50) {
            respond(422, ['error' => 'Periksa nama, email, nomor telepon, alamat, metode pembayaran, dan produk pesanan.']);
        }

        $quantities = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                respond(422, ['error' => 'Daftar produk atau jumlah barang tidak valid.']);
            }
            $productId = filter_var($item['productId'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($productId === false || $productId < 1 || $quantity === false || $quantity < 1 || $quantity > 99) {
                respond(422, ['error' => 'Daftar produk atau jumlah barang tidak valid.']);
            }
            $quantities[$productId] = ($quantities[$productId] ?? 0) + $quantity;
            if ($quantities[$productId] > 99) {
                respond(422, ['error' => 'Jumlah maksimum tiap produk adalah 99.']);
            }
        }

        $pdo = database();
        $pdo->beginTransaction();
        try {
            $findProduct = $pdo->prepare('SELECT id, name, price, stock FROM products WHERE id = ? AND is_active = 1 FOR UPDATE');
            $decreaseStock = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
            $insertOrder = $pdo->prepare(
                'INSERT INTO orders (user_id, recipient_name, recipient_email, recipient_phone, total_amount, shipping_address, payment_method)
                 VALUES (?, ?, ?, ?, 0, ?, ?)'
            );
            $insertItem = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );

            $orderLines = [];
            $total = 0;
            foreach ($quantities as $productId => $quantity) {
                $findProduct->execute([$productId]);
                $product = $findProduct->fetch();
                if (!$product || (int) $product['stock'] < $quantity) {
                    $pdo->rollBack();
                    respond(409, ['error' => 'Produk tidak tersedia atau stok tidak mencukupi. Perbarui keranjang lalu coba lagi.']);
                }
                $lineTotal = (int) $product['price'] * $quantity;
                $total += $lineTotal;
                $orderLines[] = [$product, $quantity, $lineTotal];
            }

            $insertOrder->execute([
                $user['id'], $recipientName, $recipientEmail, $recipientPhone, $address, $paymentMethod,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            foreach ($orderLines as [$product, $quantity, $lineTotal]) {
                $decreaseStock->execute([$quantity, $product['id'], $quantity]);
                if ($decreaseStock->rowCount() !== 1) {
                    $pdo->rollBack();
                    respond(409, ['error' => 'Stok produk baru saja berubah. Coba lagi.']);
                }
                $insertItem->execute([$orderId, $product['id'], $product['name'], $product['price'], $quantity, $lineTotal]);
            }

            $updateOrder = $pdo->prepare('UPDATE orders SET total_amount = ? WHERE id = ?');
            $updateOrder->execute([$total, $orderId]);
            $logActivity = $pdo->prepare(
                'INSERT INTO transaction_activity
                 (order_id, actor_user_id, activity_type, previous_status, new_status)
                 VALUES (?, ?, \'order_created\', NULL, \'pending\')'
            );
            $logActivity->execute([$orderId, $user['id']]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        respond(201, [
            'orderId' => $orderId,
            'total' => $total,
            'status' => 'pending',
            'paymentMethod' => $paymentMethod,
            'recipientName' => $recipientName,
        ]);
    }

    respond(404, ['error' => 'Endpoint tidak ditemukan.']);
} catch (Throwable $error) {
    error_log('[GadgetHub API] ' . $error->getMessage());
    respond(500, ['error' => 'Terjadi kesalahan pada server. Pastikan MySQL aktif dan konfigurasi database sudah benar.']);
}
