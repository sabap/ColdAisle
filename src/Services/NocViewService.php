<?php
/**
 * Named NOC walls. Each view is a 12×6 board on a fixed 1920×1080 stage.
 * A tile that would hang off the board or cover another tile is refused,
 * so the TV layout cannot grow a scrollbar.
 */
declare(strict_types=1);

class NocViewService
{
    public const COLS = 12;
    public const ROWS = 6;
    public const MAX_VIEWS = 24;

    /** @var list<string> */
    public const PAGE_MODULES = ['overview', 'power', 'zones', 'cooling', 'firewalls'];

    public static function ensureTable(): void
    {
        $exists = Database::fetchValue(
            "SELECT 1 FROM sys.tables WHERE name = ? AND SCHEMA_NAME(schema_id) = 'dbo'",
            ['noc_views']
        );
        if ($exists) {
            return;
        }
        Database::exec(
            "CREATE TABLE noc_views (
                view_id INT IDENTITY(1,1) PRIMARY KEY,
                name NVARCHAR(60) NOT NULL,
                slug NVARCHAR(40) NOT NULL,
                layout_json NVARCHAR(MAX) NOT NULL,
                created_at DATETIME2 NOT NULL CONSTRAINT DF_noc_views_created DEFAULT SYSUTCDATETIME(),
                updated_at DATETIME2 NOT NULL CONSTRAINT DF_noc_views_updated DEFAULT SYSUTCDATETIME(),
                CONSTRAINT UQ_noc_views_slug UNIQUE (slug)
            )"
        );
    }

    /**
     * Modules an operator can place. Sizes are width × height in board cells.
     * Larger sizes are listed first so a drop prefers the biggest tile that fits.
     *
     * @return array<string, array{label:string, blurb:string, sizes:list<array{0:int,1:int}>}>
     */
    public static function modules(): array
    {
        $panel = [[12, 6], [7, 6], [6, 6], [5, 6], [12, 4], [7, 4], [6, 4], [5, 4]];
        return [
            'scene' => [
                'label' => '3D datacenter',
                'blurb' => 'The floor model. It orbits when 3D auto-rotate is on in Settings.',
                'sizes' => [[12, 6], [6, 6], [5, 6], [6, 4], [5, 4]],
            ],
            'alerts' => [
                'label' => 'Recent alerts',
                'blurb' => 'The latest alerts. The list stays inside the tile.',
                'sizes' => [[12, 2], [7, 2], [6, 2], [5, 2]],
            ],
            'rotate' => [
                'label' => 'Rotating pages',
                'blurb' => 'Cycles the pages you pick. Leave it off the board for a fixed wall.',
                'sizes' => $panel,
            ],
            'overview' => [
                'label' => 'Overview',
                'blurb' => 'Inventory and capacity figures. Stays on this page.',
                'sizes' => $panel,
            ],
            'power' => [
                'label' => 'Power',
                'blurb' => 'Load, voltage, and UPS. Stays on this page.',
                'sizes' => $panel,
            ],
            'zones' => [
                'label' => 'Zones',
                'blurb' => 'Power zones. Stays on this page.',
                'sizes' => $panel,
            ],
            'cooling' => [
                'label' => 'Cooling',
                'blurb' => 'Supply, return, and hall temperature. Stays on this page.',
                'sizes' => $panel,
            ],
            'firewalls' => [
                'label' => 'Firewalls',
                'blurb' => 'Firewall charts. With more than one firewall the tile turns its own page.',
                'sizes' => $panel,
            ],
        ];
    }

    /**
     * @return array<string, array{label:string, tiles:list<array<string,mixed>>}>
     */
    public static function templates(): array
    {
        return [
            'blank' => [
                'label' => 'Empty board',
                'tiles' => [],
            ],
            'classic' => [
                'label' => '3D and rotating pages',
                'tiles' => [
                    ['module' => 'scene', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 6],
                    [
                        'module' => 'rotate',
                        'x' => 5,
                        'y' => 0,
                        'w' => 7,
                        'h' => 6,
                        'pages' => self::PAGE_MODULES,
                    ],
                ],
            ],
            'firewalls' => [
                'label' => 'Firewalls',
                'tiles' => [
                    ['module' => 'firewalls', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 6],
                ],
            ],
        ];
    }

    public static function slugify(string $name): string
    {
        $s = strtolower(trim($name));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        if (strlen($s) > 40) {
            $s = rtrim(substr($s, 0, 40), '-');
        }
        return $s;
    }

    public static function publicUrl(string $slug, string $token = ''): string
    {
        $url = App::url('pages/noc.php') . '?view=' . rawurlencode($slug);
        if ($token !== '') {
            $url .= '&token=' . rawurlencode($token);
        }
        return $url;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function all(): array
    {
        self::ensureTable();
        $rows = Database::fetchAll(
            'SELECT view_id, name, slug, layout_json, created_at, updated_at
             FROM noc_views ORDER BY name, view_id'
        );
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = self::hydrate($row);
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        self::ensureTable();
        $row = Database::fetchOne(
            'SELECT view_id, name, slug, layout_json, created_at, updated_at FROM noc_views WHERE view_id = ?',
            [$id]
        );
        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        $slug = self::slugify($slug);
        if ($slug === '') {
            return null;
        }
        self::ensureTable();
        $row = Database::fetchOne(
            'SELECT view_id, name, slug, layout_json, created_at, updated_at FROM noc_views WHERE slug = ?',
            [$slug]
        );
        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function hydrate(array $row): array
    {
        $layout = json_decode((string)($row['layout_json'] ?? ''), true);
        $tiles = [];
        if (is_array($layout) && is_array($layout['tiles'] ?? null)) {
            $checked = self::validateLayout($layout['tiles']);
            if ($checked['ok']) {
                $tiles = $checked['tiles'];
            }
        }
        $row['tiles'] = $tiles;
        unset($row['layout_json']);
        return $row;
    }

    /**
     * @param mixed $tiles
     * @return array{ok:bool, error:string, tiles:list<array<string,mixed>>}
     */
    public static function validateLayout(mixed $tiles): array
    {
        $fail = static function (string $error): array {
            return ['ok' => false, 'error' => $error, 'tiles' => []];
        };
        if (!is_array($tiles)) {
            return $fail('The board could not be read.');
        }
        if (count($tiles) > 8) {
            return $fail('A view can hold at most 8 tiles.');
        }
        $modules = self::modules();
        $clean = [];
        $seen = [];
        foreach ($tiles as $tile) {
            if (!is_array($tile)) {
                return $fail('The board could not be read.');
            }
            $module = (string)($tile['module'] ?? '');
            if (!isset($modules[$module])) {
                return $fail('That module is not on the NOC.');
            }
            if (isset($seen[$module])) {
                return $fail($modules[$module]['label'] . ' is already on this view.');
            }
            $seen[$module] = true;
            $w = (int)($tile['w'] ?? 0);
            $h = (int)($tile['h'] ?? 0);
            $allowed = false;
            foreach ($modules[$module]['sizes'] as $size) {
                if ($size[0] === $w && $size[1] === $h) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return $fail($modules[$module]['label'] . ' is not that size.');
            }
            $x = (int)($tile['x'] ?? -1);
            $y = (int)($tile['y'] ?? -1);
            if ($x < 0 || $y < 0 || $x + $w > self::COLS || $y + $h > self::ROWS) {
                return $fail($modules[$module]['label'] . ' does not fit on the 1080p board.');
            }
            $item = ['module' => $module, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
            if ($module === 'rotate') {
                $pages = [];
                $rawPages = $tile['pages'] ?? [];
                if (!is_array($rawPages)) {
                    return $fail('Rotating pages needs at least one page.');
                }
                foreach (self::PAGE_MODULES as $page) {
                    if (in_array($page, $rawPages, true)) {
                        $pages[] = $page;
                    }
                }
                if ($pages === []) {
                    return $fail('Rotating pages needs at least one page.');
                }
                $item['pages'] = $pages;
            }
            $clean[] = $item;
        }
        foreach ($clean as $item) {
            if ($item['module'] !== 'rotate') {
                continue;
            }
            foreach ($item['pages'] as $page) {
                if (isset($seen[$page])) {
                    $label = $modules[$page]['label'] ?? $page;
                    return $fail($label . ' cannot sit on the board and inside Rotating pages.');
                }
            }
        }
        $n = count($clean);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (self::overlaps($clean[$i], $clean[$j])) {
                    return $fail('Those tiles overlap. The board cannot scroll.');
                }
            }
        }
        return ['ok' => true, 'error' => '', 'tiles' => $clean];
    }

    /** @param array{x:int,y:int,w:int,h:int} $a @param array{x:int,y:int,w:int,h:int} $b */
    public static function overlaps(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w']
            && $a['x'] + $a['w'] > $b['x']
            && $a['y'] < $b['y'] + $b['h']
            && $a['y'] + $a['h'] > $b['y'];
    }

    /**
     * @param list<array<string,mixed>> $tiles
     * @return array{ok:bool, error:string, id:int}
     */
    public static function save(int $id, string $name, string $slug, array $tiles): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || strlen($name) > 60) {
            return ['ok' => false, 'error' => 'Give the view a name of 60 characters or fewer.', 'id' => $id];
        }
        $slug = self::slugify($slug !== '' ? $slug : $name);
        if ($slug === '' || strlen($slug) < 2) {
            return ['ok' => false, 'error' => 'The URL needs at least two letters or numbers.', 'id' => $id];
        }
        $checked = self::validateLayout($tiles);
        if (!$checked['ok']) {
            return ['ok' => false, 'error' => $checked['error'], 'id' => $id];
        }
        self::ensureTable();
        $other = self::findBySlug($slug);
        if ($other !== null && (int)$other['view_id'] !== $id) {
            return ['ok' => false, 'error' => 'That URL is already used by another view.', 'id' => $id];
        }
        $json = json_encode(['tiles' => $checked['tiles']], JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'The board could not be saved.', 'id' => $id];
        }
        $now = gmdate('Y-m-d H:i:s');
        if ($id > 0) {
            if (self::find($id) === null) {
                return ['ok' => false, 'error' => 'That view was deleted.', 'id' => 0];
            }
            Database::update('noc_views', [
                'name' => $name,
                'slug' => $slug,
                'layout_json' => $json,
                'updated_at' => $now,
            ], 'view_id = ?', [$id]);
            return ['ok' => true, 'error' => '', 'id' => $id];
        }
        if (count(self::all()) >= self::MAX_VIEWS) {
            return ['ok' => false, 'error' => 'There are already ' . self::MAX_VIEWS . ' NOC views.', 'id' => 0];
        }
        $newId = Database::insert('noc_views', [
            'name' => $name,
            'slug' => $slug,
            'layout_json' => $json,
            'updated_at' => $now,
        ]);
        return ['ok' => true, 'error' => '', 'id' => $newId];
    }

    public static function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        self::ensureTable();
        Database::delete('noc_views', 'view_id = ?', [$id]);
    }

    /**
     * Pages the rotator should cycle, in catalog order.
     *
     * @param list<array<string,mixed>> $tiles
     * @return list<string>
     */
    public static function rotatePages(array $tiles): array
    {
        foreach ($tiles as $tile) {
            if (($tile['module'] ?? '') === 'rotate' && is_array($tile['pages'] ?? null)) {
                return array_values($tile['pages']);
            }
        }
        return [];
    }
}
