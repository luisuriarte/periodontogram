<?php
/**
 * save.php — Periodontograma
 * Guarda el examen periodontal (cabecera + detalle por diente/punto)
 */
require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");
require_once("$srcdir/translation.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Services\FormService;

header('Content-Type: application/json');

// Verificar CSRF
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"] ?? '')) {
    CsrfUtils::csrfNotVerified();
}

$pid       = (int)$_SESSION['pid'];
$encounter = (int)$_SESSION['encounter'];
$user      = $_SESSION['authUser'] ?? '';
$groupname = $_SESSION['authGroup'] ?? $_SESSION['authProvider'] ?? ''; 
$formid    = (int)($_POST['formid'] ?? 0);
$authId    = (int)($_SESSION['authUserID'] ?? $_SESSION['authId'] ?? 0);

if (!$pid || !$encounter) {
    echo json_encode(['success' => false, 'message' => xlt('No encounter or patient specified')]);
    exit;
}

// Decodificar payload
$payload = json_decode($_POST['payload'] ?? '{}', true);
if (empty($payload)) {
    echo json_encode(['success' => false, 'message' => xlt('No data provided')]);
    exit;
}

$diagnosis   = $payload['diagnosis']   ?? null;
$grade       = $payload['grade']       ?? null;
$notes       = $payload['notes']       ?? null;
$details     = $payload['details']     ?? [];   // Array de puntos de sondaje

// Calcular BOP
$bop_total  = 0;
$bop_sites  = 0;
foreach ($details as $row) {
    if (isset($row['bleeding'])) {
        $bop_sites++;
        if ((int)$row['bleeding'] === 1) {
            $bop_total++;
        }
    }
}
$bop_percent = ($bop_sites > 0)
    ? round(($bop_total / $bop_sites) * 100, 2)
    : 0.00;

sqlBeginTrans();

try {
    // Determinar si ya existe un registro en form_periodontogram vinculado
    $perio_id = 0;
    if ($formid > 0) {
        $res_link = sqlQuery("SELECT form_id FROM forms WHERE id = ?", [$formid]);
        $perio_id = (int)($res_link['form_id'] ?? 0);
    }

    if ($perio_id > 0) {
        // Actualizar cabecera existente
        $sql = "UPDATE form_periodontogram
                SET date = NOW(), diagnosis = ?, grade = ?,
                    bop_total = ?, bop_sites = ?, bop_percent = ?, notes = ?
                WHERE id = ? AND pid = ?";
        sqlStatement($sql, [$diagnosis, $grade, $bop_total, $bop_sites, $bop_percent, $notes, $perio_id, $pid]);

        // Eliminar detalle anterior para reinsertar
        sqlStatement("DELETE FROM form_periodontogram_detail WHERE periodontogram_id = ?", [$perio_id]);
    } else {
        // Obtener datos de facilidad y sesión
        $fac_id = $_SESSION['active_site_id'] ?? $GLOBALS['facility_id'] ?? 1;

        // Insertar cabecera nueva
        $sql = "INSERT INTO form_periodontogram
                (date, pid, encounter, user, groupname, facility_id, authorized, activity,
                 diagnosis, grade, bop_total, bop_sites, bop_percent, notes)
                VALUES (NOW(), ?, ?, ?, ?, ?, 1, 1, ?, ?, ?, ?, ?, ?)";
        $perio_id = sqlInsert($sql, [
            $pid, $encounter, $user, $groupname, $fac_id,
            $diagnosis, $grade, $bop_total, $bop_sites, $bop_percent, $notes
        ]);

        if ($formid == 0) {
            // Registrar en OpenEMR (tabla forms) usando el servicio
            $formService = new FormService();
            $authorized = 1; 
            // addForm(encounter, form_name, form_id, formdir, pid, authorized, date, user, group)
            $formid = $formService->addForm($encounter, "Periodontogram", $perio_id, "periodontics", $pid, $authorized, "NOW()", $user, $groupname);
        }
    }

    // Insertar detalle por punto
    $sqlDetail = "INSERT INTO form_periodontogram_detail
                  (periodontogram_id, pid, encounter, tooth_fdi, surface, point,
                   probing_depth, recession, bleeding, suppuration, furcation, mobility)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    foreach ($details as $row) {
        $furcation = isset($row['furcation']) && $row['furcation'] !== '' ? (int)$row['furcation'] : null;
        $mobility  = isset($row['mobility'])  && $row['mobility']  !== '' ? (int)$row['mobility']  : null;
        $pd        = isset($row['probing_depth']) && $row['probing_depth'] !== '' ? (int)$row['probing_depth'] : null;
        $rec       = isset($row['recession'])     && $row['recession']     !== '' ? (int)$row['recession']     : null;

        sqlStatement($sqlDetail, [
            $perio_id,
            $pid,
            $encounter,
            (int)$row['tooth_fdi'],
            $row['surface'],
            (int)$row['point'],
            $pd,
            $rec,
            (int)($row['bleeding'] ?? 0),
            (int)($row['suppuration'] ?? 0),
            $furcation,
            $mobility
        ]);
    }

    sqlCommitTrans();
    echo json_encode([
        'success'     => true,
        'message'     => xlt('Periodontal exam saved successfully'),
        'formid'      => $formid,
        'perio_id'    => $perio_id,
        'bop_percent' => $bop_percent
    ]);
} catch (Exception $e) {
    sqlRollbackTrans();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
