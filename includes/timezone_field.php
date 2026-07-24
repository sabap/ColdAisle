<?php
/**
 * Shared searchable timezone combobox (Settings, Sites, setup, …).
 *
 * Usage:
 *   require_once .../includes/timezone_field.php;
 *   coldaisle_render_timezone_field(['name' => 'timezone', 'value' => 'UTC']);
 *
 * Front-end: assets/js/app.js binds [data-tz-combobox] on DOMContentLoaded.
 */
declare(strict_types=1);

/**
 * PHP timezone identifiers (cached per request).
 *
 * @return list<string>
 */
function coldaisle_timezone_list(): array
{
    static $list = null;
    if ($list === null) {
        $list = timezone_identifiers_list();
    }
    return $list;
}

/**
 * Emit the timezone JSON list once per request (for app.js).
 */
function coldaisle_timezone_data_script(): void
{
    static $emitted = false;
    if ($emitted) {
        return;
    }
    $emitted = true;
    $list = coldaisle_timezone_list();
    echo '<script type="application/json" id="ca_timezone_data">'
        . json_encode(array_values($list), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "</script>\n";
}

/**
 * Render a searchable timezone field.
 *
 * @param array{
 *   name?: string,
 *   value?: string|null,
 *   id?: string,
 *   label?: string|null,
 *   required?: bool,
 *   full?: bool,
 *   hint?: string|null,
 *   form_row?: bool,
 *   input_class?: string,
 *   placeholder?: string
 * } $opts
 */
function coldaisle_render_timezone_field(array $opts = []): void
{
    static $uid = 0;
    $uid++;

    $name = (string)($opts['name'] ?? 'timezone');
    $value = trim((string)($opts['value'] ?? 'UTC'));
    if ($value === '') {
        $value = 'UTC';
    }
    $required = !empty($opts['required']);
    $formRow = !array_key_exists('form_row', $opts) || !empty($opts['form_row']);
    $full = !empty($opts['full']);
    $label = array_key_exists('label', $opts) ? $opts['label'] : 'Timezone';
    $hint = $opts['hint'] ?? 'Type to filter (e.g. New, Chicago, UTC). Click a match or press Enter.';
    $placeholder = (string)($opts['placeholder'] ?? 'Search timezones (e.g. New, Chicago, UTC)…');
    $inputClass = (string)($opts['input_class'] ?? 'form-control');

    $inputId = (string)($opts['id'] ?? ('timezone_input_' . $uid));
    $listId = 'timezone_list_' . $uid;
    $boxId = 'tz_combobox_' . $uid;

    // Keep a custom/legacy value selectable if it is not in the PHP list
    // (list itself is global; the input still accepts the current value).
    $esc = static function (string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    coldaisle_timezone_data_script();

    if ($formRow) {
        $rowClass = 'form-row' . ($full ? ' full' : '');
        echo '<div class="' . $esc($rowClass) . '">';
        if ($label !== null && $label !== '') {
            echo '<label for="' . $esc($inputId) . '">' . $esc((string)$label)
                . ($required ? ' *' : '') . '</label>';
        }
    }

    echo '<div class="tz-combobox" id="' . $esc($boxId) . '" data-tz-combobox>';
    echo '<input class="' . $esc($inputClass) . '" type="text" name="' . $esc($name) . '" id="' . $esc($inputId) . '"'
        . ' value="' . $esc($value) . '"'
        . ' autocomplete="off" spellcheck="false"'
        . ' role="combobox" aria-autocomplete="list"'
        . ' aria-expanded="false" aria-controls="' . $esc($listId) . '"'
        . ' placeholder="' . $esc($placeholder) . '"'
        . ($required ? ' required' : '')
        . '>';
    echo '<ul class="tz-combobox-list" id="' . $esc($listId) . '" role="listbox" hidden></ul>';
    echo '</div>';

    if ($hint !== null && $hint !== '') {
        echo '<p class="text-muted" style="font-size:.75rem;margin:.3rem 0 0">'
            . htmlspecialchars((string)$hint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p>';
    }

    if ($formRow) {
        echo '</div>';
    }
}

/**
 * Normalize and validate a timezone string from POST (throws on invalid).
 */
function coldaisle_normalize_timezone(?string $tzIn, string $default = 'UTC'): string
{
    $tzIn = trim((string)$tzIn);
    if ($tzIn === '') {
        $tzIn = $default;
    }
    try {
        new DateTimeZone($tzIn);
        return $tzIn;
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Invalid timezone “' . $tzIn . '”. Choose a value from the list (e.g. America/New_York).'
        );
    }
}
