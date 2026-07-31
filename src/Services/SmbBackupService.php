<?php
/**
 * Copy local backup ZIPs to a Windows SMB/UNC share (optional DR target).
 *
 * Build locally first (SiteBackupService / UpdateService), then copy.
 * Auth modes:
 *   - app_pool: use IIS app pool identity (no stored password)
 *   - local:    machine or local account (.\user or COMPUTER\user)
 *   - domain:   AD / domain credentials (DOMAIN\user or user@domain) — same accounts used with LDAPS
 *
 * Password is stored sealed via Crypto (settings table), never in the backup package.
 */
declare(strict_types=1);

class SmbBackupService
{
    public const SET_ENABLED = 'smb_backup_enabled';
    public const SET_UNC = 'smb_backup_unc';
    public const SET_AUTH_MODE = 'smb_backup_auth_mode';
    public const SET_USERNAME = 'smb_backup_username';
    public const SET_DOMAIN = 'smb_backup_domain';
    public const SET_PASSWORD = 'smb_backup_password';
    public const SET_ON_EXPORT = 'smb_backup_on_export';
    public const SET_ON_UPDATE = 'smb_backup_on_update_backup';
    public const SET_LAST_OK = 'smb_backup_last_ok_at';
    public const SET_LAST_ERROR = 'smb_backup_last_error';
    public const SET_LAST_FILE = 'smb_backup_last_file';

    /**
     * @return array{
     *   enabled:bool,unc:string,auth_mode:string,username:string,domain:string,
     *   has_password:bool,on_export:bool,on_update_backup:bool,
     *   last_ok_at:?string,last_error:?string,last_file:?string
     * }
     */
    public static function settings(): array
    {
        $mode = strtolower(trim((string)SettingsService::get(self::SET_AUTH_MODE, 'app_pool')));
        if (!in_array($mode, ['app_pool', 'local', 'domain'], true)) {
            $mode = 'app_pool';
        }
        $pass = (string)SettingsService::get(self::SET_PASSWORD, '');
        return [
            'enabled' => SettingsService::get(self::SET_ENABLED, '0') === '1',
            'unc' => trim((string)SettingsService::get(self::SET_UNC, '')),
            'auth_mode' => $mode,
            'username' => trim((string)SettingsService::get(self::SET_USERNAME, '')),
            'domain' => trim((string)SettingsService::get(self::SET_DOMAIN, '')),
            'has_password' => $pass !== '',
            'on_export' => SettingsService::get(self::SET_ON_EXPORT, '1') !== '0',
            'on_update_backup' => SettingsService::get(self::SET_ON_UPDATE, '0') === '1',
            'last_ok_at' => self::nullIfEmpty(SettingsService::get(self::SET_LAST_OK, '')),
            'last_error' => self::nullIfEmpty(SettingsService::get(self::SET_LAST_ERROR, '')),
            'last_file' => self::nullIfEmpty(SettingsService::get(self::SET_LAST_FILE, '')),
        ];
    }

    /**
     * @param array<string,mixed> $post
     * @return array{enabled:bool,unc:string,auth_mode:string}
     */
    public static function saveFromPost(array $post): array
    {
        $enabled = !empty($post['smb_backup_enabled']);
        $unc = self::normalizeUnc(trim((string)($post['smb_backup_unc'] ?? '')));
        $mode = strtolower(trim((string)($post['smb_backup_auth_mode'] ?? 'app_pool')));
        if (!in_array($mode, ['app_pool', 'local', 'domain'], true)) {
            $mode = 'app_pool';
        }
        $username = trim((string)($post['smb_backup_username'] ?? ''));
        $domain = trim((string)($post['smb_backup_domain'] ?? ''));
        $passwordNew = (string)($post['smb_backup_password'] ?? '');

        if ($enabled && $unc === '') {
            throw new RuntimeException('SMB UNC path is required when remote copy is enabled (e.g. \\\\fileserver\\backups\\ColdAisle).');
        }
        if ($enabled && $unc !== '' && !self::isValidUnc($unc)) {
            throw new RuntimeException('UNC path must look like \\\\server\\share or \\\\server\\share\\folder.');
        }
        if ($enabled && $mode !== 'app_pool' && $username === '') {
            throw new RuntimeException('Username is required for local or domain credentials.');
        }
        if ($enabled && $mode !== 'app_pool' && $passwordNew === '' && !self::settings()['has_password']) {
            throw new RuntimeException('Password is required for local or domain credentials (or leave blank only to keep the saved password).');
        }

        SettingsService::set(self::SET_ENABLED, $enabled ? '1' : '0', 'backup');
        SettingsService::set(self::SET_UNC, $unc, 'backup');
        SettingsService::set(self::SET_AUTH_MODE, $mode, 'backup');
        SettingsService::set(self::SET_USERNAME, $username, 'backup');
        SettingsService::set(self::SET_DOMAIN, $domain, 'backup');
        SettingsService::set(self::SET_ON_EXPORT, !empty($post['smb_backup_on_export']) ? '1' : '0', 'backup');
        SettingsService::set(self::SET_ON_UPDATE, !empty($post['smb_backup_on_update_backup']) ? '1' : '0', 'backup');

        if ($mode === 'app_pool') {
            // Clear stored secret when not using credentials
            SettingsService::set(self::SET_PASSWORD, '', 'backup');
        } elseif ($passwordNew !== '') {
            $sealed = Crypto::isAvailable() ? Crypto::encrypt($passwordNew) : $passwordNew;
            SettingsService::set(self::SET_PASSWORD, (string)$sealed, 'backup');
        }

        return self::settings();
    }

    /**
     * Copy a local zip to the configured share when enabled and the trigger matches.
     *
     * @param 'export'|'update_backup' $trigger
     * @return array{ok:bool,skipped:bool,message:string,remote:?string}
     */
    public static function maybeCopy(string $localPath, string $trigger): array
    {
        $s = self::settings();
        if (!$s['enabled'] || $s['unc'] === '') {
            return ['ok' => true, 'skipped' => true, 'message' => 'SMB backup not enabled.', 'remote' => null];
        }
        if ($trigger === 'export' && !$s['on_export']) {
            return ['ok' => true, 'skipped' => true, 'message' => 'SMB copy on site export is off.', 'remote' => null];
        }
        if ($trigger === 'update_backup' && !$s['on_update_backup']) {
            return ['ok' => true, 'skipped' => true, 'message' => 'SMB copy on pre-update backup is off.', 'remote' => null];
        }
        return self::copyFile($localPath);
    }

    /**
     * @return array{ok:bool,skipped:bool,message:string,remote:?string}
     */
    public static function copyFile(string $localPath): array
    {
        if (!is_file($localPath)) {
            return self::fail('Local backup file not found for SMB copy.');
        }
        $s = self::settings();
        if ($s['unc'] === '') {
            return self::fail('SMB UNC path is not configured.');
        }

        $name = basename($localPath);
        $remote = rtrim(str_replace('/', '\\', $s['unc']), '\\') . '\\' . $name;

        try {
            if (PHP_OS_FAMILY !== 'Windows') {
                // Best-effort for non-Windows (rare for this app)
                if (@copy($localPath, $remote)) {
                    return self::ok($remote, $name);
                }
                return self::fail('copy() to UNC failed (non-Windows host).');
            }

            if ($s['auth_mode'] === 'app_pool') {
                if (@copy($localPath, $remote)) {
                    return self::ok($remote, $name);
                }
                $err = error_get_last();
                $detail = is_array($err) ? (string)($err['message'] ?? '') : '';
                return self::fail(
                    'Could not copy to ' . $s['unc'] . ' using the IIS app pool identity.'
                    . ($detail !== '' ? ' ' . $detail : '')
                    . ' Grant the app pool share+NTFS write access, or use stored credentials.'
                );
            }

            return self::copyWithCredentials($localPath, $remote, $s);
        } catch (Throwable $e) {
            return self::fail($e->getMessage());
        }
    }

    /**
     * Probe share: map (if needed) and check directory is writable.
     *
     * @return array{ok:bool,message:string}
     */
    public static function testConnection(): array
    {
        $s = self::settings();
        if ($s['unc'] === '') {
            return ['ok' => false, 'message' => 'Enter and save an SMB UNC path first.'];
        }
        if (!self::isValidUnc($s['unc'])) {
            return ['ok' => false, 'message' => 'Invalid UNC path format.'];
        }

        $probeName = 'coldaisle_smb_probe_' . bin2hex(random_bytes(4)) . '.txt';
        $tmp = App::ROOT . '/storage/tmp/' . $probeName;
        $dir = dirname($tmp);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = 'ColdAisle SMB probe ' . gmdate('c') . "\n";
        if (@file_put_contents($tmp, $payload) === false) {
            return ['ok' => false, 'message' => 'Could not write local probe file.'];
        }

        try {
            $result = self::copyFile($tmp);
            if (!empty($result['ok']) && empty($result['skipped']) && !empty($result['remote'])) {
                // Best-effort delete probe on share
                self::tryDeleteRemote((string)$result['remote'], $s);
                @unlink($tmp);
                return [
                    'ok' => true,
                    'message' => 'Connected and wrote a test file to ' . $s['unc'] . ' (removed after test).',
                ];
            }
            @unlink($tmp);
            return [
                'ok' => false,
                'message' => $result['message'] ?? 'SMB test failed.',
            ];
        } catch (Throwable $e) {
            @unlink($tmp);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param array{unc:string,auth_mode:string,username:string,domain:string,has_password:bool} $s
     * @return array{ok:bool,skipped:bool,message:string,remote:?string}
     */
    private static function copyWithCredentials(string $localPath, string $remote, array $s): array
    {
        $password = self::passwordPlain();
        if ($password === null || $password === '') {
            return self::fail('SMB password is not set. Save credentials with a password, then try again.');
        }

        $userSpec = self::windowsUserSpec($s['auth_mode'], $s['username'], $s['domain']);
        $shareRoot = self::shareRoot($s['unc']);
        $drive = self::tempDriveLetter();
        if ($drive === null) {
            return self::fail('No free drive letter available for temporary SMB mapping.');
        }

        // Map with net use (password via stdin-like arg — avoid logging command line in App::log)
        $map = self::runNetUseMap($drive, $shareRoot, $userSpec, $password);
        if (!$map['ok']) {
            return self::fail('Could not map ' . $shareRoot . ': ' . $map['message']);
        }

        try {
            $destOnDrive = $drive . ':\\' . self::pathRelativeToShare($s['unc'], basename($localPath));
            // Ensure subfolder exists under share
            $destDir = dirname($destOnDrive);
            if ($destDir !== '' && $destDir !== $drive . ':' && !is_dir($destDir)) {
                if (!@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                    return self::fail('Could not create folder on share: ' . $destDir);
                }
            }
            if (!@copy($localPath, $destOnDrive)) {
                $err = error_get_last();
                $detail = is_array($err) ? (string)($err['message'] ?? '') : '';
                return self::fail('Copy to mapped drive failed.' . ($detail !== '' ? ' ' . $detail : ''));
            }
            return self::ok($remote, basename($localPath));
        } finally {
            self::runNetUseDelete($drive);
        }
    }

    private static function tryDeleteRemote(string $remotePath, array $s): void
    {
        try {
            if ($s['auth_mode'] === 'app_pool') {
                @unlink($remotePath);
                return;
            }
            // Best-effort with mapping
            $password = self::passwordPlain();
            if ($password === null || $password === '') {
                return;
            }
            $shareRoot = self::shareRoot($s['unc']);
            $drive = self::tempDriveLetter();
            if ($drive === null) {
                return;
            }
            $userSpec = self::windowsUserSpec($s['auth_mode'], $s['username'], $s['domain']);
            $map = self::runNetUseMap($drive, $shareRoot, $userSpec, $password);
            if (!$map['ok']) {
                return;
            }
            try {
                $rel = self::pathRelativeToShare($s['unc'], basename($remotePath));
                @unlink($drive . ':\\' . $rel);
            } finally {
                self::runNetUseDelete($drive);
            }
        } catch (Throwable $e) {
            // ignore probe cleanup
        }
    }

    /** @return array{ok:bool,message:string} */
    private static function runNetUseMap(string $drive, string $shareRoot, string $userSpec, string $password): array
    {
        // net use Z: \\server\share /user:DOMAIN\user *
        // Pass password as separate argument (not written to our app log)
        $cmd = [
            'net',
            'use',
            $drive . ':',
            $shareRoot,
            '/user:' . $userSpec,
            $password,
            '/persistent:no',
        ];
        $r = self::runCommand($cmd, true);
        if ($r['code'] !== 0) {
            $msg = trim($r['stderr'] . ' ' . $r['stdout']);
            // Strip accidental password echoes (defensive)
            $msg = str_replace($password, '***', $msg);
            return ['ok' => false, 'message' => $msg !== '' ? $msg : 'net use failed (code ' . $r['code'] . ')'];
        }
        return ['ok' => true, 'message' => 'mapped'];
    }

    private static function runNetUseDelete(string $drive): void
    {
        self::runCommand(['net', 'use', $drive . ':', '/delete', '/y'], true);
    }

    /**
     * @param list<string> $cmd
     * @return array{code:int,stdout:string,stderr:string}
     */
    private static function runCommand(array $cmd, bool $hideWindow): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $options = [];
        if ($hideWindow && PHP_OS_FAMILY === 'Windows') {
            $options['bypass_shell'] = true;
        }
        $proc = @proc_open($cmd, $descriptors, $pipes, null, null, $options);
        if (!is_resource($proc)) {
            // Fallback: escaped command line
            $line = self::escapeCmd($cmd);
            $stdout = [];
            $code = 0;
            @exec($line . ' 2>&1', $stdout, $code);
            return ['code' => $code, 'stdout' => implode("\n", $stdout), 'stderr' => ''];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param list<string> $cmd */
    private static function escapeCmd(array $cmd): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return implode(' ', array_map(static function (string $p): string {
                if ($p === '') {
                    return '""';
                }
                if (preg_match('/[\s"]/', $p)) {
                    return '"' . str_replace('"', '""', $p) . '"';
                }
                return $p;
            }, $cmd));
        }
        return implode(' ', array_map('escapeshellarg', $cmd));
    }

    private static function windowsUserSpec(string $mode, string $username, string $domain): string
    {
        $username = trim($username);
        $domain = trim($domain);
        if (str_contains($username, '\\') || str_contains($username, '@')) {
            return $username;
        }
        if ($mode === 'local') {
            return '.\\' . $username;
        }
        if ($domain !== '') {
            return $domain . '\\' . $username;
        }
        return $username;
    }

    private static function passwordPlain(): ?string
    {
        $stored = (string)SettingsService::get(self::SET_PASSWORD, '');
        if ($stored === '') {
            return null;
        }
        return Crypto::decryptQuiet($stored);
    }

    public static function normalizeUnc(string $unc): string
    {
        $unc = trim($unc);
        $unc = str_replace('/', '\\', $unc);
        // Allow users pasting single leading backslash pairs
        if (preg_match('#^[/\\\\]{2}#', $unc)) {
            $unc = '\\\\' . ltrim($unc, '\\/');
        }
        return rtrim($unc, '\\');
    }

    public static function isValidUnc(string $unc): bool
    {
        // \\server\share or \\server\share\path
        return (bool)preg_match('#^\\\\[^\\\\/]+\\[^\\\\/]+#', $unc);
    }

    /** \\server\share\folder → \\server\share */
    private static function shareRoot(string $unc): string
    {
        $unc = self::normalizeUnc($unc);
        if (preg_match('#^(\\\\[^\\\\]+\\[^\\\\]+)#', $unc, $m)) {
            return $m[1];
        }
        return $unc;
    }

    /** Path under mapped drive for full UNC target + filename */
    private static function pathRelativeToShare(string $unc, string $fileName): string
    {
        $unc = self::normalizeUnc($unc);
        $root = self::shareRoot($unc);
        $sub = trim(substr($unc, strlen($root)), '\\');
        if ($sub === '') {
            return $fileName;
        }
        return $sub . '\\' . $fileName;
    }

    private static function tempDriveLetter(): ?string
    {
        foreach (range('Z', 'H') as $letter) {
            if (!is_dir($letter . ':\\')) {
                // Not foolproof but good enough for temporary net use
                return $letter;
            }
        }
        return null;
    }

    /** @return array{ok:bool,skipped:bool,message:string,remote:?string} */
    private static function ok(string $remote, string $name): array
    {
        $at = gmdate('c');
        SettingsService::set(self::SET_LAST_OK, $at, 'backup');
        SettingsService::set(self::SET_LAST_ERROR, '', 'backup');
        SettingsService::set(self::SET_LAST_FILE, $name, 'backup');
        return [
            'ok' => true,
            'skipped' => false,
            'message' => 'Copied to SMB: ' . $remote,
            'remote' => $remote,
        ];
    }

    /** @return array{ok:bool,skipped:bool,message:string,remote:?string} */
    private static function fail(string $message): array
    {
        SettingsService::set(self::SET_LAST_ERROR, $message, 'backup');
        App::log('SMB backup: ' . $message, 'warning');
        return [
            'ok' => false,
            'skipped' => false,
            'message' => $message,
            'remote' => null,
        ];
    }

    private static function nullIfEmpty(mixed $v): ?string
    {
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }
}
