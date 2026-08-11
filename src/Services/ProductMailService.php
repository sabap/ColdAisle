<?php
/**
 * ColdAisle — product email flows (G-B5).
 *
 * Welcome user, local forgot/reset password, disposal due-soon digests.
 * Transport: MailService (SMTP).
 */
declare(strict_types=1);

class ProductMailService
{
    public const RESET_TTL_HOURS = 2;
    public const SETTING_DISPOSAL_MAIL = 'disposal_mail_enabled';
    public const SETTING_DISPOSAL_EMAIL = 'disposal_notify_email';
    public const SETTING_DISPOSAL_DAYS = 'disposal_notify_days';

    /** Absolute app URL for a path (CLI-safe via configured base_url). */
    public static function absoluteUrl(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $base = rtrim(App::baseUrl(), '/');
        if ($base === '' || $base === '/') {
            // CLI or missing base_url — best-effort host from config
            $base = rtrim((string)App::config('base_url'), '/');
        }
        if ($base === '') {
            $base = 'http://localhost';
        }
        return $path === '' ? $base : $base . '/' . $path;
    }

    public static function mailReady(): bool
    {
        return class_exists('MailService') && MailService::isEnabled();
    }

    // -------------------------------------------------------------------------
    // Welcome
    // -------------------------------------------------------------------------

    /**
     * Send welcome email after admin creates a user.
     * Includes a one-time set-password link for local accounts (no password in email).
     *
     * @param array<string,mixed> $user Row with user_id, username, email, display_name?, auth_source?
     * @return array{ok:bool,message:string}
     */
    public static function sendWelcome(array $user, bool $includeResetLink = true): array
    {
        if (!self::mailReady()) {
            return ['ok' => false, 'message' => 'Outbound mail is not enabled or configured.'];
        }
        $email = trim((string)($user['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'User has no valid email address.'];
        }

        $username = (string)($user['username'] ?? '');
        $display = trim((string)($user['display_name'] ?? ''));
        $greet = $display !== '' ? $display : $username;
        $auth = strtolower((string)($user['auth_source'] ?? 'local'));
        $loginUrl = self::absoluteUrl('login.php');
        $app = App::APP_NAME;

        $resetLineText = '';
        $resetLineHtml = '';
        if ($includeResetLink && $auth === 'local' && !empty($user['user_id'])) {
            $token = self::createResetToken((int)$user['user_id'], $email);
            if ($token !== null) {
                $resetUrl = self::absoluteUrl('reset_password.php?token=' . urlencode($token));
                $hours = self::RESET_TTL_HOURS;
                $resetLineText = "\r\nSet or change your password (link expires in {$hours} hours):\r\n{$resetUrl}\r\n";
                $resetLineHtml = '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '">Set or change your password</a> '
                    . '(link expires in ' . $hours . ' hours).</p>';
            }
        }

        $authHint = match ($auth) {
            'ldaps' => 'Sign in with your domain (LDAPS) credentials on the login form.',
            'entra' => 'Sign in with Microsoft Entra ID from the login page.',
            default => 'Sign in with your local username'
                . ($includeResetLink ? ' (use the set-password link if you do not have a password yet).' : '.'),
        };

        $subject = "Welcome to {$app}";
        $text = "Hello {$greet},\r\n\r\n"
            . "An account has been created for you on {$app}.\r\n\r\n"
            . "Username: {$username}\r\n"
            . "Sign in: {$loginUrl}\r\n"
            . "{$authHint}\r\n"
            . $resetLineText
            . "\r\nIf you did not expect this message, contact your {$app} administrator.\r\n";

        $html = '<!DOCTYPE html><html><body style="font-family:system-ui,sans-serif;line-height:1.5">'
            . '<h2 style="margin:0 0 .5rem">Welcome to ' . htmlspecialchars($app, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>'
            . '<p>Hello ' . htmlspecialchars($greet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>'
            . '<p>An account has been created for you.</p>'
            . '<ul>'
            . '<li><strong>Username:</strong> ' . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>'
            . '<li><strong>Sign in:</strong> <a href="' . htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>'
            . '</ul>'
            . '<p>' . htmlspecialchars($authHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . $resetLineHtml
            . '<p style="color:#64748b;font-size:.9rem">If you did not expect this message, contact your administrator.</p>'
            . '</body></html>';

        try {
            $result = MailService::send($email, $subject, ['text' => $text, 'html' => $html]);
            if (!empty($result['ok'])) {
                App::log("Welcome email sent to {$email} (user {$username})", 'info');
            }
            return $result;
        } catch (Throwable $e) {
            App::log('Welcome email failed: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Forgot / reset password (local only)
    // -------------------------------------------------------------------------

    /**
     * Request a password reset. Always returns a neutral success message
     * (does not reveal whether the email exists).
     *
     * @return array{ok:bool,message:string,sent?:bool}
     */
    public static function requestPasswordReset(string $emailOrUsername): array
    {
        $neutral = 'If an account matches, a password reset link has been sent. Check your inbox.';
        $ident = trim($emailOrUsername);
        if ($ident === '') {
            return ['ok' => false, 'message' => 'Enter your username or email address.'];
        }

        if (!self::mailReady()) {
            // Still neutral to the public page, but log
            App::log('Password reset requested but mail is not configured', 'warning');
            return ['ok' => true, 'message' => $neutral, 'sent' => false];
        }

        try {
            $user = Database::fetchOne(
                "SELECT user_id, username, email, display_name, auth_source, is_active
                 FROM users
                 WHERE is_active = 1 AND auth_source = 'local'
                   AND (email = ? OR username = ?)",
                [$ident, $ident]
            );
        } catch (Throwable $e) {
            App::log('Password reset lookup failed: ' . $e->getMessage(), 'error');
            return ['ok' => true, 'message' => $neutral, 'sent' => false];
        }

        if (!$user || empty($user['email']) || !filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL)) {
            // Timing-ish delay
            usleep(200000);
            return ['ok' => true, 'message' => $neutral, 'sent' => false];
        }

        $token = self::createResetToken((int)$user['user_id'], (string)$user['email']);
        if ($token === null) {
            return ['ok' => true, 'message' => $neutral, 'sent' => false];
        }

        $resetUrl = self::absoluteUrl('reset_password.php?token=' . urlencode($token));
        $hours = self::RESET_TTL_HOURS;
        $app = App::APP_NAME;
        $greet = trim((string)($user['display_name'] ?? '')) !== ''
            ? (string)$user['display_name']
            : (string)$user['username'];

        $subject = "{$app} password reset";
        $text = "Hello {$greet},\r\n\r\n"
            . "We received a request to reset the password for your {$app} account"
            . " ({$user['username']}).\r\n\r\n"
            . "Open this link within {$hours} hours:\r\n{$resetUrl}\r\n\r\n"
            . "If you did not request this, you can ignore this message.\r\n";

        $html = '<!DOCTYPE html><html><body style="font-family:system-ui,sans-serif;line-height:1.5">'
            . '<h2 style="margin:0 0 .5rem">Password reset</h2>'
            . '<p>Hello ' . htmlspecialchars($greet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ',</p>'
            . '<p>We received a request to reset the password for your '
            . htmlspecialchars($app, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' account (<strong>' . htmlspecialchars((string)$user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</strong>).</p>'
            . '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">Reset your password</a> (expires in ' . $hours . ' hours).</p>'
            . '<p style="color:#64748b;font-size:.9rem">If you did not request this, ignore this message.</p>'
            . '</body></html>';

        try {
            $result = MailService::send((string)$user['email'], $subject, ['text' => $text, 'html' => $html]);
            if (!empty($result['ok'])) {
                App::log('Password reset email sent for user_id=' . (int)$user['user_id'], 'info');
                return ['ok' => true, 'message' => $neutral, 'sent' => true];
            }
            App::log('Password reset send failed: ' . ($result['message'] ?? ''), 'error');
        } catch (Throwable $e) {
            App::log('Password reset send exception: ' . $e->getMessage(), 'error');
        }

        return ['ok' => true, 'message' => $neutral, 'sent' => false];
    }

    /**
     * Validate a raw token; return user row or null.
     * @return array<string,mixed>|null
     */
    public static function findValidReset(string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || strlen($rawToken) < 32) {
            return null;
        }
        $hash = hash('sha256', $rawToken);
        try {
            $row = Database::fetchOne(
                "SELECT t.token_id, t.user_id, t.expires_at, t.used_at,
                        u.username, u.email, u.display_name, u.auth_source, u.is_active
                 FROM password_reset_tokens t
                 INNER JOIN users u ON u.user_id = t.user_id
                 WHERE t.token_hash = ?
                   AND t.used_at IS NULL
                   AND u.is_active = 1
                   AND u.auth_source = 'local'",
                [$hash]
            );
        } catch (Throwable $e) {
            App::log('findValidReset: ' . $e->getMessage(), 'error');
            return null;
        }
        if (!$row) {
            return null;
        }
        $exp = strtotime((string)$row['expires_at']);
        if ($exp === false || $exp < time()) {
            return null;
        }
        return $row;
    }

    /**
     * Apply new password and consume token.
     * @return array{ok:bool,message:string}
     */
    public static function completePasswordReset(string $rawToken, string $newPassword): array
    {
        if (strlen($newPassword) < 8) {
            return ['ok' => false, 'message' => 'Password must be at least 8 characters.'];
        }
        $row = self::findValidReset($rawToken);
        if (!$row) {
            return ['ok' => false, 'message' => 'This reset link is invalid or has expired. Request a new one.'];
        }

        $uid = (int)$row['user_id'];
        $tokenId = (int)$row['token_id'];
        try {
            Database::update('users', [
                'password_hash' => LocalAuth::hashPassword($newPassword),
                'must_change_password' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'user_id = :id', [':id' => $uid]);

            Database::update('password_reset_tokens', [
                'used_at' => date('Y-m-d H:i:s'),
            ], 'token_id = :id', [':id' => $tokenId]);

            // Invalidate other outstanding tokens for this user
            try {
                Database::query(
                    'UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL AND token_id <> ?',
                    [date('Y-m-d H:i:s'), $uid, $tokenId]
                );
            } catch (Throwable $e) {
                // non-fatal
            }

            App::log("Password reset completed for user_id={$uid}", 'info');
            return ['ok' => true, 'message' => 'Password updated. You can sign in now.'];
        } catch (Throwable $e) {
            App::log('completePasswordReset: ' . $e->getMessage(), 'error');
            return ['ok' => false, 'message' => 'Could not update password. Try again or contact an administrator.'];
        }
    }

    /**
     * Create a reset token; returns raw token for email URL, or null on failure.
     */
    public static function createResetToken(int $userId, string $email = ''): ?string
    {
        if ($userId < 1) {
            return null;
        }
        try {
            $raw = bin2hex(random_bytes(32));
            $hash = hash('sha256', $raw);
            $expires = date('Y-m-d H:i:s', time() + self::RESET_TTL_HOURS * 3600);

            // Drop old unused tokens for this user
            try {
                Database::query(
                    'DELETE FROM password_reset_tokens WHERE user_id = ? AND (used_at IS NOT NULL OR expires_at < SYSUTCDATETIME())',
                    [$userId]
                );
            } catch (Throwable $e) {
                // ignore
            }

            Database::insert('password_reset_tokens', [
                'user_id' => $userId,
                'token_hash' => $hash,
                'email' => $email !== '' ? $email : null,
                'expires_at' => $expires,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return $raw;
        } catch (Throwable $e) {
            App::log('createResetToken: ' . $e->getMessage(), 'error');
            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Disposal due-soon digests
    // -------------------------------------------------------------------------

    public static function disposalMailEnabled(): bool
    {
        $v = SettingsService::get(self::SETTING_DISPOSAL_MAIL, '1');
        return $v === '1' || $v === 'true' || $v === 'yes';
    }

    /** @return list<string> */
    public static function disposalRecipients(): array
    {
        $raw = trim((string)SettingsService::get(self::SETTING_DISPOSAL_EMAIL, ''));
        if ($raw === '') {
            $raw = trim((string)SettingsService::get('alerts_default_email', ''));
        }
        if ($raw === '') {
            $raw = trim((string)SettingsService::get('power_alerts_email', ''));
        }
        return self::normalizeEmails($raw);
    }

    /**
     * Find open disposals due within notify window that have not been emailed yet.
     * Marks notification_sent after a successful send.
     *
     * @return array{checked:int,due:int,sent:int,skipped:int,message:string}
     */
    public static function processDisposalReminders(bool $force = false): array
    {
        $out = ['checked' => 0, 'due' => 0, 'sent' => 0, 'skipped' => 0, 'message' => ''];

        if (!$force && !self::disposalMailEnabled()) {
            $out['message'] = 'disposal mail disabled';
            return $out;
        }
        if (!self::mailReady()) {
            $out['message'] = 'mail not ready';
            return $out;
        }

        $recipients = self::disposalRecipients();
        if (!$recipients) {
            $out['message'] = 'no disposal recipients (set Disposal notify email or Alerts default email)';
            return $out;
        }

        $days = max(0, min(365, (int)SettingsService::get(self::SETTING_DISPOSAL_DAYS, '7')));
        try {
            $rows = Database::fetchAll(
                "SELECT d.disposal_id, d.device_id, d.scheduled_date, d.stage, d.status, d.change_ticket,
                        d.notification_sent, dev.label AS device_label, dev.serial_no
                 FROM disposals d
                 INNER JOIN devices dev ON dev.device_id = d.device_id
                 WHERE d.status IN ('pending','approved','in_progress')
                   AND d.scheduled_date IS NOT NULL
                   AND d.scheduled_date <= DATEADD(day, ?, CAST(GETUTCDATE() AS date))
                   AND (d.notification_sent IS NULL OR d.notification_sent = 0)
                 ORDER BY d.scheduled_date",
                [$days]
            );
        } catch (Throwable $e) {
            // notification_sent column may be missing on very old DBs mid-upgrade
            App::log('processDisposalReminders query: ' . $e->getMessage(), 'error');
            $out['message'] = 'query failed: ' . $e->getMessage();
            return $out;
        }

        $out['checked'] = count($rows);
        $out['due'] = count($rows);
        if (!$rows) {
            $out['message'] = 'no due disposals pending notify';
            return $out;
        }

        $app = App::APP_NAME;
        $listUrl = self::absoluteUrl('pages/disposals.php');
        $lines = [];
        $htmlItems = [];
        foreach ($rows as $r) {
            $label = (string)($r['device_label'] ?? ('#' . $r['device_id']));
            $date = (string)($r['scheduled_date'] ?? '');
            $stage = (string)($r['stage'] ?? '');
            $ticket = trim((string)($r['change_ticket'] ?? ''));
            $serial = trim((string)($r['serial_no'] ?? ''));
            $line = "{$label} — target {$date}"
                . ($stage !== '' ? " [{$stage}]" : '')
                . ($ticket !== '' ? " ticket {$ticket}" : '')
                . ($serial !== '' ? " S/N {$serial}" : '');
            $lines[] = '  • ' . $line;
            $htmlItems[] = '<li><strong>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>'
                . ' — target ' . htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ($stage !== '' ? ' <span style="color:#64748b">[' . htmlspecialchars($stage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ']</span>' : '')
                . ($ticket !== '' ? ' · ticket ' . htmlspecialchars($ticket, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '')
                . '</li>';
        }

        $count = count($rows);
        $subject = "[{$app}] {$count} decommission(s) due within {$days} day(s)";
        $text = "{$count} open decommission(s) are scheduled within the next {$days} day(s):\r\n\r\n"
            . implode("\r\n", $lines)
            . "\r\n\r\nOpen queue: {$listUrl}\r\n";
        $html = '<!DOCTYPE html><html><body style="font-family:system-ui,sans-serif;line-height:1.5">'
            . '<h2 style="margin:0 0 .5rem">Decommission reminders</h2>'
            . '<p><strong>' . $count . '</strong> open decommission(s) scheduled within '
            . $days . ' day(s):</p>'
            . '<ul>' . implode('', $htmlItems) . '</ul>'
            . '<p><a href="' . htmlspecialchars($listUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Open disposal queue</a></p>'
            . '</body></html>';

        try {
            $result = MailService::send($recipients, $subject, ['text' => $text, 'html' => $html]);
            if (empty($result['ok'])) {
                $out['message'] = 'send failed: ' . ($result['message'] ?? 'unknown');
                $out['skipped'] = $count;
                return $out;
            }
        } catch (Throwable $e) {
            $out['message'] = 'send exception: ' . $e->getMessage();
            $out['skipped'] = $count;
            App::log('Disposal reminder send: ' . $e->getMessage(), 'error');
            return $out;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $r) {
            try {
                Database::update('disposals', [
                    'notification_sent' => 1,
                    'notification_sent_at' => $now,
                ], 'disposal_id = :id', [':id' => (int)$r['disposal_id']]);
                $out['sent']++;
            } catch (Throwable $e) {
                App::log('Mark disposal notification_sent: ' . $e->getMessage(), 'error');
            }
        }

        // In-app notice (best effort)
        if (class_exists('AlertService')) {
            try {
                AlertService::emit([
                    'category' => AlertService::CAT_SYSTEM,
                    'severity' => AlertService::SEV_WARNING,
                    'title' => "{$count} decommission(s) due soon",
                    'message' => implode("\n", $lines) . "\n" . $listUrl,
                    'skip_email' => true,
                ]);
            } catch (Throwable $e) {
                // ignore
            }
        }

        $out['message'] = "sent digest for {$out['sent']} disposal(s) to " . implode(', ', $recipients);
        App::log('Disposal reminders: ' . $out['message'], 'info');
        return $out;
    }

    /** Reset notification flag when scheduled date changes (call from disposals save). */
    public static function resetDisposalNotifyFlag(int $disposalId): void
    {
        if ($disposalId < 1) {
            return;
        }
        try {
            Database::update('disposals', [
                'notification_sent' => 0,
                'notification_sent_at' => null,
            ], 'disposal_id = :id', [':id' => $disposalId]);
        } catch (Throwable $e) {
            // column may not exist yet
        }
    }

    /** @return list<string> */
    private static function normalizeEmails(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[,;\s]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                $out[] = $p;
            }
        }
        return array_values(array_unique($out));
    }
}
