<?php
/**
 * App-level SNMP schedule policy (enable, interval, heartbeat).
 * Windows Task Scheduler only runs scripts/poll_snmp.php on a fixed tick;
 * this service decides whether work runs and whether the feature looks "active".
 */
declare(strict_types=1);

class SnmpSchedulerService
{
    public const SETTING_ENABLED = 'snmp_scheduler_enabled';
    public const SETTING_INTERVAL = 'snmp_scheduler_interval_sec';
    public const SETTING_LAST_RUN = 'snmp_scheduler_last_run_at';
    public const SETTING_LAST_RESULT = 'snmp_scheduler_last_result';
    public const SETTING_LAST_OK = 'snmp_scheduler_last_ok';
    public const SETTING_LAST_FAIL = 'snmp_scheduler_last_fail';

    public const MIN_INTERVAL_SEC = 60;
    public const MAX_INTERVAL_SEC = 86400;
    public const DEFAULT_INTERVAL_SEC = 300;

    /** @return array{
     *   enabled:bool,
     *   interval_sec:int,
     *   last_run_at:?string,
     *   last_result:?string,
     *   last_ok:int,
     *   last_fail:int,
     *   active:bool,
     *   status:string,
     *   status_label:string,
     *   status_detail:string
     * } */
    public static function status(): array
    {
        $enabled = self::isEnabled();
        $interval = self::intervalSec();
        $lastRun = SettingsService::get(self::SETTING_LAST_RUN, null);
        $lastRun = is_string($lastRun) && $lastRun !== '' ? $lastRun : null;
        $lastResult = SettingsService::get(self::SETTING_LAST_RESULT, null);
        $lastResult = is_string($lastResult) && $lastResult !== '' ? $lastResult : null;
        $lastOk = (int)SettingsService::get(self::SETTING_LAST_OK, '0');
        $lastFail = (int)SettingsService::get(self::SETTING_LAST_FAIL, '0');

        $active = false;
        $status = 'off';
        $label = 'Off';
        $detail = 'Scheduled SNMP polling is disabled in Settings.';

        if ($enabled) {
            if ($lastRun === null) {
                $status = 'pending_task';
                $label = 'Waiting for Windows task';
                $detail = 'Enabled in ColdAisle, but the poll worker has not run yet. '
                    . 'Download and run the registration script (elevated) on the app server.';
            } else {
                $ts = strtotime($lastRun);
                $staleAfter = max($interval * 2, 600);
                if ($ts !== false && (time() - $ts) <= $staleAfter) {
                    $active = true;
                    $status = 'active';
                    $label = 'Active';
                    $detail = 'Worker last ran ' . $lastRun
                        . ($lastResult ? (' · ' . $lastResult) : '') . '.';
                } else {
                    $status = 'stale';
                    $label = 'Task not running';
                    $detail = 'Enabled, but last worker run was '
                        . ($lastRun ?: 'never')
                        . ' (stale). Check Task Scheduler / run the registration script again.';
                }
            }
        }

        return [
            'enabled' => $enabled,
            'interval_sec' => $interval,
            'last_run_at' => $lastRun,
            'last_result' => $lastResult,
            'last_ok' => $lastOk,
            'last_fail' => $lastFail,
            'active' => $active,
            'status' => $status,
            'status_label' => $label,
            'status_detail' => $detail,
        ];
    }

    public static function isEnabled(): bool
    {
        return SettingsService::get(self::SETTING_ENABLED, '0') === '1';
    }

    public static function intervalSec(): int
    {
        $n = (int)SettingsService::get(self::SETTING_INTERVAL, (string)self::DEFAULT_INTERVAL_SEC);
        return max(self::MIN_INTERVAL_SEC, min(self::MAX_INTERVAL_SEC, $n > 0 ? $n : self::DEFAULT_INTERVAL_SEC));
    }

    /**
     * @return array{enabled:bool,interval_sec:int}
     */
    public static function saveFromPost(array $post): array
    {
        $enabled = !empty($post['snmp_scheduler_enabled']);
        $interval = (int)($post['snmp_scheduler_interval_sec'] ?? self::DEFAULT_INTERVAL_SEC);
        $interval = max(self::MIN_INTERVAL_SEC, min(self::MAX_INTERVAL_SEC, $interval));
        SettingsService::set(self::SETTING_ENABLED, $enabled ? '1' : '0', 'snmp');
        SettingsService::set(self::SETTING_INTERVAL, (string)$interval, 'snmp');
        return ['enabled' => $enabled, 'interval_sec' => $interval];
    }

    public static function recordRun(int $ok, int $failed, string $summary): void
    {
        SettingsService::set(self::SETTING_LAST_RUN, date('Y-m-d H:i:s'), 'snmp');
        SettingsService::set(self::SETTING_LAST_OK, (string)max(0, $ok), 'snmp');
        SettingsService::set(self::SETTING_LAST_FAIL, (string)max(0, $failed), 'snmp');
        SettingsService::set(self::SETTING_LAST_RESULT, substr($summary, 0, 500), 'snmp');
    }

    /** Whether a target is due based on last success timestamp. */
    public static function isDue(?string $lastPollAt, ?int $intervalSec = null): bool
    {
        $interval = $intervalSec !== null
            ? max(self::MIN_INTERVAL_SEC, min(self::MAX_INTERVAL_SEC, $intervalSec))
            : self::intervalSec();
        if ($lastPollAt === null || trim($lastPollAt) === '') {
            return true;
        }
        $ts = strtotime($lastPollAt);
        if ($ts === false) {
            return true;
        }
        return (time() - $ts) >= $interval;
    }

    public static function scriptPath(): string
    {
        return App::ROOT . '/scripts/Register-ColdAisle-SnmpPollTask.ps1';
    }

    /**
     * Render the registration script with site-specific defaults substituted.
     */
    public static function renderedRegistrationScript(): string
    {
        $path = self::scriptPath();
        if (!is_file($path)) {
            throw new RuntimeException('Register-ColdAisle-SnmpPollTask.ps1 is missing from scripts/.');
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Could not read registration script.');
        }
        $root = realpath(App::ROOT) ?: App::ROOT;
        // App::ROOT is src/.. — normalize to real site path without \src\..
        $siteRoot = str_replace('/', '\\', $root);
        $phpGuess = 'C:\\PHP\\php.exe';
        foreach (['C:\\PHP\\php.exe', 'C:\\php\\php.exe'] as $c) {
            if (is_file($c)) {
                $phpGuess = $c;
                break;
            }
        }
        // Replace placeholder tokens in the template
        $out = str_replace(
            [
                '__COLDAISLE_SITE_ROOT__',
                '__COLDAISLE_PHP_EXE__',
                '__COLDAISLE_INTERVAL_HINT__',
            ],
            [
                $siteRoot,
                $phpGuess,
                (string)self::intervalSec(),
            ],
            $raw
        );
        return $out;
    }
}
