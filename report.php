<?php
/**
 * report.php — Periodontogram V2 Report (print)
 * Read-only view: perio-tools style chart + data summary.
 * All displayed strings via xl()/xlt() (English source).
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
require_once("$srcdir/date_functions.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Services\PatientService;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
if (!CsrfUtils::verifyCsrfToken($_GET["csrf_token_form"] ?? '', $session)) {
    CsrfUtils::csrfNotVerified();
}

$pid = (int)($session->get('pid') ?? 0);
$encounter = (int)($session->get('encounter') ?? 0);
$formid = (int)($_GET['id'] ?? 0);

if (!$pid || !$encounter || !$formid) {
    die(xlt("Error: Invalid patient, encounter, or form ID."));
}

$examHeader = sqlQuery(
    "SELECT * FROM form_periodontogram WHERE id = ? AND pid = ?",
    [$formid, $pid]
) ?: [];

if (empty($examHeader)) {
    die(xlt("Exam not found."));
}
$perioId = (int)$examHeader['id'];

// Patient via Service
$patientService = new PatientService();
$patientData = $patientService->findByPid($pid) ?: [];
$patientName = trim(($patientData['lname'] ?? '') . ', ' . ($patientData['fname'] ?? ''));
$patientDob = $patientData['DOB'] ?? '';

function getOptionTitleTranslated($list_id, $option_id) {
    if (!$option_id) return xl('None');
    $res = sqlQuery("SELECT title FROM list_options WHERE list_id = ? AND option_id = ?", [$list_id, $option_id]);
    $t = $res['title'] ?? $option_id;
    return xl($t);
}
$diagnosisTitle = getOptionTitleTranslated('perio_diagnosis', $examHeader['diagnosis'] ?? null);
$gradeTitle = getOptionTitleTranslated('perio_grade', $examHeader['grade'] ?? null);

// Load v2
$v2Teeth = [];
$v2Sites = []; // fdi -> site -> row
$toothExists = sqlQuery("SHOW TABLES LIKE 'form_periodontogram_tooth'");
if ($toothExists) {
    $resT = sqlStatement("SELECT * FROM form_periodontogram_tooth WHERE periodontogram_id=? ORDER BY tooth_fdi", [$perioId]);
    while ($t = sqlFetchArray($resT)) {
        $v2Teeth[(int)$t['tooth_fdi']] = $t;
        $v2Sites[(int)$t['tooth_fdi']] = [];
    }
    $resS = sqlStatement("SELECT * FROM form_periodontogram_site WHERE periodontogram_id=? ORDER BY tooth_fdi, site", [$perioId]);
    while ($s = sqlFetchArray($resS)) {
        $fdi = (int)$s['tooth_fdi'];
        $v2Sites[$fdi][(int)$s['site']] = $s;
    }
}

// Legacy fallback data
$legacyDetails = [];
$resL = sqlStatement("SELECT * FROM form_periodontogram_detail WHERE periodontogram_id=? ORDER BY tooth_fdi, surface, point", [$perioId]);
while ($r = sqlFetchArray($resL)) { $legacyDetails[] = $r; }

$upperTeeth = [18,17,16,15,14,13,12,11, 21,22,23,24,25,26,27,28];
$lowerTeeth = [48,47,46,45,44,43,42,41, 31,32,33,34,35,36,37,38];

function legacySiteOf($surface, $point) {
    $m = ['V'=>[1=>1,2=>2,3=>3],'P'=>[1=>6,2=>5,3=>4]];
    return $m[$surface][$point] ?? null;
}
function legacySiteToV2($fdi, $site) {
    $f = null;
    return $f;
}
// Build unified view: fdi -> site -> ['pd','gm','al','bop','plaque','supp','surf','pt']
$unified = [];
if (!empty($v2Sites)) {
    foreach ($v2Sites as $fdi => $sites) {
        foreach ($sites as $sid => $s) {
            $unified[$fdi][$sid] = [
                'pd' => $s['probing_depth'], 'gm' => $s['gingival_margin'],
                'al' => $s['attachment_level'], 'bop' => $s['bleeding'],
                'plaque' => $s['plaque'], 'supp' => $s['suppuration'],
            ];
        }
    }
    foreach ($v2Teeth as $fdi => $t) {
        $unified[$fdi]['_tooth'] = $t;
    }
} else {
    foreach ($legacyDetails as $row) {
        $fdi = (int)$row['tooth_fdi'];
        $sid = legacySiteOf($row['surface'], (int)$row['point']);
        if (!$sid) continue;
        $pd = $row['probing_depth'] !== null ? (int)$row['probing_depth'] : null;
        $rec = $row['recession'] !== null ? (int)$row['recession'] : null;
        $gm = $rec !== null ? -$rec : null;
        $al = $pd !== null ? $pd - ($gm ?? 0) : null;
        $unified[$fdi][$sid] = ['pd'=>$pd,'gm'=>$gm,'al'=>$al,'bop'=>$row['bleeding'],'plaque'=>0,'supp'=>$row['suppuration']];
        if (!isset($unified[$fdi]['_tooth'])) {
            $unified[$fdi]['_tooth'] = ['status'=>'present','mobility'=>$row['mobility'] ?? null,'furcation_buccal'=>$row['furcation'] ?? null];
        }
    }
}

function pdClassCss($pd) {
    if ($pd === null || $pd === '' ) return '';
    if ((int)$pd >= 6) return 'background:#f8d7da;';
    if ((int)$pd >= 4) return 'background:#fff3cd;';
    return 'background:#d4edda;';
}

function renderArch($teeth, $title) {
    global $unified;
    echo "<h5 class='mt-4'>" . text($title) . "</h5>";
    echo "<table class='grid-table'><thead><tr><td class='row-label'>" . text(xl('Tooth')) . " #</td>";
    foreach ($teeth as $idx => $t) {
        echo "<td colspan='6' class='tooth-num" . ($idx===8?' midline':'') . "'>" . (int)$t . "</td>";
    }
    echo "</tr></thead><tbody>";

    // Status row
    echo "<tr><td class='row-label'>" . text(xl('Status')) . "</td>";
    foreach ($teeth as $idx => $t) {
        $st = $unified[$t]['_tooth']['status'] ?? 'present';
        $label = $st==='missing' ? xl('Missing') : ($st==='implant' ? xl('Implant') : xl('Present'));
        echo "<td colspan='6' class='" . ($idx===8?'midline':'') . "'>" . text($label) . "</td>";
    }
    echo "</tr>";

    // Mobility
    echo "<tr><td class='row-label'>" . text(xl('Mobility')) . "</td>";
    foreach ($teeth as $idx => $t) {
        $m = $unified[$t]['_tooth']['mobility'] ?? null;
        $mTxt = ($m === null || $m === '') ? '–' : ( (int)$m===0 ? '0' : (['I','II','III'][(int)$m-1] ?? (int)$m) );
        echo "<td colspan='6' class='" . ($idx===8?'midline':'') . "'>$mTxt</td>";
    }
    echo "</tr>";

    // PD row (6 sites)
    echo "<tr><td class='row-label'>PD</td>";
    foreach ($teeth as $idx => $t) {
        echo "<td colspan='6' class='" . ($idx===8?'midline':'') . "'><div class='site-row'>";
        foreach ([1,2,3,4,5,6] as $sid) {
            $v = $unified[$t][$sid]['pd'] ?? null;
            echo "<span class='site-val' style='" . pdClassCss($v) . "'>" . ($v===null?'–':text($v)) . "</span>";
        }
        echo "</div></td>";
    }
    echo "</tr>";

    // GM row
    echo "<tr><td class='row-label'>GM</td>";
    foreach ($teeth as $idx => $t) {
        echo "<td colspan='6' class='" . ($idx===8?'midline':'') . "'><div class='site-row'>";
        foreach ([1,2,3,4,5,6] as $sid) {
            $v = $unified[$t][$sid]['gm'] ?? null;
            echo "<span class='site-val gm-val'>" . ($v===null?'–':text($v)) . "</span>";
        }
        echo "</div></td>";
    }
    echo "</tr>";

    // CAL row
    echo "<tr><td class='row-label'>CAL</td>";
    foreach ($teeth as $idx => $t) {
        echo "<td colspan='6' class='" . ($idx===8?'midline':'') . "'><div class='site-row'>";
        foreach ([1,2,3,4,5,6] as $sid) {
            $v = $unified[$t][$sid]['al'] ?? null;
            echo "<span class='site-val cal-val'>" . ($v===null?'–':text($v)) . "</span>";
        }
        echo "</div></td>";
    }
    echo "</tr>";

    // BOP / PI / Supp row with dots
    echo "<tr><td class='row-label'>BOP/PI/Supp</td>";
    foreach ($teeth as $idx => $t) {
        echo "<td colspan='6' class='" . ($idx===8?'midline':'') . "'><div class='site-row'>";
        foreach ([1,2,3,4,5,6] as $sid) {
            $b = $unified[$t][$sid]['bop'] ?? 0;
            $p = $unified[$t][$sid]['plaque'] ?? 0;
            $s = $unified[$t][$sid]['supp'] ?? 0;
            echo "<span class='site-val'>";
            if ($b) echo "<span class='bop-mark'>●</span>";
            if ($p) echo "<span class='pi-mark'>◆</span>";
            if ($s) echo "<span class='sup-mark'>▲</span>";
            if (!$b && !$p && !$s) echo "–";
            echo "</span>";
        }
        echo "</div></td>";
    }
    echo "</tr>";

    echo "</tbody></table>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo xlt('Periodontal Chart Report'); ?></title>
    <?php Header::setupHeader(['bootstrap', 'fontawesome']); ?>
    <style>
        body { font-size: 10px; padding: 15px; }
        .report-header { margin-bottom: 12px; border-bottom: 2px solid #333; padding-bottom: 8px; }
        .grid-table { border-collapse: collapse; width: 100%; margin-bottom: 14px; table-layout: fixed; }
        .grid-table th, .grid-table td { border: 1px solid #999; text-align: center; padding: 2px 1px; vertical-align: middle; }
        .row-label { font-weight: bold; background: #eee; width: 90px; text-align: left !important; padding-left: 5px !important; }
        .tooth-num { background: #444; color: #fff; font-weight: bold; }
        .midline { border-left: 3px solid #000 !important; }
        .site-row { display:flex; gap:1px; justify-content:space-around; }
        .site-val { display:inline-block; min-width:14px; border:1px solid #ccc; padding:0 1px; border-radius:2px; }
        .gm-val { color:#007bff; }
        .cal-val { font-weight:bold; color:#555; }
        .bop-mark { color:#dc3545; font-weight:bold; }
        .pi-mark { color:#b08900; font-weight:bold; }
        .sup-mark { color:#6f42c1; font-weight:bold; }
        .summary-box { background: #f8f9fa; border: 1px solid #ccc; padding: 10px; margin-bottom: 14px; }
        @media print { .no-print { display:none; } body { padding:0; } }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="row">
            <div class="col-6">
                <h3><?php echo xlt('Periodontal Chart Report'); ?></h3>
                <?php if (!empty($patientName)): ?>
                <div><?php echo text($patientName); ?><?php if (!empty($patientDob)): ?> · <?php echo text($patientDob); ?><?php endif; ?></div>
                <?php endif; ?>
            </div>
            <div class="col-6 text-right">
                <b><?php echo xlt('Date'); ?>:</b> <?php echo text(oeTimestampFormatDateTime($examHeader['date'])); ?>
            </div>
        </div>
    </div>

    <div class="summary-box">
        <div class="row">
            <div class="col-3"><b><?php echo xlt('Diagnosis'); ?>:</b> <?php echo text($diagnosisTitle); ?></div>
            <div class="col-3"><b><?php echo xlt('Grade'); ?>:</b> <?php echo text($gradeTitle); ?></div>
            <div class="col-2"><b>BOP:</b> <?php echo text($examHeader['bop_percent'] ?? 0); ?>%</div>
            <div class="col-2"><b>PI:</b> <?php echo text($examHeader['pi_percent'] ?? 0); ?>%</div>
            <div class="col-2"><b><?php echo xlt('Avg PD'); ?>:</b> <?php echo text($examHeader['avg_pd'] ?? '–'); ?> mm</div>
        </div>
        <div class="row mt-2">
            <div class="col-4"><b><?php echo xlt('Avg AL'); ?>:</b> <?php echo text($examHeader['avg_al'] ?? '–'); ?> mm</div>
            <div class="col-8"><b><?php echo xlt('Notes'); ?>:</b> <?php echo text($examHeader['notes'] ?? ''); ?></div>
        </div>
    </div>

    <?php renderArch($upperTeeth, xl('Upper Arch')); ?>
    <?php renderArch($lowerTeeth, xl('Lower Arch')); ?>

    <div class="mt-2 small text-muted">
        <?php echo xlt('Sites: MB · B · DB / DL · L · ML. Attachment Level (CAL) = Probing Depth (PD) - Gingival Margin (GM).'); ?>
    </div>

    <div class="no-print mt-4">
        <button onclick="window.print()" class="btn btn-primary"><i class="fa fa-print"></i> <?php echo xlt('Print Report'); ?></button>
        <button onclick="window.close()" class="btn btn-secondary"><?php echo xlt('Close'); ?></button>
    </div>
</body>
</html>
