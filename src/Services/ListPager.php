<?php
/**
 * List pagination + CSV export of the current filter (not just the visible page).
 */
declare(strict_types=1);

class ListPager
{
    public const DEFAULT_PER = 50;
    /** @var list<int> */
    public const CHOICES = [25, 50, 100, 200];
    public const CSV_MAX = 10000;

    public static function wantsCsv(): bool
    {
        return strtolower(trim((string)($_GET['export'] ?? ''))) === 'csv';
    }

    /**
     * @return array{
     *   page:int,per_page:int,offset:int,total:int,pages:int,from:int,to:int
     * }
     */
    public static function fromRequest(int $total): array
    {
        $total = max(0, $total);
        $per = (int)($_GET['per'] ?? self::DEFAULT_PER);
        if (!in_array($per, self::CHOICES, true)) {
            $per = self::DEFAULT_PER;
        }
        $pages = max(1, (int)ceil(($total ?: 1) / $per));
        if ($total === 0) {
            $pages = 1;
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $per;
        $from = $total === 0 ? 0 : ($offset + 1);
        $to = min($total, $offset + $per);
        return [
            'page' => $page,
            'per_page' => $per,
            'offset' => $offset,
            'total' => $total,
            'pages' => $pages,
            'from' => $from,
            'to' => $to,
        ];
    }

    public static function stripOrderBy(string $sql): string
    {
        $stripped = preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', rtrim($sql));
        return is_string($stripped) && $stripped !== '' ? $stripped : $sql;
    }

    public static function applyLimit(string $orderedSql, int $offset, int $limit): string
    {
        return rtrim($orderedSql) . ' OFFSET ' . max(0, $offset)
            . ' ROWS FETCH NEXT ' . max(1, $limit) . ' ROWS ONLY';
    }

    /** @param list<mixed> $params */
    public static function count(string $orderedSql, array $params = []): int
    {
        $inner = self::stripOrderBy($orderedSql);
        try {
            return (int)Database::fetchValue(
                'SELECT COUNT(*) FROM (' . $inner . ') AS _list_count',
                $params
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Query keys to keep on pager / export / search links (drops page).
     *
     * @param list<string> $keys
     * @return array<string,string>
     */
    public static function keepGet(array $keys): array
    {
        $keep = [];
        foreach ($keys as $k) {
            if (!isset($_GET[$k])) {
                continue;
            }
            $v = $_GET[$k];
            if ($v === '' || $v === null) {
                continue;
            }
            if ($k === 'page') {
                continue;
            }
            $keep[$k] = is_array($v) ? implode(',', $v) : (string)$v;
        }
        $per = (int)($_GET['per'] ?? 0);
        if (in_array($per, self::CHOICES, true) && $per !== self::DEFAULT_PER) {
            $keep['per'] = (string)$per;
        }
        return $keep;
    }

    public static function href(string $path, array $keep, array $overrides = []): string
    {
        $q = $keep;
        foreach ($overrides as $k => $v) {
            if ($v === null || $v === '') {
                unset($q[$k]);
            } else {
                $q[$k] = (string)$v;
            }
        }
        $qs = http_build_query($q);
        return App::url($path . ($qs !== '' ? '?' . $qs : ''));
    }

    /**
     * @param list<string> $headers
     * @param list<list<mixed>> $rows
     */
    public static function sendCsv(string $filename, array $headers, array $rows): never
    {
        $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $filename) ?: 'export.csv';
        if (!str_ends_with(strtolower($filename), '.csv')) {
            $filename .= '.csv';
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            App::json(['error' => 'Cannot write CSV'], 500);
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $cell) {
                if ($cell === null) {
                    $line[] = '';
                } elseif (is_bool($cell)) {
                    $line[] = $cell ? '1' : '0';
                } else {
                    $line[] = (string)$cell;
                }
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }
}
