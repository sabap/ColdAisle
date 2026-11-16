<?php
/**
 * Named NOC views. The public wall for each view does not require a login.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/App.php';
require_once dirname(__DIR__) . '/includes/layout.php';
App::boot();
$user = App::requirePermission('manage_settings');

NocViewService::ensureTable();

$editId = (int)($_GET['id'] ?? 0);
$error = '';
$draft = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!App::verifyCsrf((string)($_POST['_csrf'] ?? ''))) {
        App::flash('error', 'The form expired. Try again.');
        App::redirect('pages/noc_views.php');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        NocViewService::delete((int)($_POST['id'] ?? 0));
        App::flash('success', 'NOC view deleted. TVs using that URL will not open it.');
        App::redirect('pages/noc_views.php');
    }
    if ($action === 'create') {
        $templateKey = (string)($_POST['template'] ?? 'blank');
        $templates = NocViewService::templates();
        $tiles = $templates[$templateKey]['tiles'] ?? [];
        $result = NocViewService::save(0, (string)($_POST['name'] ?? ''), '', $tiles);
        if ($result['ok']) {
            App::flash('success', 'NOC view created. Place the tiles, then save.');
            App::redirect('pages/noc_views.php?id=' . (int)$result['id']);
        }
        $error = $result['error'];
    }
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $rawLayout = (string)($_POST['layout_json'] ?? '');
        $decoded = json_decode($rawLayout, true);
        if ($rawLayout === '' || !is_array($decoded)) {
            $error = 'The board could not be read. Reload the page and try again.';
            $editId = $id;
        } else {
        $tiles = $decoded;
        $result = NocViewService::save($id, (string)($_POST['name'] ?? ''), (string)($_POST['slug'] ?? ''), $tiles);
        if ($result['ok']) {
            App::flash('success', 'NOC view saved.');
            App::redirect('pages/noc_views.php?id=' . (int)$result['id']);
        }
        $error = $result['error'];
        $draft = [
            'view_id' => $id,
            'name' => trim((string)($_POST['name'] ?? '')),
            'slug' => NocViewService::slugify((string)($_POST['slug'] ?? '')),
            'tiles' => $tiles,
        ];
        $editId = $id;
        }
    }
}

$token = '';
try {
    $token = trim((string)SettingsService::get('noc_access_token', ''));
} catch (Throwable $e) {
    $token = '';
}
$classicUrl = App::url('pages/noc.php');
if ($token !== '') {
    $classicUrl .= (str_contains($classicUrl, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
}

layout_header('NOC views', $user, 'noc_views');
?>
<link rel="stylesheet" href="<?= App::e(App::url('assets/css/noc-views.css') . '?v=1') ?>">
<?php if ($editId > 0 || $draft !== null):
    $view = $draft ?? NocViewService::find($editId);
    if ($view === null) {
        App::flash('error', 'That NOC view does not exist.');
        App::redirect('pages/noc_views.php');
    }
    $modules = NocViewService::modules();
    $public = NocViewService::publicUrl((string)$view['slug'], $token);
    ?>
<div class="card">
    <div class="card-header flex-between">
        <h2 style="margin:0">Edit NOC view</h2>
        <a class="btn btn-sm btn-secondary" href="<?= App::e(App::url('pages/noc_views.php')) ?>">All views</a>
    </div>
    <div class="card-body">
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= App::e($error) ?></div>
        <?php endif; ?>
        <p class="text-muted" style="margin-top:0">
            The board is a 1920×1080 TV. Drag a module onto it. A tile that would hang off the board or cover another tile is refused, so the wall cannot scroll.
            Rotation is a tile. Leave it off and the view stays on the tiles you placed.
        </p>
        <form method="post" id="nvForm">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)$view['view_id'] ?>">
            <input type="hidden" name="layout_json" id="layout_json" value="">
            <div class="form-grid" style="margin-bottom:1rem">
                <div class="form-row">
                    <label for="nvName">Name</label>
                    <input class="form-control" id="nvName" name="name" maxlength="60" required value="<?= App::e((string)$view['name']) ?>">
                </div>
                <div class="form-row">
                    <label for="nvSlug">URL name</label>
                    <input class="form-control" id="nvSlug" name="slug" maxlength="40" required value="<?= App::e((string)$view['slug']) ?>" data-touched="1" autocomplete="off">
                </div>
            </div>
            <p class="text-muted" style="font-size:.8rem;word-break:break-all;margin-top:0">
                Public URL (no login): <code id="nvUrl"><?= App::e($public) ?></code>
                <a class="btn btn-sm btn-secondary" id="nvOpen" href="<?= App::e($public) ?>" target="_blank" rel="noopener">Open</a>
            </p>
            <div class="nv-layout">
                <div>
                    <strong style="font-size:.85rem">Modules</strong>
                    <div id="nvPalette" class="nv-palette"></div>
                </div>
                <div>
                    <div class="nv-stage-label">1920×1080</div>
                    <div class="nv-stage">
                        <div class="nv-board" id="nvBoard"></div>
                    </div>
                    <p class="text-muted" id="nvFit" style="min-height:1.2em;margin:.45rem 0 0"></p>
                    <div id="nvDetail"></div>
                </div>
            </div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">Save view</button>
            </div>
        </form>
        <form method="post" style="margin-top:.75rem" onsubmit="return confirm('Delete this NOC view?');">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$view['view_id'] ?>">
            <button type="submit" class="btn btn-sm btn-secondary">Delete view</button>
        </form>
    </div>
</div>
<script>
  window.NocViewEditor = {
    modules: <?= json_encode($modules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    pages: <?= json_encode(NocViewService::PAGE_MODULES, JSON_UNESCAPED_SLASHES) ?>,
    tiles: <?= json_encode($view['tiles'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    wallBase: <?= json_encode(App::url('pages/noc.php'), JSON_UNESCAPED_SLASHES) ?>,
    token: <?= json_encode($token, JSON_UNESCAPED_SLASHES) ?>
  };
</script>
<script src="<?= App::e(App::url('assets/js/noc-views.js') . '?v=1') ?>"></script>
<?php else:
    $views = NocViewService::all();
    $templates = NocViewService::templates();
    ?>
<div class="card">
    <div class="card-header"><h2>NOC views</h2></div>
    <div class="card-body">
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= App::e($error) ?></div>
        <?php endif; ?>
        <p class="text-muted" style="margin-top:0">
            Each view is a named 1920×1080 wall with its own public URL. The original wall is unchanged:
            <a href="<?= App::e($classicUrl) ?>" target="_blank" rel="noopener"><code><?= App::e($classicUrl) ?></code></a>
        </p>
        <?php if ($views === []): ?>
            <p>No custom views yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                    <tr><th>Name</th><th>Public URL</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($views as $view):
                        $url = NocViewService::publicUrl((string)$view['slug'], $token);
                        ?>
                        <tr>
                            <td><strong><?= App::e((string)$view['name']) ?></strong></td>
                            <td class="text-muted" style="font-size:.8rem;word-break:break-all"><code><?= App::e($url) ?></code></td>
                            <td class="actions col-actions">
                                <a class="btn btn-sm btn-secondary" href="<?= App::e($url) ?>" target="_blank" rel="noopener">Open</a>
                                <a class="btn btn-sm btn-primary" href="<?= App::e(App::url('pages/noc_views.php?id=' . (int)$view['view_id'])) ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h3 style="margin:1.25rem 0 .5rem;font-size:1rem">New view</h3>
        <form method="post" class="form-grid">
            <input type="hidden" name="_csrf" value="<?= App::e(App::csrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <div class="form-row">
                <label for="nvNewName">Name</label>
                <input class="form-control" id="nvNewName" name="name" maxlength="60" required placeholder="Firewall wall">
            </div>
            <div class="form-row">
                <label for="nvTemplate">Start from</label>
                <select class="form-control" id="nvTemplate" name="template">
                    <?php foreach ($templates as $key => $template): ?>
                        <option value="<?= App::e($key) ?>"><?= App::e($template['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary">Create view</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php layout_footer(); ?>
