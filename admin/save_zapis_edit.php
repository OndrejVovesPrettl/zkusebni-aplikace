<?php
// admin/save_zapis_edit.php – AJAX handler pro editaci záznamu
require_once dirname(__DIR__) . '/includes/config.php';
header('Content-Type: application/json; charset=utf-8');

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonOut(['ok'=>false,'error'=>'Method not allowed'],405); }
if (!verifyCsrfToken(post('_csrf'))) { jsonOut(['ok'=>false,'error'=>'CSRF'],403); }
if (!canWrite()) {
    logAction('EDIT_ZAPIS_DENIED', 'zapisy', null, [], [], t('error_insufficient_permission'));
    jsonOut(['ok'=>false,'error'=>t('error_insufficient_permission_write')],403);
}

$id = (int)post('id');
if (!$id) jsonOut(['ok'=>false,'error'=>'Missing ID'],422);

$existing = db_row('SELECT * FROM zapisy WHERE id=?', [$id]);
if (!$existing) jsonOut(['ok'=>false,'error'=>t('record_not_found')],404);

try {
    $pdo = db();
    $pdo->beginTransaction();

    $typ   = in_array(post('typ'), ['serie', 'filtry', 'vstrikovna'], true) ? post('typ') : 'serie';
    $datum = post('datum') ?: date('Y-m-d');
    if (!validateDate($datum)) jsonOut(['ok'=>false,'error'=>'Invalid date'],422);

    $cw    = getCW($datum);
    $mesic = (int)date('n', strtotime($datum));
    $rok   = (int)date('Y', strtotime($datum));
    $smena = post('smena','O8');
    $skupina = in_array(post('skupina'), ['A', 'B', 'C', 'D']) ? post('skupina') : null;
    $pracId = post('pracovisite_id') ? (int)post('pracovisite_id') : null;
    $pw = $pracId ? db_row('SELECT * FROM pracovisite WHERE id=?',[$pracId]) : null;

    $casPr = post('cas_prichodu') ? $datum.' '.post('cas_prichodu').':00' : null;
    $casOd = post('cas_odchodu')  ? $datum.' '.post('cas_odchodu').':00'  : null;

    $vyrobeno  = max(0,(int)post('vyrobeno_ks',0));
    $nokKs     = max(0,(int)post('nok_ks',0));
    $kPos      = max(0,(int)post('k_posouzeni',0));
    $dobre     = max(0,$vyrobeno - $nokKs);
    $pracDoba  = post('pracovni_doba_min')!=='' ? (int)post('pracovni_doba_min') : null;
    $pauza     = max(0,(int)post('pauza_min',0));
    $prestas   = max(0,(int)post('prestas_min',0));
    $cistyCas  = post('cisty_cas_min')!=='' ? (int)post('cisty_cas_min') : null;
    $tech      = max(0, (int)post('tech', 0));
    $blok      = max(0, (int)post('blok', 0));
    $tnz       = max(0, (int)post('tnz', 0));

    // Centrálně zadané prostoje
    $prostoje = [];
    foreach (($_POST['prostoje'] ?? []) as $rawProstoj) {
        if (!is_array($rawProstoj)) continue;
        $prostoje[] = [
            'typ_kod'  => trim((string)($rawProstoj['typ_kod'] ?? '')),
            'cas_od'   => trim((string)($rawProstoj['cas_od'] ?? '')),
            'cas_do'   => trim((string)($rawProstoj['cas_do'] ?? '')),
            'minuty'   => max(0,(int)($rawProstoj['minuty'] ?? 0)),
            'pocet'    => max(1,(int)($rawProstoj['pocet'] ?? 1)),
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

        // C) Kvalita = Dobré ks / Vyrobené ks
        if ($vyrobeno > 0) {
            $oeeQ = min(1.0, max(0.0, ($vyrobeno - $nokKs) / $vyrobeno));
        }

        // D) Celkové OEE
        if ($oeeA !== null && $oeeP !== null && $oeeQ !== null) {
            $oeeTotal = min(1.0, max(0.0, $oeeA * $oeeP * $oeeQ));
        }
    }

    // Pracovníci
    $workerRows = [];
    foreach (($_POST['workers'] ?? []) as $workerRow) {
        if (!is_array($workerRow)) continue;
        $pracovnikId = !empty($workerRow['pracovnik_id']) ? (int)$workerRow['pracovnik_id'] : null;
        $jmenoRucne  = trim((string)($workerRow['jmeno_rucne'] ?? ''));
        $pozice      = trim((string)($workerRow['pracovni_pozice'] ?? ''));
        $jmeno = $jmenoRucne;
        if ($pracovnikId) {
            $pw2 = db_row('SELECT jmeno FROM pracovnici WHERE id=?', [$pracovnikId]);
            if ($pw2 && !empty($pw2['jmeno'])) $jmeno = $pw2['jmeno'];
        }
        $jmeno = normalizePeopleListValue((string)$jmeno, 150);
        if ($jmeno === null) continue;
        $workerRows[] = [
            'pracovnik_id'    => $pracovnikId,
            'jmeno'           => $jmeno,
            'pracovni_pozice' => $pozice !== '' ? $pozice : null,
        ];
    }

    $workerNames     = array_map(static fn(array $w): string => $w['jmeno'], $workerRows);
    $workerPositions = array_values(array_unique(array_filter(array_map(static fn(array $w): ?string => $w['pracovni_pozice'], $workerRows))));
    $jmeno           = $workerNames ? normalizePeopleListValue(implode(', ', $workerNames), 150) : null;
    $poziceSummary   = $workerPositions ? normalizePeopleListValue(implode(', ', $workerPositions), 100) : null;

    // UPDATE zapisy
    $stmt = $pdo->prepare("UPDATE zapisy SET
        typ=:typ, pracovisite_id=:prac_id, cw=:cw, datum=:datum, rok=:rok, mesic=:mesic, smena=:smena, skupina=:skupina,
        cas_prichodu=:cas_pr, cas_odchodu=:cas_od,
        pracovni_doba_min=:prac_doba, prestas_min=:prestas, pauza_min=:pauza,
        jmeno=:jmeno, pracovni_pozice=:pozice,
        zakaznik=:zakaznik, projekt=:projekt, verze=:verze,
        kod_pracovisite=:kp, nazev_pracovisite=:np, nazev_dilu=:nd,
        sap_cislo=:sap, nakladove_stredisko=:ns, final_typ=:final,
        vyrobeno_ks=:vyr, nok_ks=:nok, k_posouzeni=:pos, vyrobeno_dobre_ks=:dobre,
        cisty_cas_min=:cisty,
        oee_dostupnost=:oee_a, oee_vykon=:oee_p, oee_kvalita=:oee_q, oee_procent=:oee,
        norma_ks=:norma, cycle_time_min=:cycle,
        tech=:tech, blok=:blok, tnz=:tnz,
        poznamky=:pozn, poznamka_zapisujiciho=:pozn_zap
        WHERE id=:id");

    $stmt->execute([
        ':typ'=>$typ, ':prac_id'=>$pracId, ':cw'=>$cw, ':datum'=>$datum,
        ':rok'=>$rok, ':mesic'=>$mesic, ':smena'=>$smena, ':skupina'=>$skupina,
        ':cas_pr'=>$casPr, ':cas_od'=>$casOd,
        ':prac_doba'=>$pracDoba, ':prestas'=>$prestas, ':pauza'=>$pauza,
        ':jmeno'=>$jmeno, ':pozice'=>$poziceSummary,
        ':zakaznik'=>$pw['zakaznik']??null, ':projekt'=>$pw['projekt']??null,
        ':verze'=>$pw['verze']??null, ':kp'=>$pw['kod_pracovisite']??null,
        ':np'=>$pw['nazev_pracovisite']??null, ':nd'=>$pw['nazev_dilu']??null,
        ':sap'=>$pw['sap_cislo']??null, ':ns'=>$pw['nakladove_stredisko']??null,
        ':final'=>$pw['oznaceni']??null,
        ':vyr'=>$vyrobeno, ':nok'=>$nokKs, ':pos'=>$kPos, ':dobre'=>$dobre, ':cisty'=>$cistyCas,
        ':oee_a'=>$oeeA,':oee_p'=>$oeeP,':oee_q'=>$oeeQ,':oee'=>$oeeTotal,
        ':norma'=>$normaKs,':cycle'=>$cyclMin,
        ':tech'=>$tech, ':blok'=>$blok, ':tnz'=>$tnz,
        ':pozn'=>post('poznamky')?:null,
        ':pozn_zap'=>post('poznamka_zapisujiciho')?:null,
        ':id'=>$id,
    ]);

    syncZapisyPracovnici($pdo, $id, $workerRows);

    // Technické prostoje – smazat a znovu vložit
    $pdo->prepare('DELETE FROM technicke_prostoje WHERE zapis_id=?')->execute([$id]);
    if ($prostoje) {
        $ptStmt = $pdo->prepare('INSERT INTO technicke_prostoje (zapis_id,typ_kod,cas_od,cas_do,minuty,pocet,poznamka) VALUES (?,?,?,?,?,?,?)');
        foreach($prostoje as $pr) {
            if (empty($pr['typ_kod'])) continue;
            $ptStmt->execute([$id,$pr['typ_kod'],$pr['cas_od']?:null,$pr['cas_do']?:null,max(0,(int)$pr['minuty']),max(1,(int)($pr['pocet']??1)),$pr['poznamka']??null]);
        }
    }

    saveZapisyProstoje($pdo, $id, $codeMinutes, $prostoje);

    // NOK – smazat a znovu vložit
    $pdo->prepare('DELETE FROM zapis_nok WHERE zapis_id=?')->execute([$id]);
    $nokStmt = $pdo->prepare('INSERT INTO zapis_nok (zapis_id,nok_kod_id,pocet) VALUES (?,?,?)');
    foreach(($_POST['nok']??[]) as $kodId=>$pocet) {
        $pocet=(int)$pocet; if($pocet<=0) continue;
        $nokStmt->execute([$id,(int)$kodId,$pocet]);
    }

    // Hodinové sledování – smazat a znovu vložit
    $pdo->prepare('DELETE FROM hodinove_sledovani WHERE zapis_id=?')->execute([$id]);
    $hodStmt = $pdo->prepare('INSERT INTO hodinove_sledovani (zapis_id,hodina_od,p_minuty,t_minuty,vyrobeno) VALUES (?,?,?,?,?)');
    foreach(($_POST['hodiny']??[]) as $slot=>$h) {
        $vyr=(int)($h['vyrobeno']??0); $pm=(int)($h['p_minuty']??0); $tm=(int)($h['t_minuty']??0);
        if($vyr||$pm||$tm) $hodStmt->execute([$id,substr($slot,0,10),$pm,$tm,$vyr]);
    }

    $pdo->commit();

    logAction(
        'ZAPIS_EDIT',
        'zapisy',
        $id,
        ['vyrobeno_ks'=>$existing['vyrobeno_ks'],'nok_ks'=>$existing['nok_ks'],'oee_procent'=>$existing['oee_procent'], 'skupina'=>$existing['skupina']],
        ['typ'=>$typ,'datum'=>$datum,'smena'=>$smena,'skupina'=>$skupina,'vyrobeno_ks'=>$vyrobeno,'nok_ks'=>$nokKs,'oee_procent'=>$oeeTotal?round($oeeTotal,4):null],
        t('edit')." #$id – ".($pw['nazev_dilu']??'—')." ($vyrobeno ks)"
    );

    jsonOut(['ok'=>true,'message'=>t('saved').' (ID: '.$id.')']);

} catch(Exception $e) {
    if(isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();
    jsonOut(['ok'=>false,'error'=>$e->getMessage()],500);
}
