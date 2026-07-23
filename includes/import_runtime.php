<?php

function importNormalizeHashValue(mixed $value): string {
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_float($value)) {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    }

    return trim((string)$value);
}

function importNormalizeTypText(?string $value): string {
    $value = trim((string)$value);
    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    return trim($value);
}

function importIsFiltryByTemplate(
    ?string $projekt,
    ?string $kodPracovisite,
    ?string $nazevPracovisite,
    ?string $nazevDilu
): bool {
    $projectNorm = importNormalizeTypText($projekt);
    if ($projectNorm === 'emv filter') {
        return true;
    }

    $text = importNormalizeTypText(implode(' ', [
        (string)$nazevPracovisite,
        (string)$nazevDilu,
        (string)$projekt,
        (string)$kodPracovisite,
    ]));

    return str_contains($text, 'ostatni') || str_contains($text, 'srot');
}

function importResolveTypByTemplate(array $row): string {
    return importIsFiltryByTemplate(
        (string)($row[':projekt'] ?? ''),
        (string)($row[':kp'] ?? ''),
        (string)($row[':np'] ?? ''),
        (string)($row[':nd'] ?? '')
    ) ? 'filtry' : 'serie';
}

function importBuildHash(array $row): string {
    $orderedValues = [
        'v1',
        $row[':typ'] ?? null,
        $row[':datum'] ?? null,
        $row[':cw'] ?? null,
        $row[':rok'] ?? null,
        $row[':mesic'] ?? null,
        $row[':smena'] ?? null,
        $row[':zakaznik'] ?? null,
        $row[':projekt'] ?? null,
        $row[':verze'] ?? null,
        $row[':kp'] ?? null,
        $row[':np'] ?? null,
        $row[':nd'] ?? null,
        $row[':sap'] ?? null,
        $row[':ns'] ?? null,
        $row[':jmeno'] ?? null,
        $row[':pozice'] ?? null,
        $row[':vyr'] ?? null,
        $row[':nok'] ?? null,
        $row[':pos'] ?? null,
        $row[':cisty'] ?? null,
        $row[':prac_doba'] ?? null,
        $row[':prestas'] ?? null,
        $row[':p1'] ?? null,
        $row[':p2'] ?? null,
        $row[':p3'] ?? null,
        $row[':p4'] ?? null,
        $row[':p5'] ?? null,
        $row[':p6'] ?? null,
        $row[':p7'] ?? null,
        $row[':p8'] ?? null,
        $row[':p9'] ?? null,
        $row[':p10'] ?? null,
        $row[':o1'] ?? null,
        $row[':l1'] ?? null,
        $row[':l2'] ?? null,
        $row[':v1'] ?? null,
        $row[':v2'] ?? null,
        $row[':v3'] ?? null,
        $row[':q1'] ?? null,
        $row[':q2'] ?? null,
        $row[':q3'] ?? null,
        $row[':q4'] ?? null,
        $row[':pt'] ?? null,
        $row[':pv'] ?? null,
        $row[':pk'] ?? null,
        $row[':pl'] ?? null,
        $row[':po'] ?? null,
        $row[':pp'] ?? null,
        $row[':pnp'] ?? null,
        $row[':pc'] ?? null,
        $row[':oee_a'] ?? null,
        $row[':oee_p'] ?? null,
        $row[':oee_q'] ?? null,
        $row[':norma'] ?? null,
        $row[':cycle'] ?? null,
        $row[':final'] ?? null,
        $row[':zapsal'] ?? null,
    ];

    $normalized = array_map('importNormalizeHashValue', $orderedValues);
    return hash('sha256', implode('|', $normalized));
}

function importNextGeneratedPracovisiteId(): int {
    static $nextId = null;

    if ($nextId === null) {
        $nextId = (int)db_val('SELECT COALESCE(MAX(cislo_id), 0) + 1 FROM pracovisite');
        if ($nextId < 1) {
            $nextId = 1;
        }
    }

    return $nextId++;
}

function importResolvePracovisiteId(array $row): ?int {
    static $cache = [];

    $kod = trim((string)($row[':kp'] ?? ''));
    $nazevDilu = trim((string)($row[':nd'] ?? ''));
    $zakaznik = trim((string)($row[':zakaznik'] ?? ''));
    $projekt = trim((string)($row[':projekt'] ?? ''));
    $verze = trim((string)($row[':verze'] ?? ''));

    if ($kod === '' && $nazevDilu === '') {
        return null;
    }

    $cacheKey = implode('|', [$kod, $nazevDilu, $zakaznik]);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $existing = db_row(
        'SELECT id FROM pracovisite WHERE COALESCE(kod_pracovisite, \'\')=? AND COALESCE(nazev_dilu, \'\')=? AND COALESCE(zakaznik, \'\')=? ORDER BY aktivni DESC, id ASC LIMIT 1',
        [$kod, $nazevDilu, $zakaznik]
    );

    if ($existing && isset($existing['id'])) {
        return $cache[$cacheKey] = (int)$existing['id'];
    }

    $cisloId = importNextGeneratedPracovisiteId();
    $stmt = db()->prepare(
        'INSERT INTO pracovisite (cislo_id, zakaznik, projekt, verze, kod_pracovisite, nazev_pracovisite, nazev_dilu, sap_cislo, nakladove_stredisko, oznaceni, norma_ks_min, cyklus_min, typ, aktivni, produktivita) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $resolvedTyp = importResolveTypByTemplate($row);
    $stmt->execute([
        $cisloId,
        $zakaznik,
        $projekt,
        $verze,
        $kod,
        trim((string)($row[':np'] ?? '')) ?: $kod,
        $nazevDilu,
        trim((string)($row[':sap'] ?? '')),
        $row[':ns'] ?? null,
        trim((string)($row[':final'] ?? '')) ?: 'Pol',
        $row[':norma'] ?? null,
        $row[':cycle'] ?? null,
        $resolvedTyp,
        1,
        1,
    ]);

    return $cache[$cacheKey] = (int)db()->lastInsertId();
}

function importNormalizePeopleList(?string $value, int $maxLen = 150): ?string {
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $parts = preg_split('/\s*[;,]+\s*/u', $value) ?: [];
    $parts = array_values(array_unique(array_filter(array_map(static function (string $part): string {
        return trim($part, " \t\n\r\0\x0B,;");
    }, $parts), static fn(string $part): bool => $part !== '')));

    if (!$parts) {
        return null;
    }

    $normalized = implode(', ', $parts);
    return function_exists('mb_substr') ? mb_substr($normalized, 0, $maxLen) : substr($normalized, 0, $maxLen);
}

function importPrepareZapisyRow(array $row): array {
    $row[':typ'] = importResolveTypByTemplate($row);

    $row[':jmeno'] = importNormalizePeopleList(isset($row[':jmeno']) ? (string)$row[':jmeno'] : null, 150);
    $row[':pozice'] = importNormalizePeopleList(isset($row[':pozice']) ? (string)$row[':pozice'] : null, 100);

    $row[':dobre'] = max(
        0,
        (int)($row[':vyr'] ?? 0) - (int)($row[':nok'] ?? 0) - (int)($row[':pos'] ?? 0)
    );

    $a = isset($row[':oee_a']) && $row[':oee_a'] !== null ? (float)$row[':oee_a'] : null;
    $p = isset($row[':oee_p']) && $row[':oee_p'] !== null ? (float)$row[':oee_p'] : null;
    $q = isset($row[':oee_q']) && $row[':oee_q'] !== null ? (float)$row[':oee_q'] : null;
    $oee = isset($row[':oee']) && $row[':oee'] !== null ? (float)$row[':oee'] : null;

    if ($a !== null && $p !== null && $q !== null) {
        $row[':oee'] = min(1.0, max(0.0, round($a * $p * $q, 8)));
    } elseif ($oee !== null) {
        $row[':oee'] = min(1.0, max(0.0, round($oee, 8)));
    }

    $row[':prac_id'] = importResolvePracovisiteId($row);
    $row[':import_hash'] = importBuildHash($row);
    return $row;
}

function importSqlBindParams(array $row): array {
    $params = [];
    foreach ($row as $key => $value) {
        if (is_string($key) && str_starts_with($key, ':')) {
            $params[$key] = $value;
        }
    }
    return $params;
}

function importSqlBindParamsForZapisyInsert(array $row): array {
    $allowed = [
        ':typ', ':prac_id', ':datum', ':cw', ':rok', ':mesic', ':smena', ':zakaznik', ':projekt', ':verze',
        ':kp', ':np', ':nd', ':sap', ':ns', ':jmeno', ':pozice',
        ':vyr', ':nok', ':pos', ':dobre',
        ':cisty', ':prac_doba', ':prestas',
        ':oee_a', ':oee_p', ':oee_q', ':oee',
        ':norma', ':cycle', ':final', ':zapsal', ':import_hash',
    ];
    $params = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $row)) {
            $params[$key] = $row[$key];
        }
    }
    return $params;
}
