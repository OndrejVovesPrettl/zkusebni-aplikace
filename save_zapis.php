<?php
// save_zapis.php – AJAX handler pro uložení záznamu
require_once __DIR__ . '/includes/config.php';
header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if (!$lastError) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($lastError['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    if (ob_get_length()) {
        @ob_clean();
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'ok' => false,
        'error' => 'Fatal error: ' . (string)($lastError['message'] ?? 'Unknown fatal error'),
    ], JSON_UNESCAPED_UNICODE);
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonOut(['ok'=>false,'error'=>'Method not allowed'],405); }
if (!verifyCsrfToken(post('_csrf'))) { jsonOut(['ok'=>false,'error'=>'CSRF'],403); }

function normalizePeopleListValue(?string $value, int $maxLen = 150): ?string {
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

// Ověřit právo na zápis (superadmin nebo write role)
if (!canWrite()) {
    logAction('SAVE_ZAPIS_DENIED', 'zapisy', null, [], [], t('error_insufficient_permission'));
    jsonOut(['ok'=>false,'error'=>t('error_insufficient_permission_write')],403);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $typ   = in_array(post('typ'), ['serie', 'filtry', 'vstrikovna'], true) ? post('typ') : 'serie';
    $datum = post('datum') ?: date('Y-m-d');
    if (!validateDate($datum)) {
        jsonOut(['ok'=>false,'error'=>'Invalid date'],422);
    }
    $cw    = getCW($datum);
    $mesic = (int)date('n', strtotime($datum));
    $rok   = (int)date('Y', strtotime($datum));
    $smena = post('smena','O8');
    // Načtení a bezpečnostní kontrola skupiny (povoleno jen A, B, C, D)
    $skupina = in_array(post('skupina'), ['A', 'B', 'C', 'D']) ? post('skupina') : null;

    // Pracoviště info – načteme z DB pokud vybrané
    $pracId = post('pracovisite_id') ? (int)post('pracovisite_id') : null;
    $pw = $pracId ? db_row('SELECT * FROM pracovisite WHERE id=?',[$pracId]) : null;

    $casPr = post('cas_prichodu') ? $datum.' '.post('cas_prichodu').':00' : null;
    $casOd = post('cas_odchodu')  ? $datum.' '.post('cas_odchodu').':00'  : null;

    $vyrobeno  = max(0,(int)post('vyrobeno_ks',0));
    $nokKs     = max(0,(int)post('nok_ks',0));
    
    // --- ZÁCHRANNÁ BRZDA: NOK nesmí být větší než Vyrobeno ---
    if ($nokKs > $vyrobeno) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonOut(['ok'=>false,'error'=>'Nelze zadat více vadných kusů (NOK) než celkem vyrobených.'], 422);
    }

    $nokCounts = [];
    foreach (($_POST['nok'] ?? []) as $nokId => $quantity) {
        $nokCounts[(string)(int)$nokId] = max(0, (int)$quantity);
    }
    $nokOperatorExcluded = [];
    foreach (($_POST['nok_operator_excluded'] ?? []) as $nokId => $excluded) {
        $nokOperatorExcluded[(string)(int)$nokId] = (int)$excluded === 1 ? 1 : 0;
    }
    $nokOperatorDefaultsByAfo = getNokAfoOperatorExclusionMap();
    $nokDefaults = $pw ? ($nokOperatorDefaultsByAfo[(string)$pw['cislo_id']] ?? []) : [];
    $nokOperatorKs = calculateNokOperatorQuantity(
        $nokCounts,
        $nokOperatorExcluded,
        $nokDefaults
    );
    $kPos      = max(0,(int)post('k_posouzeni',0));
    $tech      = max(0, (int)post('tech', 0));
    $blok      = max(0, (int)post('blok', 0));
    $tnz       = max(0, (int)post('tnz', 0));
    
    // Dobré kusy: Vyrobeno - NOK celkem (zde už je Tech i TNZ) - Blok
    $dobre     = max(0, $vyrobeno - $nokKs - $blok);
    
    $pracDoba  = post('pracovni_doba_min')!=='' ? (int)post('pracovni_doba_min') : null;
    $pauza     = max(0,(int)post('pauza_min',0));
    $prestas   = max(0,(int)post('prestas_min',0));
    $cistyCas  = post('cisty_cas_min')!=='' ? (int)post('cisty_cas_min') : null;
    // Centrálně zadané prostoje z řádků
    $prostoje = [];
    foreach (($_POST['prostoje'] ?? []) as $rawProstoj) {
        if (!is_array($rawProstoj)) {
            continue;
        }
        $prostoje[] = [
            'typ_kod' => trim((string)($rawProstoj['typ_kod'] ?? '')),
            'cas_od' => trim((string)($rawProstoj['cas_od'] ?? '')),
            'cas_do' => trim((string)($rawProstoj['cas_do'] ?? '')),
            'minuty' => max(0, (int)($rawProstoj['minuty'] ?? 0)),
            'pocet' => max(1, (int)($rawProstoj['pocet'] ?? 1)),
            'poznamka' => trim((string)($rawProstoj['poznamka'] ?? '')),
        ];
    }
    $codeMinutes = buildZapisyDowntimeCodeMinutes([], $prostoje);
    $prostPlan = $prostTech = $prostVyr = $prostKval = $prostLog = $prostOst = 0;
    foreach ($codeMinutes as $code => $minutes) {
        $minutes = max(0, (int)$minutes);
        switch (getZapisyDowntimeCategoryForCode($code)) {
            case 'plan':
                $prostPlan += $minutes;
                break;
            case 'vyr':
                $prostVyr += $minutes;
                break;
            case 'kval':
                $prostKval += $minutes;
                break;
            case 'log':
                $prostLog += $minutes;
                break;
            case 'ost':
                $prostOst += $minutes;
                break;
            default:
                $prostTech += $minutes;
                break;
        }
    }

    $prostNepl = $prostTech + $prostVyr + $prostKval + $prostLog + $prostOst;
    $prostCelk = $prostPlan + $prostNepl;

    // OEE výpočet
    $oeeA = $oeeP = $oeeQ = $oeeTotal = null;
    $normaKs = $pw['norma_ks_min'] ?? null;
    $cyclMin = $pw['cyklus_min']   ?? null;

    if ($pracDoba !== null && $pracDoba > 0) {
        $pauzaVal     = max(0, $pauza);
        $cistyVal     = $cistyCas !== null ? $cistyCas : max(0, $pracDoba - $pauzaVal);
        
        // 1. Plánovaný čas provozu = Pracovní doba - Pauza - Plánované prostoje
        $planovanyCas = max(0, $pracDoba - $pauzaVal - $prostPlan);
        
        // 2. Skutečný čas běhu (provozní čas) = Plánovaný čas - Neplánované prostoje
        $provozniCas  = max(0, $planovanyCas - $prostNepl);

        // A) Dostupnost = Skutečný čas běhu / Plánovaný čas provozu
        if ($planovanyCas > 0) {
            $oeeA = min(1.0, max(0.0, $provozniCas / $planovanyCas));
        }

        // B) Výkonnost = Vyrobené ks / (Skutečný čas běhu / Cyklus)
        if ($cyclMin > 0 && $provozniCas > 0) {
            $oeeP = min(1.5, max(0.0, $vyrobeno / ($provozniCas / $cyclMin)));
        } elseif ($provozniCas == 0 && $vyrobeno > 0) {
            $oeeP = 0.0;
        }

        // C) Kvalita = Srážíme NOK operátora a Tech kusy
        if ($vyrobeno > 0) {
            $oeeQ = min(1.0, max(0.0, ($vyrobeno - ($nokOperatorKs + $tech)) / $vyrobeno));
        }

        // D) Celkové OEE
        if ($oeeA !== null && $oeeP !== null && $oeeQ !== null) {
            $oeeTotal = min(1.0, max(0.0, $oeeA * $oeeP * $oeeQ));
        }
    }

    $workerRows = [];
    foreach (($_POST['workers'] ?? []) as $workerRow) {
        if (!is_array($workerRow)) {
            continue;
        }

        $pracovnikId = !empty($workerRow['pracovnik_id']) ? (int)$workerRow['pracovnik_id'] : null;
        $jmenoRucne = trim((string)($workerRow['jmeno_rucne'] ?? ''));
        $pozice = trim((string)($workerRow['pracovni_pozice'] ?? ''));
        $jmeno = $jmenoRucne;

        if ($pracovnikId) {
            $pw2 = db_row('SELECT jmeno FROM pracovnici WHERE id=?', [$pracovnikId]);
            if ($pw2 && !empty($pw2['jmeno'])) {
                $jmeno = $pw2['jmeno'];
            }
        }

        $jmeno = normalizePeopleListValue((string)$jmeno, 150);
        if ($jmeno === null) {
            continue;
        }

        $workerRows[] = [
            'pracovnik_id' => $pracovnikId,
            'jmeno' => $jmeno,
            'pracovni_pozice' => $pozice !== '' ? $pozice : null,
        ];
    }

    if (!$workerRows) {
        $pracovnikId = post('pracovnik_id') ? (int)post('pracovnik_id') : null;
        $jmenoRucne = trim((string)post('jmeno_rucne', ''));
        $jmeno = $jmenoRucne;
        if ($pracovnikId) {
            $pw2 = db_row('SELECT jmeno FROM pracovnici WHERE id=?',[$pracovnikId]);
            if ($pw2 && !empty($pw2['jmeno'])) {
                $jmeno = $pw2['jmeno'];
            }
        }
        $jmeno = normalizePeopleListValue((string)$jmeno, 150);
        if ($jmeno !== null) {
            $workerRows[] = [
                'pracovnik_id' => $pracovnikId,
                'jmeno' => $jmeno,
                'pracovni_pozice' => post('pracovni_pozice') ?: null,
            ];
        }
    }

    $workerNames = array_map(static fn(array $worker): string => $worker['jmeno'], $workerRows);
    $workerPositions = array_values(array_unique(array_filter(array_map(static fn(array $worker): ?string => $worker['pracovni_pozice'], $workerRows))));
    $jmeno = $workerNames ? normalizePeopleListValue(implode(', ', $workerNames), 150) : null;
    $poziceSummary = $workerPositions ? normalizePeopleListValue(implode(', ', $workerPositions), 100) : null;

    $stmt = $pdo->prepare("INSERT INTO zapisy
        (typ,pracovisite_id,cw,datum,rok,mesic,smena,skupina,cas_prichodu,cas_odchodu,
         pracovni_doba_min,prestas_min,pauza_min,jmeno,pracovni_pozice,
         zakaznik,projekt,verze,kod_pracovisite,nazev_pracovisite,nazev_dilu,
         sap_cislo,nakladove_stredisko,final_typ,
         vyrobeno_ks,nok_ks,nok_operator_ks,k_posouzeni,vyrobeno_dobre_ks,cisty_cas_min,
         oee_dostupnost,oee_vykon,oee_kvalita,oee_procent,
         norma_ks,cycle_time_min,tech,blok,tnz,
         poznamky,zapsal,poznamka_zapisujiciho)
        VALUES
        (:typ,:prac_id,:cw,:datum,:rok,:mesic,:smena,:skupina,:cas_pr,:cas_od,
         :prac_doba,:prestas,:pauza,:jmeno,:pozice,
         :zakaznik,:projekt,:verze,:kp,:np,:nd,:sap,:ns,:final,
         :vyr,:nok,:nok_operator,:pos,:dobre,:cisty,
         :oee_a,:oee_p,:oee_q,:oee,
         :norma,:cycle,:tech,:blok,:tnz,
         :pozn,:zapsal,:pozn_zap)");

    $stmt->execute([
        ':typ'=>$typ,':prac_id'=>$pracId,':cw'=>$cw,':datum'=>$datum,
        ':rok'=>$rok,':mesic'=>$mesic,':smena'=>$smena,':skupina'=>$skupina,
        ':cas_pr'=>$casPr,':cas_od'=>$casOd,
        ':prac_doba'=>$pracDoba,':prestas'=>$prestas,':pauza'=>$pauza,
        ':jmeno'=>$jmeno,':pozice'=>$poziceSummary,
        ':zakaznik'=>$pw['zakaznik']??null,':projekt'=>$pw['projekt']??null,
        ':verze'=>$pw['verze']??null,':kp'=>$pw['kod_pracovisite']??null,
        ':np'=>$pw['nazev_pracovisite']??null,':nd'=>$pw['nazev_dilu']??null,
        ':sap'=>$pw['sap_cislo']??null,':ns'=>$pw['nakladove_stredisko']??null,
        ':final'=>$pw['oznaceni']??null,
        ':vyr'=>$vyrobeno,':nok'=>$nokKs,':nok_operator'=>$nokOperatorKs,':pos'=>$kPos,':dobre'=>$dobre,':cisty'=>$cistyCas,
        ':oee_a'=>$oeeA,':oee_p'=>$oeeP,':oee_q'=>$oeeQ,':oee'=>$oeeTotal,
        ':norma'=>$normaKs,':cycle'=>$cyclMin,
        ':tech'=>$tech, ':blok'=>$blok, ':tnz'=>$tnz,
        ':pozn'=>post('poznamky')?:null,
        ':zapsal'=>post('zapsal')?:null,
        ':pozn_zap'=>post('poznamka_zapisujiciho')?:null,
    ]);

    $zapisId = (int)$pdo->lastInsertId();

    syncZapisyPracovnici($pdo, $zapisId, $workerRows);

    // Technické prostoje
    $ptStmt = $pdo->prepare('INSERT INTO technicke_prostoje (zapis_id,typ_kod,cas_od,cas_do,minuty,pocet,poznamka) VALUES (?,?,?,?,?,?,?)');
    foreach($prostoje as $pr) {
        if (empty($pr['typ_kod'])||!isset($pr['minuty'])) continue;
        $ptStmt->execute([$zapisId,$pr['typ_kod'],$pr['cas_od']?:null,$pr['cas_do']?:null,max(0,(int)$pr['minuty']),max(1,(int)($pr['pocet']??1)),$pr['poznamka']??null]);
    }

    saveZapisyProstoje($pdo, $zapisId, $codeMinutes, $prostoje);

    // NOK záznamy
    $nokItemsTotal = 0;
    foreach (($_POST['nok'] ?? []) as $pocetRaw) {
        $nokItemsTotal += max(0, (int)$pocetRaw);
    }
    
    // Do vysvětlených kusů přičítáme Tech
    $vysvetleno = $nokItemsTotal + $tech;
    
    if ($vysvetleno !== $nokKs) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        jsonOut(['ok'=>false,'error'=>t('nok_mismatch') . " ({$vysvetleno}/{$nokKs})"], 422);
    }

    $nokStmt = $pdo->prepare('INSERT INTO zapis_nok (zapis_id,nok_kod_id,pocet,operator_excluded) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE pocet=pocet+VALUES(pocet), operator_excluded=VALUES(operator_excluded)');
    foreach($nokCounts as $kodId=>$pocet) {
        $pocet=(int)$pocet; if($pocet<=0) continue;
        $excluded = $nokOperatorExcluded[(string)$kodId] ?? $nokDefaults[(string)$kodId] ?? 0;
        $nokStmt->execute([$zapisId,(int)$kodId,$pocet,(int)$excluded === 1 ? 1 : 0]);
    }

    // Hodinové sledování
    $hodStmt = $pdo->prepare('INSERT INTO hodinove_sledovani (zapis_id,hodina_od,p_minuty,t_minuty,vyrobeno) VALUES (?,?,?,?,?)');
    foreach(($_POST['hodiny']??[]) as $slot=>$h) {
        $vyr=(int)($h['vyrobeno']??0); $pm=(int)($h['p_minuty']??0); $tm=(int)($h['t_minuty']??0);
        if($vyr||$pm||$tm) $hodStmt->execute([$zapisId,substr($slot,0,10),$pm,$tm,$vyr]);
    }

    $pdo->commit();

    $auditDil = (string)($pw['nazev_dilu'] ?? '-');
    $auditOeePct = $oeeTotal ? round($oeeTotal * 100, 1) : 0;
    
    // Audit log
    logAction(
        'ZAPIS_CREATE',
        'zapisy',
        $zapisId,
        null,
        [
            'typ' => $typ,
            'datum' => $datum,
            'smena' => $smena,
            'skupina' => $skupina,
            'pracovisite' => $pw['nazev_pracovisite'] ?? '',
            'vyrobeno_ks' => $vyrobeno,
            'nok_ks' => $nokKs,
            'oee_procent' => $oeeTotal ? round($oeeTotal, 4) : null
        ],
        t('new_record_log') . ": " . $auditDil . " (" . $vyrobeno . " ks, OEE: " . $auditOeePct . "%)"
    );
    
    jsonOut(['ok'=>true,'zapis_id'=>$zapisId,'message'=>t('new_record_saved').' (ID: '.$zapisId.')']);

} catch (Throwable $e) {
    if(isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
    $code = $e instanceof RuntimeException ? 422 : 500;
    jsonOut(['ok'=>false,'error'=>$e->getMessage()], $code);
}
