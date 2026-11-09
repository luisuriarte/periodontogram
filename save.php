<?php
/**
 * save.php — Periodontogram V2
 * Accepts v2 payload (teeth + sites 1..6) and legacy payload (details).
 * Writes to form_periodontogram_tooth / form_periodontogram_site when available,
 * and dual-writes to legacy detail table for backward compatibility.
 * All output strings via xl()/xlt() (English source).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");
require_once("$srcdir/translation.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Services\FormService;

header('Content-Type: application/json');

$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"] ?? '', $session)) {
    CsrfUtils::csrfNotVerified();
}

$pid = (int)($session->get('pid') ?? 0);
$encounter = (int)($session->get('encounter') ?? 0);
$user = $session->get('authUser') ?? '';
$groupname = $session->get('authGroup') ?? $session->get('authProvider') ?? '';
$formid = (int)($_POST['formid'] ?? 0);

if (!$pid || !$encounter) {
    echo json_encode(['success' => false, 'message' => xl('No encounter or patient specified')]);
    exit;
}

$payload = json_decode($_POST['payload'] ?? '{}', true);
if (empty($payload)) {
    echo json_encode(['success' => false, 'message' => xl('No data provided')]);
    exit;
}

$diagnosis = $payload['diagnosis'] ?? null;
$grade = $payload['grade'] ?? null;
$notes = $payload['notes'] ?? null;
$teeth = $payload['teeth'] ?? null;       // v2
$details = $payload['details'] ?? [];     // legacy / dual-write

// ---- Statistics (computed server-side from v2 payload if present) ----
$bop_total = 0; $bop_sites = 0; $pi_total = 0; $pi_sites = 0;
$avgPd = null; $avgAl = null;
$teethPresent = 0; $teethImplant = 0;

if (is_array($teeth)) {
    $pdSum=0; $pdN=0; $alSum=0; $alN=0;
    foreach ($teeth as $tooth) {
        $status = $tooth['status'] ?? 'present';
        if ($status === 'missing') continue;
        if ($status === 'implant') $teethImplant++; else $teethPresent++;
        foreach (($tooth['sites'] ?? []) as $s) {
            $pd = isset($s['pd']) && $s['pd'] !== null ? (int)$s['pd'] : null;
            $gm = isset($s['gm']) && $s['gm'] !== null ? (int)$s['gm'] : null;
            if ($pd !== null) {
                $pdSum += $pd; $pdN++;
                $al = $pd - ($gm ?? 0);
                $alSum += $al; $alN++;
                $bop_sites++; $pi_sites++;
                if (!empty($s['bop'])) $bop_total++;
                if (!empty($s['plaque'])) $pi_total++;
            }
        }
    }
    $avgPd = $pdN > 0 ? round($pdSum / $pdN, 2) : null;
    $avgAl = $alN > 0 ? round($alSum / $alN, 2) : null;
} else {
    foreach ($details as $row) {
        if (isset($row['bleeding'])) { $bop_sites++; if ((int)$row['bleeding']===1) $bop_total++; }
    }
}
$bop_percent = $bop_sites > 0 ? round(($bop_total / $bop_sites)*100,2) : 0.00;
$pi_percent = $pi_sites > 0 ? round(($pi_total / $pi_sites)*100,2) : 0.00;

// ---- Header upsert ----
sqlBeginTrans();
try {
    // Resolve periodontogram id and forms.id cleanly
    $perio_id = (int)($_POST['perio_id'] ?? 0);
    $forms_id = (int)($_POST['forms_id'] ?? $_POST['formid'] ?? 0);

    // 1. If perio_id was explicitly provided, verify it belongs to this patient
    if ($perio_id > 0) {
        $chkP = sqlQuery("SELECT id FROM form_periodontogram WHERE id = ? AND pid = ? LIMIT 1", [$perio_id, $pid]);
        if (!$chkP) {
            $perio_id = 0;
        }
    }

    // 2. If perio_id not resolved, resolve via forms table
    if ($perio_id === 0 && $forms_id > 0) {
        $res_link = sqlQuery("SELECT id, form_id FROM forms WHERE id = ? AND formdir = 'periodontics' LIMIT 1", [$forms_id]);
        if ($res_link && !empty($res_link['form_id'])) {
            $perio_id = (int)$res_link['form_id'];
        } else {
            // forms_id might have been form_periodontogram.id directly
            $direct = sqlQuery("SELECT id FROM form_periodontogram WHERE id = ? AND pid = ? LIMIT 1", [$forms_id, $pid]);
            if ($direct) {
                $perio_id = (int)$direct['id'];
                $chkF = sqlQuery("SELECT id FROM forms WHERE form_id = ? AND formdir = 'periodontics' LIMIT 1", [$perio_id]);
                $forms_id = $chkF ? (int)$chkF['id'] : 0;
            }
        }
    }

    // 3. If still not resolved, check if an existing exam already exists for this encounter
    if ($perio_id === 0 && $encounter > 0) {
        $existing = sqlQuery("SELECT id FROM form_periodontogram WHERE pid = ? AND encounter = ? ORDER BY id DESC LIMIT 1", [$pid, $encounter]);
        if ($existing) {
            $perio_id = (int)$existing['id'];
            $chkF = sqlQuery("SELECT id FROM forms WHERE form_id = ? AND formdir = 'periodontics' LIMIT 1", [$perio_id]);
            if ($chkF) {
                $forms_id = (int)$chkF['id'];
            }
        }
    }

    // Check if v2 columns exist (table_v2.sql applied?)
    $hasV2Cols = false;
    $chkCol = sqlQuery("SHOW COLUMNS FROM `form_periodontogram` LIKE 'chart_version'");
    $hasV2Cols = ($chkCol !== false && $chkCol !== null);

    if ($perio_id > 0) {
        if ($hasV2Cols) {
            sqlStatement(
                "UPDATE form_periodontogram SET date=NOW(), diagnosis=?, grade=?, bop_total=?, bop_sites=?, bop_percent=?,
                 pi_total=?, pi_sites=?, pi_percent=?, avg_pd=?, avg_al=?, teeth_present=?, teeth_implant=?, chart_version=2, notes=? WHERE id=? AND pid=?",
                [$diagnosis,$grade,$bop_total,$bop_sites,$bop_percent,$pi_total,$pi_sites,$pi_percent,$avgPd,$avgAl,$teethPresent,$teethImplant,$notes,$perio_id,$pid]
            );
        } else {
            sqlStatement(
                "UPDATE form_periodontogram SET date=NOW(), diagnosis=?, grade=?, bop_total=?, bop_sites=?, bop_percent=?, notes=? WHERE id=? AND pid=?",
                [$diagnosis,$grade,$bop_total,$bop_sites,$bop_percent,$notes,$perio_id,$pid]
            );
        }
        // Clean previous v2 rows if tables exist
        $toothExists = sqlQuery("SHOW TABLES LIKE 'form_periodontogram_tooth'");
        if ($toothExists) {
            sqlStatement("DELETE FROM form_periodontogram_site WHERE periodontogram_id=?", [$perio_id]);
            sqlStatement("DELETE FROM form_periodontogram_tooth WHERE periodontogram_id=?", [$perio_id]);
        }
        sqlStatement("DELETE FROM form_periodontogram_detail WHERE periodontogram_id=?", [$perio_id]);
    } else {
        $fac_id = $session->get('active_site_id') ?? $GLOBALS['facility_id'] ?? 1;
        if ($hasV2Cols) {
            $perio_id = sqlInsert(
                "INSERT INTO form_periodontogram (date,pid,encounter,user,groupname,facility_id,authorized,activity,diagnosis,grade,bop_total,bop_sites,bop_percent,pi_total,pi_sites,pi_percent,avg_pd,avg_al,teeth_present,teeth_implant,chart_version,notes) VALUES (NOW(),?,?,?,?,?,1,1,?,?,?,?,?,?,?,?,?,?,?,?,2,?)",
                [$pid,$encounter,$user,$groupname,$fac_id,$diagnosis,$grade,$bop_total,$bop_sites,$bop_percent,$pi_total,$pi_sites,$pi_percent,$avgPd,$avgAl,$teethPresent,$teethImplant,$notes]
            );
        } else {
            $perio_id = sqlInsert(
                "INSERT INTO form_periodontogram (date,pid,encounter,user,groupname,facility_id,authorized,activity,diagnosis,grade,bop_total,bop_sites,bop_percent,notes) VALUES (NOW(),?,?,?,?,?,1,1,?,?,?,?,?,?)",
                [$pid,$encounter,$user,$groupname,$fac_id,$diagnosis,$grade,$bop_total,$bop_sites,$bop_percent,$notes]
            );
        }
    }

    // Ensure OpenEMR forms table entry exists and points to current $perio_id
    if ($forms_id > 0) {
        sqlStatement("UPDATE forms SET form_id = ?, date = NOW() WHERE id = ?", [$perio_id, $forms_id]);
    } else {
        $fEnc = sqlQuery("SELECT id FROM forms WHERE encounter = ? AND formdir = 'periodontics' LIMIT 1", [$encounter]);
        if ($fEnc) {
            $forms_id = (int)$fEnc['id'];
            sqlStatement("UPDATE forms SET form_id = ?, date = NOW() WHERE id = ?", [$perio_id, $forms_id]);
        } else {
            $formService = new FormService();
            $forms_id = $formService->addForm($encounter, "Periodontogram", $perio_id, "periodontics", $pid, 1, "NOW()", $user, $groupname);
        }
    }

    // ---- Write v2 tables if available ----
    $toothExists = sqlQuery("SHOW TABLES LIKE 'form_periodontogram_tooth'");
    $siteExists  = sqlQuery("SHOW TABLES LIKE 'form_periodontogram_site'");

    if ($toothExists && $siteExists && is_array($teeth)) {
        $sqlTooth = "INSERT INTO form_periodontogram_tooth (periodontogram_id,pid,encounter,tooth_fdi,status,mobility,furcation_buccal,furcation_lingual,furcation_palatal,notes) VALUES (?,?,?,?,?,?,?,?,?,?)";
        $sqlSite  = "INSERT INTO form_periodontogram_site (periodontogram_id,tooth_id,pid,encounter,tooth_fdi,site,probing_depth,gingival_margin,attachment_level,bleeding,plaque,suppuration) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";

        foreach ($teeth as $tooth) {
            $fdi = (int)($tooth['fdi'] ?? 0);
            if ($fdi < 11 || $fdi > 48) continue;
            $status = in_array($tooth['status'] ?? 'present', ['present','missing','implant']) ? $tooth['status'] : 'present';
            $mobility = isset($tooth['mobility']) && $tooth['mobility']!=='' ? max(0,min(3,(int)$tooth['mobility'])) : null;
            // mobility 0 is meaningful; store 0-3, NULL if not set? Keep 0 as valid
            if (($mobility === 0 && ($tooth['mobility'] ?? null) === '')) $mobility = null;
            $fur = $tooth['furcation'] ?? [];
            $fb = isset($fur['buccal']) ? max(0,min(3,(int)$fur['buccal'])) : null;
            $fl = isset($fur['lingual']) ? max(0,min(3,(int)$fur['lingual'])) : null;
            $fp = isset($fur['palatal']) ? max(0,min(3,(int)$fur['palatal'])) : null;
            // Normalize furcation 0 -> null for display? Keep 0 = not assessed
            if ($fb===0) $fb = null; if ($fl===0) $fl = null; if ($fp===0) $fp = null;

            $tooth_id = sqlInsert($sqlTooth, [$perio_id,$pid,$encounter,$fdi,$status,$mobility,$fb,$fl,$fp,'']);

            if ($status === 'missing') continue; // no sites for missing teeth

            foreach (($tooth['sites'] ?? []) as $s) {
                $site = (int)($s['site'] ?? 0);
                if ($site < 1 || $site > 6) continue;
                $pd = isset($s['pd']) && $s['pd']!=='' ? (int)$s['pd'] : null;
                $gm = isset($s['gm']) && $s['gm']!=='' ? (int)$s['gm'] : null;
                if ($pd !== null) $pd = max(0, min(12, $pd));
                if ($gm !== null) $gm = max(-12, min(12, $gm));
                $al = ($pd !== null) ? ($pd - ($gm ?? 0)) : null;
                sqlStatement($sqlSite, [
                    $perio_id, $tooth_id, $pid, $encounter, $fdi, $site,
                    $pd, $gm, $al,
                    !empty($s['bop'])?1:0,
                    !empty($s['plaque'])?1:0,
                    !empty($s['supp'])?1:0
                ]);
            }
        }
    }

    // ---- Dual-write legacy detail (for old viewers / migration) ----
    if (!empty($details)) {
        $sqlDetail = "INSERT INTO form_periodontogram_detail (periodontogram_id,pid,encounter,tooth_fdi,surface,point,probing_depth,recession,bleeding,suppuration,furcation,mobility) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
        foreach ($details as $row) {
            if (empty($row['tooth_fdi'])) continue;
            sqlStatement($sqlDetail, [
                $perio_id,$pid,$encounter,(int)$row['tooth_fdi'],$row['surface'],(int)$row['point'],
                $row['probing_depth']!=='' && $row['probing_depth']!==null ? (int)$row['probing_depth'] : null,
                $row['recession']!=='' && $row['recession']!==null ? (int)$row['recession'] : null,
                (int)($row['bleeding'] ?? 0),(int)($row['suppuration'] ?? 0),
                isset($row['furcation']) && $row['furcation']!=='' ? (int)$row['furcation'] : null,
                isset($row['mobility']) && $row['mobility']!=='' ? (int)$row['mobility'] : null
            ]);
        }
    }

    sqlCommitTrans();
    echo json_encode([
        'success' => true,
        'message' => xl('Periodontal exam saved successfully'),
        'formid' => $forms_id ?: $perio_id,
        'forms_id' => $forms_id,
        'perio_id' => $perio_id,
        'bop_percent' => $bop_percent,
        'pi_percent' => $pi_percent,
        'avg_pd' => $avgPd,
        'avg_al' => $avgAl,
    ]);
} catch (Exception $e) {
    sqlRollbackTrans();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
