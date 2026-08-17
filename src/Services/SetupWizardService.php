<?php
/**
 * First-login / on-demand setup wizard.
 *
 * Linear, skippable steps with persisted progress (settings.setup_wizard_state).
 * Fresh installs start pending; restores and existing sites stay quiet.
 */
declare(strict_types=1);

class SetupWizardService
{
    public const SETTING_KEY = 'setup_wizard_state';
    public const STATE_VERSION = 1;

    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Ordered steps. Optional steps can be skipped without blocking later ones.
     *
     * @return list<array{id:string,title:string,skippable:bool,skip_label:string}>
     */
    public static function catalog(): array
    {
        return [
            [
                'id' => 'welcome',
                'title' => 'Welcome',
                'skippable' => false,
                'skip_label' => '',
            ],
            [
                'id' => 'organization',
                'title' => 'Organization',
                'skippable' => false,
                'skip_label' => '',
            ],
            [
                'id' => 'security',
                'title' => 'Security',
                'skippable' => true,
                'skip_label' => 'Keep defaults',
            ],
            [
                'id' => 'directory',
                'title' => 'Directory sign-in',
                'skippable' => true,
                'skip_label' => 'Local accounts only',
            ],
            [
                'id' => 'mail',
                'title' => 'Email',
                'skippable' => true,
                'skip_label' => 'Skip email for now',
            ],
            [
                'id' => 'site',
                'title' => 'Site',
                'skippable' => false,
                'skip_label' => '',
            ],
            [
                'id' => 'floor',
                'title' => 'Data hall',
                'skippable' => false,
                'skip_label' => '',
            ],
            [
                'id' => 'modules',
                'title' => 'What you track',
                'skippable' => false,
                'skip_label' => '',
            ],
            [
                'id' => 'updates',
                'title' => 'Updates',
                'skippable' => true,
                'skip_label' => 'Decide later',
            ],
            [
                'id' => 'finish',
                'title' => 'Ready',
                'skippable' => false,
                'skip_label' => '',
            ],
        ];
    }

    /** @return list<string> */
    public static function stepIds(): array
    {
        return array_column(self::catalog(), 'id');
    }

    public static function defaultState(): array
    {
        return [
            'version' => self::STATE_VERSION,
            'status' => self::STATUS_PENDING,
            'current_step' => 'welcome',
            'completed_steps' => [],
            'skipped_steps' => [],
            'data' => [],
            'updated_at' => date('c'),
            'started_at' => null,
            'completed_at' => null,
            'launched_from' => 'auto',
        ];
    }

    public static function loadState(): array
    {
        $raw = class_exists('SettingsService') ? SettingsService::get(self::SETTING_KEY, null) : null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_merge(self::defaultState(), $decoded);
    }

    public static function saveState(array $state): void
    {
        $state['version'] = self::STATE_VERSION;
        $state['updated_at'] = date('c');
        SettingsService::set(self::SETTING_KEY, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'wizard');
    }

    public static function hasState(): bool
    {
        return self::loadState() !== [];
    }

    /** Fresh install: show wizard on first admin login. */
    public static function markPending(string $from = 'install'): void
    {
        $state = self::defaultState();
        $state['status'] = self::STATUS_PENDING;
        $state['current_step'] = 'welcome';
        $state['launched_from'] = $from;
        self::saveState($state);
    }

    public static function markCompleted(string $reason = 'finished'): void
    {
        $state = self::loadState() ?: self::defaultState();
        $state['status'] = self::STATUS_COMPLETED;
        $state['current_step'] = 'finish';
        $state['completed_at'] = date('c');
        $state['data']['complete_reason'] = $reason;
        if (!in_array('finish', $state['completed_steps'], true)) {
            $state['completed_steps'][] = 'finish';
        }
        self::saveState($state);
    }

    public static function markSkipped(string $reason = 'user'): void
    {
        $state = self::loadState() ?: self::defaultState();
        $state['status'] = self::STATUS_SKIPPED;
        $state['completed_at'] = date('c');
        $state['data']['skip_reason'] = $reason;
        self::saveState($state);
    }

    /**
     * Auto-open only for Global Admins on a pending/in-progress wizard.
     * Missing state = existing install (do not surprise operators).
     */
    public static function shouldAutoOpen(array $user): bool
    {
        if (!AuthManager::can($user, 'manage_settings')) {
            return false;
        }
        if (class_exists('TechMode') && TechMode::isActive()) {
            return false;
        }
        if (!empty($_GET['nowizard'])) {
            return false;
        }
        $state = self::loadState();
        if ($state === []) {
            return false;
        }
        $status = (string)($state['status'] ?? '');
        return in_array($status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS], true);
    }

    /**
     * Inventory / config already present — warn before re-running from Settings.
     *
     * @return array{warn:bool,message:string,counts:array<string,int>}
     */
    public static function riskAssessment(): array
    {
        $counts = [
            'sites' => self::safeCount('sites'),
            'datacenters' => self::safeCount('datacenters'),
            'rooms' => self::safeCount('rooms'),
            'cabinets' => self::safeCount('cabinets'),
            'devices' => self::safeCount('devices'),
            'pdus' => self::safeCount('pdus'),
        ];
        $state = self::loadState();
        $status = (string)($state['status'] ?? '');
        $hasInventory = ($counts['cabinets'] + $counts['devices'] + $counts['pdus']) > 0;
        $configured = in_array($status, [self::STATUS_COMPLETED, self::STATUS_SKIPPED], true)
            || $hasInventory
            || $counts['sites'] > 1;
        $msg = 'The wizard can overwrite organization, security, directory, email, site, and hall settings. '
            . 'Cabinets, devices, and live telemetry are not deleted.';
        if ($hasInventory) {
            $msg = 'This site already has inventory ('
                . $counts['cabinets'] . ' cabinets, '
                . $counts['devices'] . ' devices, '
                . $counts['pdus'] . ' PDUs). '
                . $msg;
        } elseif ($configured) {
            $msg = 'Settings have already been configured. ' . $msg;
        }
        return [
            'warn' => $configured,
            'message' => $msg,
            'counts' => $counts,
        ];
    }

    public static function launchFromSettings(): array
    {
        $state = self::loadState() ?: self::defaultState();
        if (($state['status'] ?? '') === self::STATUS_SKIPPED
            || ($state['status'] ?? '') === self::STATUS_COMPLETED
            || ($state['status'] ?? '') === '') {
            $state['status'] = self::STATUS_IN_PROGRESS;
            $state['current_step'] = 'welcome';
        }
        $state['launched_from'] = 'settings';
        $state['started_at'] = $state['started_at'] ?? date('c');
        self::saveState($state);
        return $state;
    }

    /**
     * Full payload for the wizard UI.
     *
     * @return array<string,mixed>
     */
    public static function payload(?string $stepId = null): array
    {
        $state = self::loadState() ?: self::defaultState();
        $ids = self::stepIds();
        $currentId = $stepId ?: (string)($state['current_step'] ?? 'welcome');
        if (!in_array($currentId, $ids, true)) {
            $currentId = 'welcome';
        }
        $cfg = App::config();
        $idx = array_search($currentId, $ids, true);
        $idx = $idx === false ? 0 : (int)$idx;

        return [
            'ok' => true,
            'state' => [
                'status' => $state['status'] ?? self::STATUS_PENDING,
                'current_step' => $currentId,
                'completed_steps' => array_values($state['completed_steps'] ?? []),
                'skipped_steps' => array_values($state['skipped_steps'] ?? []),
                'updated_at' => $state['updated_at'] ?? null,
            ],
            'steps' => array_map(static function (array $s) use ($state, $ids, $idx): array {
                $sid = $s['id'];
                $sidx = array_search($sid, $ids, true);
                $done = in_array($sid, $state['completed_steps'] ?? [], true);
                $skipped = in_array($sid, $state['skipped_steps'] ?? [], true);
                return [
                    'id' => $sid,
                    'title' => $s['title'],
                    'skippable' => !empty($s['skippable']),
                    'skip_label' => $s['skip_label'] ?? 'Skip',
                    'done' => $done,
                    'skipped' => $skipped,
                    'index' => (int)$sidx,
                    'current' => $sidx === $idx,
                ];
            }, self::catalog()),
            'step' => self::stepView($currentId, $state, $cfg),
            'progress' => [
                'index' => $idx + 1,
                'total' => count($ids),
            ],
            'risk' => self::riskAssessment(),
            'flags' => [
                'ldap_extension' => function_exists('ldap_connect'),
                'https' => App::isHttps(),
                'https_mismatch' => App::httpsConfigMismatch(),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,error:?string,state:array,payload:array}
     */
    public static function advance(string $action, string $stepId, array $input, array $user): array
    {
        $ids = self::stepIds();
        if (!in_array($stepId, $ids, true)) {
            return self::fail('Unknown step.');
        }
        $state = self::loadState() ?: self::defaultState();
        if (($state['status'] ?? '') === self::STATUS_PENDING) {
            $state['status'] = self::STATUS_IN_PROGRESS;
            $state['started_at'] = $state['started_at'] ?? date('c');
        }

        if ($action === 'skip_wizard') {
            self::markSkipped('user');
            return [
                'ok' => true,
                'error' => null,
                'state' => self::loadState(),
                'payload' => self::payload('welcome'),
                'closed' => true,
            ];
        }

        if ($action === 'prev') {
            self::stashDraft($state, $stepId, $input);
            $idx = (int)array_search($stepId, $ids, true);
            $prev = $ids[max(0, $idx - 1)];
            $state['current_step'] = $prev;
            self::saveState($state);
            return ['ok' => true, 'error' => null, 'state' => $state, 'payload' => self::payload($prev)];
        }

        if ($action === 'save') {
            self::stashDraft($state, $stepId, $input);
            self::saveState($state);
            return ['ok' => true, 'error' => null, 'state' => $state, 'payload' => self::payload($stepId)];
        }

        if ($action === 'skip_step') {
            $meta = self::stepMeta($stepId);
            if (empty($meta['skippable'])) {
                return self::fail('This step cannot be skipped.');
            }
            if (!in_array($stepId, $state['skipped_steps'], true)) {
                $state['skipped_steps'][] = $stepId;
            }
            $state['completed_steps'] = array_values(array_filter(
                $state['completed_steps'],
                static fn($s) => $s !== $stepId
            ));
            $next = self::nextId($stepId);
            $state['current_step'] = $next;
            self::saveState($state);
            return ['ok' => true, 'error' => null, 'state' => $state, 'payload' => self::payload($next)];
        }

        if ($action === 'complete' || ($action === 'next' && $stepId === 'finish')) {
            $applied = self::applyStep('finish', $input, $state, $user);
            if (!$applied['ok']) {
                return self::fail((string)$applied['error']);
            }
            self::markCompleted('wizard');
            $goto = self::safeGoto((string)($input['goto'] ?? 'index.php'));
            return [
                'ok' => true,
                'error' => null,
                'state' => self::loadState(),
                'payload' => self::payload('finish'),
                'closed' => true,
                'redirect' => $goto,
            ];
        }

        if ($action === 'next') {
            $applied = self::applyStep($stepId, $input, $state, $user);
            if (!$applied['ok']) {
                return self::fail((string)$applied['error']);
            }
            $state = self::loadState() ?: $state;
            self::stashDraft($state, $stepId, $input);
            if (!in_array($stepId, $state['completed_steps'], true)) {
                $state['completed_steps'][] = $stepId;
            }
            $state['skipped_steps'] = array_values(array_filter(
                $state['skipped_steps'],
                static fn($s) => $s !== $stepId
            ));
            $next = self::nextId($stepId);
            $state['current_step'] = $next;
            $state['status'] = self::STATUS_IN_PROGRESS;
            self::saveState($state);
            return ['ok' => true, 'error' => null, 'state' => $state, 'payload' => self::payload($next)];
        }

        return self::fail('Unknown action.');
    }

    /**
     * Run an in-wizard test without advancing.
     *
     * @param array<string,mixed> $input
     * @return array{ok:bool,summary:string,steps:list<array<string,mixed>>}
     */
    public static function runTest(string $testId, array $input): array
    {
        return match ($testId) {
            'ldap' => self::testLdap($input),
            'mail' => self::testMail($input),
            'https' => self::testHttps(),
            'floor' => self::testFloor($input),
            default => ['ok' => false, 'summary' => 'Unknown test.', 'steps' => []],
        };
    }

    /** @return array{id:string,title:string,skippable:bool,skip_label:string} */
    private static function stepMeta(string $id): array
    {
        foreach (self::catalog() as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }
        return ['id' => $id, 'title' => $id, 'skippable' => false, 'skip_label' => ''];
    }

    private static function nextId(string $id): string
    {
        $ids = self::stepIds();
        $idx = array_search($id, $ids, true);
        if ($idx === false || $idx >= count($ids) - 1) {
            return 'finish';
        }
        return $ids[(int)$idx + 1];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $input
     */
    private static function stashDraft(array &$state, string $stepId, array $input): void
    {
        if (!isset($state['data']) || !is_array($state['data'])) {
            $state['data'] = [];
        }
        $clean = $input;
        foreach (['ldaps_bind_password', 'ldaps_test_password', 'mail_password'] as $secret) {
            if (isset($clean[$secret]) && $clean[$secret] === '') {
                unset($clean[$secret]);
            }
        }
        if ($stepId === 'floor') {
            unset($clean['floor_width'], $clean['floor_depth'], $clean['floor_width_m_orig'], $clean['floor_depth_m_orig']);
        }
        $state['data'][$stepId] = array_merge(
            is_array($state['data'][$stepId] ?? null) ? $state['data'][$stepId] : [],
            $clean
        );
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    private static function stepView(string $id, array $state, array $cfg): array
    {
        $meta = self::stepMeta($id);
        $draft = is_array($state['data'][$id] ?? null) ? $state['data'][$id] : [];
        $values = self::stepValues($id, $draft, $cfg);
        $guides = [];
        $tests = [];
        $fields = [];
        $kicker = '';
        $blurb = '';

        switch ($id) {
            case 'welcome':
                $kicker = 'About 5 minutes';
                $blurb = 'A short path to a usable site: identity, optional directory and email, then your first hall. Skip anything you will not use. Progress is saved as you go.';
                break;

            case 'organization':
                $kicker = 'No dependencies';
                $blurb = 'How ColdAisle labels this install. You can change these later in Settings.';
                $fields = [
                    ['name' => 'org_name', 'type' => 'text', 'label' => 'Organization name', 'required' => true],
                    ['name' => 'timezone', 'type' => 'timezone', 'label' => 'Timezone', 'required' => true],
                    ['name' => 'length_units', 'type' => 'select', 'label' => 'Length units (floor plan)', 'hint' => 'The data-hall step asks for size in this unit. You can change it again there.', 'options' => [
                        ['value' => 'imperial', 'label' => 'Feet (ft)'],
                        ['value' => 'metric', 'label' => 'Meters (m)'],
                    ]],
                    ['name' => 'temp_unit', 'type' => 'select', 'label' => 'Temperature', 'options' => [
                        ['value' => 'C', 'label' => 'Celsius (°C)'],
                        ['value' => 'F', 'label' => 'Fahrenheit (°F)'],
                    ]],
                ];
                break;

            case 'security':
                $kicker = 'Optional';
                $blurb = 'Defaults are safe for a lab or internal HTTP site. Turn on HTTPS redirect only after the site already loads over HTTPS.';
                $fields = [
                    ['name' => 'force_https', 'type' => 'checkbox', 'label' => 'Redirect HTTP to HTTPS'],
                    ['name' => 'session_idle_minutes', 'type' => 'number', 'label' => 'Idle timeout (minutes)', 'min' => 15, 'max' => 1440],
                ];
                $guides = [[
                    'title' => 'When to enable Force HTTPS',
                    'body' => 'IIS needs a TLS certificate and an HTTPS binding first. If you enable this on HTTP-only, the browser will loop or hang. Test HTTPS in a new tab, then come back and check the box.',
                ]];
                $tests = [['id' => 'https', 'label' => 'Check this connection']];
                break;

            case 'directory':
                $kicker = 'Optional';
                $blurb = 'Local admin already works. Add LDAPS if people should sign in with Active Directory.';
                $fields = [
                    ['name' => 'ldaps_enabled', 'type' => 'checkbox', 'label' => 'Enable LDAPS'],
                    ['name' => 'ldaps_host', 'type' => 'text', 'label' => 'Host', 'placeholder' => 'ldaps.example.com'],
                    ['name' => 'ldaps_port', 'type' => 'number', 'label' => 'Port', 'min' => 1, 'max' => 65535],
                    ['name' => 'ldaps_base_dn', 'type' => 'text', 'label' => 'Base DN', 'placeholder' => 'DC=example,DC=com'],
                    ['name' => 'ldaps_bind_dn', 'type' => 'text', 'label' => 'Bind DN (service account)'],
                    ['name' => 'ldaps_bind_password', 'type' => 'password', 'label' => 'Bind password', 'placeholder' => 'Leave blank to keep saved'],
                    ['name' => 'ldaps_tls_insecure', 'type' => 'checkbox', 'label' => 'Skip TLS certificate verify (lab only)'],
                    ['name' => 'ldaps_test_username', 'type' => 'text', 'label' => 'Test username (optional)'],
                    ['name' => 'ldaps_test_password', 'type' => 'password', 'label' => 'Test password (optional)'],
                ];
                $guides = [[
                    'title' => 'LDAPS prerequisites',
                    'body' => "1. PHP ldap extension loaded (php.ini → extension=ldap).\n"
                        . "2. TCP 636 (or 389 + STARTTLS) open from this IIS host to a DC.\n"
                        . "3. Service account that can bind and search the user OU.\n"
                        . "4. Base DN is the search root (often the domain DN).\n"
                        . "5. User filter default is (sAMAccountName={username}).\n"
                        . "6. Prefer a real CA or internal CA; use skip-verify only in a lab.",
                ]];
                $tests = [['id' => 'ldap', 'label' => 'Test directory']];
                break;

            case 'mail':
                $kicker = 'Optional';
                $blurb = 'Used for alerts and test messages. Skip if you will not email from ColdAisle yet.';
                $fields = [
                    ['name' => 'mail_enabled', 'type' => 'checkbox', 'label' => 'Enable outbound email'],
                    ['name' => 'mail_host', 'type' => 'text', 'label' => 'SMTP host'],
                    ['name' => 'mail_port', 'type' => 'number', 'label' => 'Port', 'min' => 1, 'max' => 65535],
                    ['name' => 'mail_encryption', 'type' => 'select', 'label' => 'Encryption', 'options' => [
                        ['value' => 'tls', 'label' => 'STARTTLS'],
                        ['value' => 'ssl', 'label' => 'SSL/TLS'],
                        ['value' => 'none', 'label' => 'None'],
                    ]],
                    ['name' => 'mail_username', 'type' => 'text', 'label' => 'Username (if required)'],
                    ['name' => 'mail_password', 'type' => 'password', 'label' => 'Password', 'placeholder' => 'Leave blank to keep saved'],
                    ['name' => 'mail_from_email', 'type' => 'email', 'label' => 'From address'],
                    ['name' => 'mail_test_to', 'type' => 'email', 'label' => 'Send test to'],
                ];
                $tests = [['id' => 'mail', 'label' => 'Send test email']];
                break;

            case 'site':
                $kicker = 'Campus';
                $blurb = 'One site is enough to start. Add more campuses later under Data Centers.';
                $fields = [
                    ['name' => 'site_name', 'type' => 'text', 'label' => 'Site name', 'required' => true],
                    ['name' => 'site_code', 'type' => 'text', 'label' => 'Short code', 'placeholder' => 'HQ'],
                    ['name' => 'site_city', 'type' => 'text', 'label' => 'City (optional)'],
                ];
                break;

            case 'floor':
                $units = (string)($values['length_units'] ?? SettingsService::get('length_units', 'metric'));
                $imperial = $units === 'imperial';
                $unitWord = $imperial ? 'feet' : 'meters';
                $unitAbbr = $imperial ? 'ft' : 'm';
                $kicker = 'Floor plan';
                $blurb = 'These are this hall’s current size (from the room record the floor plan uses — not the datacenter’s leftover defaults). '
                    . 'Leave them as-is unless you intend to resize the canvas. '
                    . 'Storage is always meters; switching the dropdown only changes how the numbers are shown.';
                $fields = [
                    ['name' => 'dc_name', 'type' => 'text', 'label' => 'Data center name', 'required' => true],
                    ['name' => 'room_name', 'type' => 'text', 'label' => 'Room / hall name', 'required' => true],
                    ['name' => 'length_units', 'type' => 'select', 'label' => 'Show / enter sizes in', 'options' => [
                        ['value' => 'metric', 'label' => 'Meters (m) — stored unit'],
                        ['value' => 'imperial', 'label' => 'Feet (ft) — converted for display'],
                    ], 'hint' => 'Does not change stored meters unless you edit width or depth.'],
                    [
                        'name' => 'floor_width_m_orig',
                        'type' => 'hidden',
                        'label' => '',
                    ],
                    [
                        'name' => 'floor_depth_m_orig',
                        'type' => 'hidden',
                        'label' => '',
                    ],
                    [
                        'name' => 'floor_width',
                        'type' => 'number',
                        'label' => 'Width, left → right (' . $unitWord . ')',
                        'step' => $imperial ? '0.01' : '0.01',
                        'required' => true,
                        'hint' => 'Shown in ' . $unitAbbr . '. Floor plan canvas uses this room size.',
                    ],
                    [
                        'name' => 'floor_depth',
                        'type' => 'number',
                        'label' => 'Depth, front → back (' . $unitWord . ')',
                        'step' => '0.01',
                        'required' => true,
                        'hint' => 'Shown in ' . $unitAbbr . '.',
                    ],
                    ['name' => 'north_edge', 'type' => 'select', 'label' => 'Which edge is north on the plan?', 'options' => [
                        ['value' => 'top', 'label' => 'Top of the screen'],
                        ['value' => 'right', 'label' => 'Right'],
                        ['value' => 'bottom', 'label' => 'Bottom'],
                        ['value' => 'left', 'label' => 'Left'],
                    ]],
                ];
                $guides = [[
                    'title' => 'Why these numbers exist',
                    'body' => "The floor plan is the room: rooms.width_m × rooms.depth_m (always meters in SQL).\n"
                        . "The datacenter row may still have old installer defaults (e.g. 40×25 m) — those are not the canvas.\n"
                        . "If you only change feet/meters here, we keep the same stored meters so nothing jumps.\n"
                        . "Only edit width/depth when you mean to resize the hall.",
                ]];
                $tests = [['id' => 'floor', 'label' => 'Check dimensions']];
                break;

            case 'modules':
                $kicker = 'Skip what you will not use';
                $blurb = 'Uncheck areas you will not configure yet. This does not remove features — it just steers the “what’s next” list.';
                $fields = [
                    ['name' => 'mod_power', 'type' => 'checkbox', 'label' => 'Power (PDUs, panels, load)'],
                    ['name' => 'mod_ups', 'type' => 'checkbox', 'label' => 'UPS'],
                    ['name' => 'mod_cooling', 'type' => 'checkbox', 'label' => 'Cooling units'],
                    ['name' => 'mod_sensors', 'type' => 'checkbox', 'label' => 'Environment sensors'],
                    ['name' => 'mod_cabling', 'type' => 'checkbox', 'label' => 'Cabling / raceways'],
                    ['name' => 'mod_snmp', 'type' => 'checkbox', 'label' => 'SNMP polling later'],
                ];
                break;

            case 'updates':
                $kicker = 'Optional';
                $blurb = 'In-app updates pull GitHub release tags. Public repos do not need a token.';
                $fields = [
                    ['name' => 'updates_enabled', 'type' => 'checkbox', 'label' => 'Enable in-app updates'],
                    ['name' => 'updates_auto_check', 'type' => 'checkbox', 'label' => 'Check for updates automatically'],
                ];
                break;

            case 'finish':
                $kicker = 'You are set';
                $blurb = 'Your settings are saved. These are not previews — opening a page under this window hides it. Choose a starting task below; the wizard will close first so you can see the real screen.';
                break;
        }

        foreach ($fields as &$f) {
            $name = $f['name'];
            if (array_key_exists($name, $values)) {
                $f['value'] = $values[$name];
            }
        }
        unset($f);

        $next = [
            'id' => $id,
            'title' => $meta['title'],
            'skippable' => !empty($meta['skippable']),
            'skip_label' => $meta['skip_label'] ?: 'Skip',
            'kicker' => $kicker,
            'blurb' => $blurb,
            'fields' => $fields,
            'guides' => $guides,
            'tests' => $tests,
            'checklist' => $id === 'finish' ? self::finishChecklist($state) : [],
        ];
        if ($id === 'organization') {
            $next['timezones'] = timezone_identifiers_list();
        }
        return $next;
    }

    /**
     * @param array<string,mixed> $draft
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    private static function stepValues(string $id, array $draft, array $cfg): array
    {
        $site = self::firstRow('sites');
        $dc = self::firstRow('datacenters');
        $room = self::firstRow('rooms');
        $length = (string)SettingsService::get('length_units', 'metric');
        $temp = class_exists('TempUnitService') ? TempUnitService::siteUnit() : 'C';
        $sec = is_array($cfg['security'] ?? null) ? $cfg['security'] : [];
        $ldaps = is_array($cfg['auth']['ldaps'] ?? null) ? $cfg['auth']['ldaps'] : [];
        $mail = is_array($cfg['mail'] ?? null) ? $cfg['mail'] : [];
        $upd = is_array($cfg['updates'] ?? null) ? $cfg['updates'] : [];

        $base = match ($id) {
            'organization' => [
                'org_name' => (string)($cfg['org_name'] ?? SettingsService::get('org_name', '')),
                'timezone' => (string)($cfg['timezone'] ?? 'UTC'),
                'length_units' => $length ?: 'metric',
                'temp_unit' => $temp,
            ],
            'security' => [
                'force_https' => !empty($sec['force_https']) ? '1' : '',
                'session_idle_minutes' => (string)($sec['session_idle_minutes'] ?? 480),
            ],
            'directory' => [
                'ldaps_enabled' => !empty($ldaps['enabled']) ? '1' : '',
                'ldaps_host' => (string)($ldaps['host'] ?? ''),
                'ldaps_port' => (string)($ldaps['port'] ?? 636),
                'ldaps_base_dn' => (string)($ldaps['base_dn'] ?? ''),
                'ldaps_bind_dn' => (string)($ldaps['bind_dn'] ?? ''),
                'ldaps_bind_password' => '',
                'ldaps_tls_insecure' => !empty($ldaps['tls_insecure']) ? '1' : '',
                'ldaps_test_username' => '',
                'ldaps_test_password' => '',
            ],
            'mail' => [
                'mail_enabled' => !empty($mail['enabled']) ? '1' : '',
                'mail_host' => (string)($mail['host'] ?? ''),
                'mail_port' => (string)($mail['port'] ?? 587),
                'mail_encryption' => (string)($mail['encryption'] ?? 'tls'),
                'mail_username' => (string)($mail['username'] ?? ''),
                'mail_password' => '',
                'mail_from_email' => (string)($mail['from_email'] ?? ''),
                'mail_test_to' => '',
            ],
            'site' => [
                'site_name' => (string)($site['name'] ?? 'Primary Site'),
                'site_code' => (string)($site['code'] ?? 'SITE1'),
                'site_city' => (string)($site['city'] ?? ''),
            ],
            'floor' => (static function () use ($dc, $room, $length): array {
                $wM = (float)($room['width_m'] ?? $dc['floor_width_m'] ?? 20);
                $dM = (float)($room['depth_m'] ?? $dc['floor_depth_m'] ?? 15);
                return [
                    'dc_name' => (string)($dc['name'] ?? 'Data Center 1'),
                    'room_name' => (string)($room['name'] ?? 'Main Hall'),
                    'length_units' => $length === 'imperial' ? 'imperial' : 'metric',
                    'floor_width_m_orig' => (string)$wM,
                    'floor_depth_m_orig' => (string)$dM,
                    'floor_width' => self::lenFromMeters($wM, $length),
                    'floor_depth' => self::lenFromMeters($dM, $length),
                    'north_edge' => (string)($dc['north_edge'] ?? 'top'),
                ];
            })(),
            'modules' => [
                'mod_power' => '1',
                'mod_ups' => '1',
                'mod_cooling' => '',
                'mod_sensors' => '',
                'mod_cabling' => '1',
                'mod_snmp' => '',
            ],
            'updates' => [
                'updates_enabled' => array_key_exists('enabled', $upd) ? (!empty($upd['enabled']) ? '1' : '') : '1',
                'updates_auto_check' => array_key_exists('auto_check', $upd) ? (!empty($upd['auto_check']) ? '1' : '') : '1',
            ],
            default => [],
        };

        foreach ($draft as $k => $v) {
            if ($v === null) {
                continue;
            }
            if (in_array($k, ['ldaps_bind_password', 'ldaps_test_password', 'mail_password'], true) && $v === '') {
                continue;
            }
            // Hall size is always live from rooms.* — never a leftover wizard draft
            if (in_array($k, ['floor_width', 'floor_depth', 'floor_width_m_orig', 'floor_depth_m_orig'], true)) {
                continue;
            }
            $base[$k] = is_bool($v) ? ($v ? '1' : '') : (string)$v;
        }
        if ($id === 'floor') {
            $wM = (float)($room['width_m'] ?? $dc['floor_width_m'] ?? 20);
            $dM = (float)($room['depth_m'] ?? $dc['floor_depth_m'] ?? 15);
            $u = (string)($base['length_units'] ?? $length);
            $u = $u === 'imperial' ? 'imperial' : 'metric';
            $base['length_units'] = $u;
            $base['floor_width_m_orig'] = (string)round($wM, 2);
            $base['floor_depth_m_orig'] = (string)round($dM, 2);
            $base['floor_width'] = self::lenFromMeters($wM, $u);
            $base['floor_depth'] = self::lenFromMeters($dM, $u);
        }
        return $base;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $state
     * @param array<string,mixed> $user
     * @return array{ok:bool,error:?string}
     */
    private static function applyStep(string $id, array $input, array $state, array $user): array
    {
        try {
            switch ($id) {
                case 'welcome':
                    break;
                case 'organization':
                    self::applyOrganization($input);
                    break;
                case 'security':
                    self::applySecurity($input);
                    break;
                case 'directory':
                    self::applyDirectory($input);
                    break;
                case 'mail':
                    self::applyMail($input);
                    break;
                case 'site':
                    self::applySite($input);
                    break;
                case 'floor':
                    $chk = self::testFloor($input);
                    if (empty($chk['ok'])) {
                        return ['ok' => false, 'error' => $chk['summary']];
                    }
                    self::applyFloor($input);
                    break;
                case 'modules':
                    $state['data']['modules'] = [
                        'power' => self::truthy($input['mod_power'] ?? false),
                        'ups' => self::truthy($input['mod_ups'] ?? false),
                        'cooling' => self::truthy($input['mod_cooling'] ?? false),
                        'sensors' => self::truthy($input['mod_sensors'] ?? false),
                        'cabling' => self::truthy($input['mod_cabling'] ?? false),
                        'snmp' => self::truthy($input['mod_snmp'] ?? false),
                    ];
                    self::stashDraft($state, 'modules', $input);
                    self::saveState($state);
                    break;
                case 'updates':
                    self::applyUpdates($input);
                    break;
                case 'finish':
                    break;
            }
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            App::log('Setup wizard apply ' . $id . ': ' . $e->getMessage(), 'error');
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @param array<string,mixed> $input */
    private static function applyOrganization(array $input): void
    {
        $org = trim((string)($input['org_name'] ?? ''));
        if ($org === '') {
            throw new RuntimeException('Organization name is required.');
        }
        $tz = (string)($input['timezone'] ?? 'UTC');
        if (function_exists('coldaisle_normalize_timezone')) {
            $tz = coldaisle_normalize_timezone($tz);
        } elseif (!in_array($tz, timezone_identifiers_list(), true)) {
            $tz = 'UTC';
        }
        $units = strtolower(trim((string)($input['length_units'] ?? 'metric')));
        if (!in_array($units, ['metric', 'imperial'], true)) {
            $units = 'metric';
        }
        $temp = strtoupper(trim((string)($input['temp_unit'] ?? 'C')));
        $temp = $temp === 'F' ? 'F' : 'C';

        SettingsService::set('org_name', $org, 'general');
        SettingsService::set('length_units', $units, 'display');
        SettingsService::set('temp_unit', $temp, 'display');
        if (class_exists('TempUnitService')) {
            TempUnitService::clearCache();
        }

        $cfg = self::readConfig();
        $cfg['org_name'] = $org;
        $cfg['timezone'] = $tz;
        self::writeConfig($cfg);
    }

    /** @param array<string,mixed> $input */
    private static function applySecurity(array $input): void
    {
        $cfg = self::readConfig();
        if (!isset($cfg['security']) || !is_array($cfg['security'])) {
            $cfg['security'] = [];
        }
        $force = self::truthy($input['force_https'] ?? false);
        if ($force && !App::isHttps()) {
            throw new RuntimeException(
                'This page is not HTTPS. Enable Force HTTPS only after the site loads over HTTPS, or skip this step.'
            );
        }
        $idle = (int)($input['session_idle_minutes'] ?? 480);
        $idle = max(15, min(1440, $idle));
        $cfg['security']['force_https'] = $force;
        $cfg['security']['session_idle_minutes'] = $idle;
        $cfg['security']['cookie_secure'] = $cfg['security']['cookie_secure'] ?? 'auto';
        self::writeConfig($cfg);
    }

    /** @param array<string,mixed> $input */
    private static function applyDirectory(array $input): void
    {
        $enabled = self::truthy($input['ldaps_enabled'] ?? false);
        $cfg = self::readConfig();
        if (!isset($cfg['auth']) || !is_array($cfg['auth'])) {
            $cfg['auth'] = [];
        }
        $saved = is_array($cfg['auth']['ldaps'] ?? null) ? $cfg['auth']['ldaps'] : [];
        $bindPass = (string)($input['ldaps_bind_password'] ?? '');
        if ($bindPass === '') {
            $bindPass = (string)($saved['bind_password'] ?? '');
        }
        $cfg['auth']['local'] = ['enabled' => true];
        $cfg['auth']['ldaps'] = array_merge($saved, [
            'enabled' => $enabled,
            'host' => trim((string)($input['ldaps_host'] ?? $saved['host'] ?? '')),
            'port' => (int)($input['ldaps_port'] ?? $saved['port'] ?? 636),
            'base_dn' => trim((string)($input['ldaps_base_dn'] ?? $saved['base_dn'] ?? '')),
            'user_filter' => $saved['user_filter'] ?? '(sAMAccountName={username})',
            'bind_dn' => trim((string)($input['ldaps_bind_dn'] ?? $saved['bind_dn'] ?? '')),
            'bind_password' => $bindPass,
            'use_ssl' => true,
            'start_tls' => false,
            'tls_insecure' => self::truthy($input['ldaps_tls_insecure'] ?? false),
            'default_role_id' => $saved['default_role_id'] ?? 4,
            'require_security_group' => $saved['require_security_group'] ?? false,
        ]);
        if ($enabled) {
            $test = self::testLdap($input);
            if (empty($test['ok'])) {
                throw new RuntimeException($test['summary'] ?: 'Directory test failed. Fix LDAPS or skip this step.');
            }
        }
        SettingsService::set('auth_ldaps_enabled', $enabled ? '1' : '0', 'auth');
        SettingsService::set('auth_local_enabled', '1', 'auth');
        self::writeConfig($cfg);
    }

    /** @param array<string,mixed> $input */
    private static function applyMail(array $input): void
    {
        $enabled = self::truthy($input['mail_enabled'] ?? false);
        $cfg = self::readConfig();
        $saved = is_array($cfg['mail'] ?? null) ? $cfg['mail'] : [];
        $pass = (string)($input['mail_password'] ?? '');
        if ($pass === '') {
            $pass = (string)($saved['password'] ?? '');
        }
        $cfg['mail'] = array_merge(class_exists('MailService') ? MailService::defaultConfig() : [], $saved, [
            'enabled' => $enabled,
            'host' => trim((string)($input['mail_host'] ?? $saved['host'] ?? '')),
            'port' => (int)($input['mail_port'] ?? $saved['port'] ?? 587),
            'encryption' => (string)($input['mail_encryption'] ?? $saved['encryption'] ?? 'tls'),
            'username' => trim((string)($input['mail_username'] ?? $saved['username'] ?? '')),
            'password' => $pass,
            'from_email' => trim((string)($input['mail_from_email'] ?? $saved['from_email'] ?? '')),
            'from_name' => $saved['from_name'] ?? 'ColdAisle',
            'auth' => trim((string)($input['mail_username'] ?? $saved['username'] ?? '')) !== '',
            'auth_mode' => trim((string)($input['mail_username'] ?? '')) !== '' ? 'login' : 'none',
        ]);
        SettingsService::set('mail_enabled', $enabled ? '1' : '0', 'mail');
        self::writeConfig($cfg);
    }

    /** @param array<string,mixed> $input */
    private static function applySite(array $input): void
    {
        $name = trim((string)($input['site_name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Site name is required.');
        }
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($input['site_code'] ?? 'SITE1')) ?: 'SITE1');
        $city = trim((string)($input['site_city'] ?? ''));
        $tz = (string)(App::config('timezone') ?? 'UTC');
        $row = self::firstRow('sites');
        $data = [
            'name' => $name,
            'code' => $code,
            'city' => $city !== '' ? $city : null,
            'timezone' => $tz,
            'is_active' => 1,
        ];
        if ($row) {
            Database::update('sites', $data, 'site_id = :id', [':id' => (int)$row['site_id']]);
        } else {
            Database::insert('sites', $data);
        }
    }

    /** @param array<string,mixed> $input */
    private static function applyFloor(array $input): void
    {
        $dcName = trim((string)($input['dc_name'] ?? ''));
        $roomName = trim((string)($input['room_name'] ?? ''));
        $units = strtolower(trim((string)($input['length_units'] ?? SettingsService::get('length_units', 'metric'))));
        if (!in_array($units, ['metric', 'imperial'], true)) {
            $units = 'metric';
        }
        // Display preference only — does not resize the hall by itself
        SettingsService::set('length_units', $units, 'display');

        $enteredW = self::lenToMeters((float)($input['floor_width'] ?? 0), $units);
        $enteredD = self::lenToMeters((float)($input['floor_depth'] ?? 0), $units);
        $origW = isset($input['floor_width_m_orig']) && $input['floor_width_m_orig'] !== ''
            ? (float)$input['floor_width_m_orig']
            : null;
        $origD = isset($input['floor_depth_m_orig']) && $input['floor_depth_m_orig'] !== ''
            ? (float)$input['floor_depth_m_orig']
            : null;
        // If the typed value is just the display conversion of the original meters, keep original
        // so 10.06 m → 33.005 ft → 10.06 m never drifts.
        $w = ($origW !== null && abs($enteredW - $origW) < 0.02) ? $origW : $enteredW;
        $d = ($origD !== null && abs($enteredD - $origD) < 0.02) ? $origD : $enteredD;
        $w = round($w, 2);
        $d = round($d, 2);

        $north = (string)($input['north_edge'] ?? 'top');
        if (!in_array($north, ['top', 'right', 'bottom', 'left'], true)) {
            $north = 'top';
        }
        $site = self::firstRow('sites');
        if (!$site) {
            $siteId = Database::insert('sites', [
                'name' => 'Primary Site',
                'code' => 'SITE1',
                'timezone' => (string)(App::config('timezone') ?? 'UTC'),
                'is_active' => 1,
            ]);
        } else {
            $siteId = (int)$site['site_id'];
        }
        $dc = self::firstRow('datacenters');
        $dcData = [
            'site_id' => $siteId,
            'name' => $dcName,
            'code' => $dc['code'] ?? 'DC1',
            'north_edge' => $north,
            'is_active' => 1,
        ];
        if ($dc) {
            Database::update('datacenters', $dcData, 'datacenter_id = :id', [':id' => (int)$dc['datacenter_id']]);
            $dcId = (int)$dc['datacenter_id'];
        } else {
            $dcData['floor_width_m'] = $w;
            $dcData['floor_depth_m'] = $d;
            $dcId = Database::insert('datacenters', $dcData);
        }
        $room = self::firstRow('rooms');
        $roomData = [
            'datacenter_id' => $dcId,
            'name' => $roomName,
            'code' => $room['code'] ?? 'HALL-A',
            'width_m' => $w,
            'depth_m' => $d,
            'is_active' => 1,
        ];
        if ($room) {
            Database::update('rooms', $roomData, 'room_id = :id', [':id' => (int)$room['room_id']]);
        } else {
            Database::insert('rooms', $roomData);
        }
    }

    /** @param array<string,mixed> $input */
    private static function applyUpdates(array $input): void
    {
        $cfg = self::readConfig();
        if (!isset($cfg['updates']) || !is_array($cfg['updates'])) {
            $cfg['updates'] = [
                'enabled' => true,
                'auto_check' => true,
                'check_interval_hours' => 24,
                'ssl_verify' => true,
            ];
        }
        $cfg['updates']['enabled'] = self::truthy($input['updates_enabled'] ?? false);
        $cfg['updates']['auto_check'] = self::truthy($input['updates_auto_check'] ?? false);
        self::writeConfig($cfg);
    }

    /** @param array<string,mixed> $input */
    private static function testLdap(array $input): array
    {
        if (!class_exists('LdapAuth')) {
            return ['ok' => false, 'summary' => 'LdapAuth is not available.', 'steps' => []];
        }
        $saved = is_array(App::config('auth.ldaps')) ? App::config('auth.ldaps') : [];
        $bindPass = (string)($input['ldaps_bind_password'] ?? '');
        if ($bindPass === '') {
            $bindPass = (string)($saved['bind_password'] ?? '');
        }
        $testCfg = [
            'host' => trim((string)($input['ldaps_host'] ?? '')),
            'port' => (int)($input['ldaps_port'] ?? 636),
            'base_dn' => trim((string)($input['ldaps_base_dn'] ?? '')),
            'user_filter' => (string)($saved['user_filter'] ?? '(sAMAccountName={username})'),
            'bind_dn' => trim((string)($input['ldaps_bind_dn'] ?? '')),
            'bind_password' => $bindPass,
            'use_ssl' => true,
            'start_tls' => false,
            'tls_insecure' => self::truthy($input['ldaps_tls_insecure'] ?? false),
        ];
        return LdapAuth::testConnection(
            $testCfg,
            trim((string)($input['ldaps_test_username'] ?? '')),
            (string)($input['ldaps_test_password'] ?? '')
        );
    }

    /** @param array<string,mixed> $input */
    private static function testMail(array $input): array
    {
        if (!class_exists('MailService')) {
            return ['ok' => false, 'summary' => 'MailService is not available.', 'steps' => []];
        }
        $saved = is_array(App::config('mail')) ? App::config('mail') : [];
        $post = [
            'mail_host' => $input['mail_host'] ?? '',
            'mail_port' => $input['mail_port'] ?? 587,
            'mail_encryption' => $input['mail_encryption'] ?? 'tls',
            'mail_username' => $input['mail_username'] ?? '',
            'mail_password' => $input['mail_password'] ?? '',
            'mail_from_email' => $input['mail_from_email'] ?? '',
            'mail_auth' => !empty($input['mail_username']),
            'mail_auth_mode' => !empty($input['mail_username']) ? 'login' : 'none',
        ];
        $override = MailService::configFromPost($post, $saved);
        $override['enabled'] = true;
        $to = trim((string)($input['mail_test_to'] ?? ''));
        $steps = [];
        $host = trim((string)($override['host'] ?? ''));
        $from = trim((string)($override['from_email'] ?? ''));
        $cfgOk = $host !== '' && $from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL);
        $steps[] = [
            'ok' => $cfgOk && $to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL),
            'name' => 'Configuration',
            'detail' => ($host !== '' ? $host : 'host missing')
                . ($from !== '' ? " · From {$from}" : ' · From missing')
                . ($to !== '' ? " · To {$to}" : ' · recipient missing'),
        ];
        if (!$steps[0]['ok']) {
            return ['ok' => false, 'summary' => 'Host, from address, and a test recipient are required.', 'steps' => $steps];
        }
        $result = MailService::sendTest($to, $override);
        $ok = !empty($result['ok']);
        $steps[] = [
            'ok' => $ok,
            'name' => 'SMTP delivery',
            'detail' => (string)($result['message'] ?? ($ok ? 'Accepted' : 'Failed')),
        ];
        return [
            'ok' => $ok,
            'summary' => $ok ? ('Test accepted for ' . $to . '.') : (string)($result['message'] ?? 'Send failed.'),
            'steps' => $steps,
        ];
    }

    private static function testHttps(): array
    {
        $https = App::isHttps();
        $mismatch = App::httpsConfigMismatch();
        $steps = [
            [
                'ok' => $https,
                'name' => 'This request',
                'detail' => $https ? 'Loaded over HTTPS.' : 'Loaded over HTTP — do not force HTTPS yet.',
            ],
            [
                'ok' => !$mismatch,
                'name' => 'Configured URL',
                'detail' => $mismatch
                    ? 'Settings URL is HTTPS but this page is HTTP.'
                    : 'No HTTPS mismatch detected.',
            ],
        ];
        return [
            'ok' => $https && !$mismatch,
            'summary' => $https
                ? 'HTTPS looks good for this connection.'
                : 'HTTP only — leave Force HTTPS unchecked.',
            'steps' => $steps,
        ];
    }

    /** @param array<string,mixed> $input */
    private static function testFloor(array $input): array
    {
        $units = strtolower(trim((string)($input['length_units'] ?? SettingsService::get('length_units', 'metric'))));
        if (!in_array($units, ['metric', 'imperial'], true)) {
            $units = 'metric';
        }
        $w = (float)($input['floor_width'] ?? 0);
        $d = (float)($input['floor_depth'] ?? 0);
        $wm = self::lenToMeters($w, $units);
        $dm = self::lenToMeters($d, $units);
        $okW = $wm >= 3 && $wm <= 500;
        $okD = $dm >= 3 && $dm <= 500;
        $label = $units === 'imperial' ? 'ft' : 'm';
        $steps = [
            [
                'ok' => $okW,
                'name' => 'Width',
                'detail' => $w > 0
                    ? (round($w, 2) . " {$label} → " . round($wm, 2) . ' m')
                    : 'Enter a width.',
            ],
            [
                'ok' => $okD,
                'name' => 'Depth',
                'detail' => $d > 0
                    ? (round($d, 2) . " {$label} → " . round($dm, 2) . ' m')
                    : 'Enter a depth.',
            ],
            [
                'ok' => trim((string)($input['dc_name'] ?? '')) !== '' && trim((string)($input['room_name'] ?? '')) !== '',
                'name' => 'Names',
                'detail' => 'Data center and room names are required.',
            ],
        ];
        $ok = $okW && $okD && $steps[2]['ok'];
        return [
            'ok' => $ok,
            'summary' => $ok
                ? ('Hall will be ' . round($wm, 1) . ' × ' . round($dm, 1) . ' m.')
                : 'Check names and dimensions (about 3–500 m on each side).',
            'steps' => $steps,
        ];
    }

    /** @param array<string,mixed> $state */
    private static function finishChecklist(array $state): array
    {
        $mods = is_array($state['data']['modules'] ?? null) ? $state['data']['modules'] : [];
        $items = [
            [
                'label' => 'Take the site tour',
                'detail' => 'Flags on the real screens: nav, floor plan, devices, power, and the rest. Exit anytime.',
                'href' => 'index.php?tour=1',
                'primary' => true,
            ],
            [
                'label' => 'Place cabinets on the floor plan',
                'detail' => 'Draw rows and drop cabinets onto the hall you just sized. Do this first — devices need a rack.',
                'href' => 'pages/floorplan.php',
                'primary' => false,
            ],
            [
                'label' => 'Add devices to those cabinets',
                'detail' => 'After racks exist, mount servers, switches, and other gear in U positions.',
                'href' => 'pages/devices.php',
            ],
        ];
        if (!empty($mods['power']) || $mods === []) {
            $items[] = [
                'label' => 'Add PDUs and power path',
                'detail' => 'Map rack PDUs, panels, and feeders so load charts have something to attach to.',
                'href' => 'pages/power_pdus.php',
            ];
        }
        if (!empty($mods['ups'])) {
            $items[] = [
                'label' => 'Add UPS units',
                'detail' => 'Register UPS gear if you track battery runtime and upstream power.',
                'href' => 'pages/power_ups.php',
            ];
        }
        if (!empty($mods['cooling'])) {
            $items[] = [
                'label' => 'Add cooling units',
                'detail' => 'CRAH/CRAC and similar units for the hall.',
                'href' => 'pages/cooling_units.php',
            ];
        }
        if (!empty($mods['sensors'])) {
            $items[] = [
                'label' => 'Add environment sensors',
                'detail' => 'Temperature and humidity probes for the 3D/NOC views.',
                'href' => 'pages/env_sensors.php',
            ];
        }
        if (!empty($mods['cabling'])) {
            $items[] = [
                'label' => 'Draw raceways and cables',
                'detail' => 'Ladder, U-channel, and port-to-port paths — after cabinets are placed.',
                'href' => 'pages/cables.php',
            ];
        }
        if (!empty($mods['snmp'])) {
            $items[] = [
                'label' => 'Turn on SNMP polling',
                'detail' => 'Settings → SNMP schedule. Only after devices/PDUs have IPs.',
                'href' => 'pages/settings.php#snmp-schedule',
            ];
        }
        $items[] = [
            'label' => 'Invite users',
            'detail' => 'Add local accounts or wait for directory logins you already configured.',
            'href' => 'pages/users.php',
        ];
        return $items;
    }

    /** Allow only same-app relative paths after the wizard closes. */
    public static function safeGoto(string $href): string
    {
        $href = trim($href);
        if ($href === '' || str_contains($href, '://') || str_starts_with($href, '//')) {
            return 'index.php';
        }
        $href = ltrim($href, '/');
        if (!preg_match('#^(index\.php|pages/[a-z0-9_]+\.php)(\?[A-Za-z0-9_=&-]+)?(#[A-Za-z0-9_-]+)?$#', $href)) {
            return 'index.php';
        }
        return $href;
    }

    /** @return array<string,mixed> */
    private static function readConfig(): array
    {
        $path = App::ROOT . '/config/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('config/config.php is missing.');
        }
        $cfg = require $path;
        return is_array($cfg) ? $cfg : [];
    }

    /** @param array<string,mixed> $cfg */
    private static function writeConfig(array $cfg): void
    {
        $path = App::ROOT . '/config/config.php';
        $export = var_export($cfg, true);
        $php = "<?php\n/** ColdAisle configuration — updated via setup wizard */\ndeclare(strict_types=1);\n\nreturn {$export};\n";
        if (file_put_contents($path, $php) === false) {
            throw new RuntimeException('Could not write config/config.php');
        }
    }

    private static function firstRow(string $table): ?array
    {
        try {
            return Database::fetchOne("SELECT TOP 1 * FROM {$table} ORDER BY 1");
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function safeCount(string $table): int
    {
        try {
            return (int)Database::fetchValue("SELECT COUNT(*) FROM {$table}");
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function lenToMeters(float $value, string $units): float
    {
        // DECIMAL(10,2) columns — 3+ decimals can ODBC-truncate the UPDATE
        if ($units === 'imperial') {
            return round($value * 0.3048, 2);
        }
        return round($value, 2);
    }

    private static function lenFromMeters(float $meters, string $units): string
    {
        if ($units === 'imperial') {
            return (string)round($meters / 0.3048, 4);
        }
        return (string)round($meters, 2);
    }

    private static function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        $s = strtolower(trim((string)$v));
        return in_array($s, ['1', 'true', 'on', 'yes'], true);
    }

    /** @return array{ok:bool,error:string,state:array,payload:array} */
    private static function fail(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'state' => self::loadState() ?: self::defaultState(),
            'payload' => self::payload(),
        ];
    }
}
