<?php
/**
 * SNMP poll age labels / badges (shared across lists, SNMP page, NOC).
 */
declare(strict_types=1);

/** Warn when last poll is older than this many seconds. */
function snmp_poll_age_warn_sec(): int
{
    return 3600; // 1 hour
}

/** Danger when last poll is older than this many seconds. */
function snmp_poll_age_danger_sec(): int
{
    return 14400; // 4 hours
}

/**
 * Seconds since $ts (site/local datetime string), or null if unparsable/empty.
 */
function snmp_poll_age_seconds(?string $ts): ?int
{
    $ts = trim((string)$ts);
    if ($ts === '') {
        return null;
    }
    $t = strtotime($ts);
    if ($t === false) {
        return null;
    }
    return max(0, time() - $t);
}

/**
 * Human label: "12m ago", "3h ago", "2d ago", "Never", "just now".
 */
function snmp_poll_age_label(?string $ts): string
{
    $sec = snmp_poll_age_seconds($ts);
    if ($sec === null) {
        return 'Never';
    }
    if ($sec < 45) {
        return 'just now';
    }
    if ($sec < 3600) {
        $m = (int)floor($sec / 60);
        return $m <= 1 ? '1m ago' : $m . 'm ago';
    }
    if ($sec < 86400) {
        $h = (int)floor($sec / 3600);
        return $h <= 1 ? '1h ago' : $h . 'h ago';
    }
    $d = (int)floor($sec / 86400);
    return $d <= 1 ? '1d ago' : $d . 'd ago';
}

/**
 * CSS modifier: snmp-age-ok | snmp-age-warn | snmp-age-danger | snmp-age-never
 * When $expectPoll is false (SNMP off), returns snmp-age-na.
 */
function snmp_poll_age_class(?string $ts, bool $expectPoll = true): string
{
    if (!$expectPoll) {
        return 'snmp-age-na';
    }
    $sec = snmp_poll_age_seconds($ts);
    if ($sec === null) {
        return 'snmp-age-never';
    }
    if ($sec >= snmp_poll_age_danger_sec()) {
        return 'snmp-age-danger';
    }
    if ($sec >= snmp_poll_age_warn_sec()) {
        return 'snmp-age-warn';
    }
    return 'snmp-age-ok';
}

/**
 * True when last poll is missing or older than warn threshold (for counters).
 */
function snmp_poll_is_stale(?string $ts, ?int $warnSec = null): bool
{
    $warn = $warnSec ?? snmp_poll_age_warn_sec();
    $sec = snmp_poll_age_seconds($ts);
    if ($sec === null) {
        return true;
    }
    return $sec >= $warn;
}

/**
 * Escaped HTML span for list cells.
 *
 * @param string|null $rawTs optional absolute time for title attribute
 */
function snmp_poll_age_html(?string $ts, bool $expectPoll = true, ?string $rawTs = null): string
{
    $label = snmp_poll_age_label($ts);
    $cls = snmp_poll_age_class($ts, $expectPoll);
    $title = $rawTs !== null && $rawTs !== ''
        ? $rawTs
        : ($ts !== null && trim((string)$ts) !== '' ? (string)$ts : 'No successful poll yet');
    if (!$expectPoll) {
        $label = '—';
        $title = 'SNMP not enabled / not scheduled';
    }
    $e = class_exists('App') ? [App::class, 'e'] : static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<span class="snmp-age ' . $e($cls) . '" title="' . $e($title) . '">' . $e($label) . '</span>';
}
