<?php
/**
 * Extra ITSM provider fieldsets (ServiceNow, Zendesk, Jira, Freshservice).
 * Included inside the Ticketing settings form. SDP fields stay in settings.php.
 *
 * @var array<string,mixed> $snowCfg
 * @var array<string,mixed> $zdCfg
 * @var array<string,mixed> $jiraCfg
 * @var array<string,mixed> $fsCfg
 * @var string $itsmProvider
 */
if (!function_exists('coldaisle_itsm_toggles')) {
    /** @param array<string,mixed> $cfg */
    function coldaisle_itsm_toggles(string $prefix, array $cfg): void
    {
        $autoC = !isset($cfg['auto_create']) || !empty($cfg['auto_create']);
        $autoN = !isset($cfg['auto_note']) || !empty($cfg['auto_note']);
        $close = !empty($cfg['close_on_complete']);
        $pullOn = class_exists('ItsmService')
            ? ItsmService::settingGet($prefix . '_inbound_status', '1') === '1'
            : true;
        ?>
        <div class="form-row full"><label>
            <input type="checkbox" name="<?= App::e($prefix) ?>_auto_create" value="1" <?= $autoC ? 'checked' : '' ?>>
            Create a ticket when a work order is created
        </label></div>
        <div class="form-row full"><label>
            <input type="checkbox" name="<?= App::e($prefix) ?>_auto_note" value="1" <?= $autoN ? 'checked' : '' ?>>
            Add a private note when work-order status changes
        </label></div>
        <div class="form-row full"><label>
            <input type="checkbox" name="<?= App::e($prefix) ?>_close_on_complete" value="1" <?= $close ? 'checked' : '' ?>>
            Close / resolve the remote ticket when the work order is completed
        </label></div>
        <div class="form-row full"><label>
            <input type="checkbox" name="<?= App::e($prefix) ?>_inbound_status" value="1" <?= $pullOn ? 'checked' : '' ?>>
            Map remote status onto the work order when pulling (no inventory apply)
        </label></div>
        <div class="form-row full"><label>
            <input type="checkbox" name="<?= App::e($prefix) ?>_tls_verify" value="1"
                <?= !isset($cfg['tls_verify']) || !empty($cfg['tls_verify']) ? 'checked' : '' ?>>
            Verify TLS certificates (recommended)
        </label></div>
        <?php
    }
}
$snowCfg = $snowCfg ?? [];
$zdCfg = $zdCfg ?? [];
$jiraCfg = $jiraCfg ?? [];
$fsCfg = $fsCfg ?? [];
$itsmProvider = $itsmProvider ?? '';
?>
</div><!-- /sdp panel -->

<div data-itsm-panel="servicenow" <?= $itsmProvider === 'servicenow' ? '' : 'hidden' ?>>
    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">ServiceNow Table API</h4></div>
    <div class="form-row full">
        <p class="text-muted" style="font-size:.8rem;margin:0">
            Integration user with REST Table API access (dedicated non-interactive account recommended).
            Creates <code>POST /api/now/table/{incident|change_request}</code> and writes <code>work_notes</code>.
        </p>
    </div>
    <div class="form-row"><label>Instance URL</label>
        <input class="form-control" name="snow_instance" value="<?= App::e((string)($snowCfg['instance'] ?? '')) ?>"
               placeholder="https://contoso.service-now.com"></div>
    <div class="form-row"><label>Table</label>
        <select class="form-control" name="snow_table">
            <?php $st = (string)($snowCfg['table'] ?? 'incident'); ?>
            <option value="incident" <?= $st === 'incident' ? 'selected' : '' ?>>incident</option>
            <option value="change_request" <?= $st === 'change_request' ? 'selected' : '' ?>>change_request</option>
        </select>
    </div>
    <div class="form-row"><label>Username</label>
        <input class="form-control" name="snow_username" value="<?= App::e((string)($snowCfg['username'] ?? '')) ?>" autocomplete="off"></div>
    <div class="form-row"><label>Password / API token</label>
        <input class="form-control" type="password" name="snow_password" value=""
               placeholder="<?= !empty($snowCfg['password']) ? '•••• saved (leave blank to keep)' : '' ?>"
               autocomplete="new-password"></div>
    <div class="form-row"><label>Caller (email or sys_id)</label>
        <input class="form-control" name="snow_caller" value="<?= App::e((string)($snowCfg['caller'] ?? '')) ?>"
               placeholder="Optional"></div>
    <details class="itsm-section">
    <summary>Status mapping &amp; automation</summary>
    <div class="form-row"><label>Closed state</label>
        <input class="form-control" name="snow_closed_state" value="<?= App::e((string)($snowCfg['closed_state'] ?? '7')) ?>"
               placeholder="7 = Closed (incident)"></div>
    <div class="form-row"><label>Cancelled state</label>
        <input class="form-control" name="snow_cancelled_state" value="<?= App::e((string)($snowCfg['cancelled_state'] ?? '8')) ?>"></div>
    <div class="form-row"><label>In-progress state</label>
        <input class="form-control" name="snow_progress_state" value="<?= App::e((string)($snowCfg['progress_state'] ?? '2')) ?>"></div>
    <?php coldaisle_itsm_toggles('snow', $snowCfg); ?>
    </details>
</div>

<div data-itsm-panel="zendesk" <?= $itsmProvider === 'zendesk' ? '' : 'hidden' ?>>
    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Zendesk Support API</h4></div>
    <div class="form-row full">
        <p class="text-muted" style="font-size:.8rem;margin:0">
            Admin Center → Apps and integrations → APIs → API tokens.
            Auth is Basic <code>{email}/token:{api_token}</code> ·
            <code>POST /api/v2/tickets.json</code>
        </p>
    </div>
    <div class="form-row"><label>Subdomain</label>
        <input class="form-control" name="zd_subdomain" value="<?= App::e((string)($zdCfg['subdomain'] ?? '')) ?>"
               placeholder="contoso"></div>
    <div class="form-row"><label>Agent email</label>
        <input class="form-control" type="email" name="zd_email" value="<?= App::e((string)($zdCfg['email'] ?? '')) ?>"></div>
    <div class="form-row"><label>API token</label>
        <input class="form-control" type="password" name="zd_api_token" value=""
               placeholder="<?= !empty($zdCfg['token']) ? '•••• saved (leave blank to keep)' : '' ?>"
               autocomplete="new-password"></div>
    <div class="form-row"><label>Requester email</label>
        <input class="form-control" type="email" name="zd_requester_email"
               value="<?= App::e((string)($zdCfg['requester_email'] ?? '')) ?>"
               placeholder="Optional"></div>
    <details class="itsm-section">
    <summary>Status mapping &amp; automation</summary>
    <div class="form-row"><label>Complete status</label>
        <input class="form-control" name="zd_closed_status" value="<?= App::e((string)($zdCfg['closed_status'] ?? 'solved')) ?>"></div>
    <div class="form-row"><label>Cancel status</label>
        <input class="form-control" name="zd_cancelled_status" value="<?= App::e((string)($zdCfg['cancelled_status'] ?? 'closed')) ?>"></div>
    <div class="form-row"><label>In-progress status</label>
        <input class="form-control" name="zd_progress_status" value="<?= App::e((string)($zdCfg['progress_status'] ?? 'open')) ?>"></div>
    <?php coldaisle_itsm_toggles('zd', $zdCfg); ?>
    </details>
</div>

<div data-itsm-panel="jira" <?= $itsmProvider === 'jira' ? '' : 'hidden' ?>>
    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Jira REST API v2</h4></div>
    <div class="form-row full">
        <p class="text-muted" style="font-size:.8rem;margin:0">
            Cloud: account email + API token. Data Center: username + PAT.
            <code>POST /rest/api/2/issue</code> (plain description). Close uses a
            <em>workflow transition name</em> that must exist on the issue.
        </p>
    </div>
    <div class="form-row"><label>Site URL</label>
        <input class="form-control" name="jira_base" value="<?= App::e((string)($jiraCfg['base'] ?? '')) ?>"
               placeholder="https://contoso.atlassian.net"></div>
    <div class="form-row"><label>Email / username</label>
        <input class="form-control" name="jira_email" value="<?= App::e((string)($jiraCfg['email'] ?? '')) ?>"></div>
    <div class="form-row"><label>API token / PAT</label>
        <input class="form-control" type="password" name="jira_token" value=""
               placeholder="<?= !empty($jiraCfg['token']) ? '•••• saved (leave blank to keep)' : '' ?>"
               autocomplete="new-password"></div>
    <div class="form-row"><label>Project key</label>
        <input class="form-control" name="jira_project" value="<?= App::e((string)($jiraCfg['project'] ?? '')) ?>"
               placeholder="ITSM"></div>
    <div class="form-row"><label>Issue type</label>
        <input class="form-control" name="jira_issue_type" value="<?= App::e((string)($jiraCfg['issue_type'] ?? 'Task')) ?>"></div>
    <details class="itsm-section">
    <summary>Workflow transitions &amp; automation</summary>
    <div class="form-row"><label>Done transition</label>
        <input class="form-control" name="jira_close_transition" value="<?= App::e((string)($jiraCfg['close_transition'] ?? 'Done')) ?>"></div>
    <div class="form-row"><label>Cancel transition</label>
        <input class="form-control" name="jira_cancel_transition" value="<?= App::e((string)($jiraCfg['cancel_transition'] ?? 'Cancelled')) ?>"></div>
    <div class="form-row"><label>In-progress transition</label>
        <input class="form-control" name="jira_progress_transition" value="<?= App::e((string)($jiraCfg['progress_transition'] ?? 'In Progress')) ?>"></div>
    <?php coldaisle_itsm_toggles('jira', $jiraCfg); ?>
    </details>
</div>

<div data-itsm-panel="freshservice" <?= $itsmProvider === 'freshservice' ? '' : 'hidden' ?>>
    <div class="form-row full"><h4 class="mt-0" style="margin-bottom:0;font-size:.95rem;color:var(--muted)">Freshservice Tickets API v2</h4></div>
    <div class="form-row full">
        <p class="text-muted" style="font-size:.8rem;margin:0">
            Profile → API key. Basic <code>{api_key}:X</code> ·
            <code>POST /api/v2/tickets</code>. Requester email is required to create.
            Status ids: 2 Open, 3 Pending, 4 Resolved, 5 Closed.
        </p>
    </div>
    <div class="form-row"><label>Domain</label>
        <input class="form-control" name="fs_domain" value="<?= App::e((string)($fsCfg['domain'] ?? '')) ?>"
               placeholder="contoso.freshservice.com"></div>
    <div class="form-row"><label>API key</label>
        <input class="form-control" type="password" name="fs_api_key" value=""
               placeholder="<?= !empty($fsCfg['api_key']) ? '•••• saved (leave blank to keep)' : '' ?>"
               autocomplete="new-password"></div>
    <div class="form-row"><label>Requester email</label>
        <input class="form-control" type="email" name="fs_email" value="<?= App::e((string)($fsCfg['email'] ?? '')) ?>"></div>
    <details class="itsm-section">
    <summary>Status ids &amp; automation</summary>
    <div class="form-row"><label>Closed status id</label>
        <input class="form-control" name="fs_closed_status" value="<?= App::e((string)($fsCfg['closed_status'] ?? '5')) ?>"></div>
    <div class="form-row"><label>Cancelled status id</label>
        <input class="form-control" name="fs_cancelled_status" value="<?= App::e((string)($fsCfg['cancelled_status'] ?? '5')) ?>"></div>
    <div class="form-row"><label>In-progress status id</label>
        <input class="form-control" name="fs_progress_status" value="<?= App::e((string)($fsCfg['progress_status'] ?? '2')) ?>"></div>
    <?php coldaisle_itsm_toggles('fs', $fsCfg); ?>
    </details>
</div>
