<?php
/**
 * report.php — Periodontogram V2 Report
 *
 * Dual mode:
 *  - Included mode (encounter list, forms.php → FormReportRenderer):
 *      defines periodontics_report($pid, $encounter, $cols, $formId) and
 *      does NOTHING else at include time (no CSRF die, no page output).
 *      $formId here is form_periodontogram.id (forms.form_id).
 *  - Standalone mode (direct access, print):
 *      runs full page when this file is the SCRIPT_FILENAME.
 *      Requires ?id=<forms.id>&csrf_token_form=... and session pid/encounter.
 *
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

if (!function_exists('perio_option_title')) {
    function perio_option_title($list_id, $option_id) {
        if (!$option_id) return xl('None');
        $res = sqlQuery("SELECT title FROM list_options WHERE list_id = ? AND option_id = ?", [$list_id, $option_id]);
        $t = $res['title'] ?? $option_id;
        return xl($t);
    }
}

if (!function_exists('perio_load_unified')) {
    function perio_load_unified($perioId) {
        $v2Teeth = [];
        $v2Sites = [];
        $chk = sqlQuery("SHOW TABLES LIKE 'form_periodontogram_tooth'");
        if ($chk) {
            $resT = sqlStatement("SELECT * FROM form_periodontogram_tooth WHERE periodontogram_id=? ORDER BY tooth_fdi", [$perioId]);
            while ($t = sqlFetchArray($resT)) {
                $v2Teeth[(int)$t['tooth_fdi']] = $t;
                $v2Sites[(int)$t['tooth_fdi']] = [];
            }
            $resS = sqlStatement("SELECT * FROM form_periodontogram_site WHERE periodontogram_id=? ORDER BY tooth_fdi, site", [$perioId]);
            while ($s = sqlFetchArray($resS)) {
                $v2Sites[(int)$s['tooth_fdi']][(int)$s['site']] = $s;
            }
        }
        $legacyDetails = [];
        $resL = sqlStatement("SELECT * FROM form_periodontogram_detail WHERE periodontogram_id=? ORDER BY tooth_fdi, surface, point", [$perioId]);
        while ($r = sqlFetchArray($resL)) { $legacyDetails[] = $r; }

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
                $map = ['V' => [1 => 1, 2 => 2, 3 => 3], 'P' => [1 => 6, 2 => 5, 3 => 4]];
                $sid = $map[$row['surface']][(int)$row['point']] ?? null;
                if (!$sid) continue;
                $pd = $row['probing_depth'] !== null ? (int)$row['probing_depth'] : null;
                $rec = $row['recession'] !== null ? (int)$row['recession'] : null;
                $gm = $rec !== null ? -$rec : null;
                $al = $pd !== null ? $pd - ($gm ?? 0) : null;
                $unified[$fdi][$sid] = ['pd' => $pd, 'gm' => $gm, 'al' => $al, 'bop' => $row['bleeding'], 'plaque' => 0, 'supp' => $row['suppuration']];
                if (!isset($unified[$fdi]['_tooth'])) {
                    $unified[$fdi]['_tooth'] = ['status' => 'present', 'mobility' => $row['mobility'] ?? null, 'furcation_buccal' => $row['furcation'] ?? null];
                }
            }
        }
        return $unified;
    }
}

if (!function_exists('perio_render_arch')) {
    function perio_render_arch($teeth, $title, $unified) {
        echo "<h5 class='mt-3'>" . text($title) . "</h5>";
        echo "<table class='grid-table'><thead><tr><td class='row-label'>" . text(xl('Tooth')) . " #</td>";
        foreach ($teeth as $idx => $t) {
            echo "<td colspan='6' class='tooth-num" . ($idx === 8 ? ' midline' : '') . "'>" . (int)$t . "</td>";
        }
        echo "</tr></thead><tbody>";

        echo "<tr><td class='row-label'>" . text(xl('Status')) . "</td>";
        foreach ($teeth as $idx => $t) {
            $st = $unified[$t]['_tooth']['status'] ?? 'present';
            $label = $st === 'missing' ? xl('Missing') : ($st === 'implant' ? xl('Implant') : xl('Present'));
            echo "<td colspan='6' class='" . ($idx === 8 ? 'midline' : '') . "'>" . text($label) . "</td>";
        }
        echo "</tr>";

        echo "<tr><td class='row-label'>" . text(xl('Mobility')) . "</td>";
        foreach ($teeth as $idx => $t) {
            $m = $unified[$t]['_tooth']['mobility'] ?? null;
            $mTxt = ($m === null || $m === '') ? '–' : ((int)$m === 0 ? '0' : (['I', 'II', 'III'][(int)$m - 1] ?? (int)$m));
            echo "<td colspan='6' class='" . ($idx === 8 ? 'midline' : '') . "'>$mTxt</td>";
        }
        echo "</tr>";

        $rows = [
            ['PD', 'pd', true],
            ['GM', 'gm', false],
            ['CAL', 'al', false],
        ];
        foreach ($rows as [$label, $key, $color]) {
            echo "<tr><td class='row-label'>$label</td>";
            foreach ($teeth as $idx => $t) {
                echo "<td colspan='6' class='" . ($idx === 8 ? 'midline' : '') . "'><div class='site-row'>";
                foreach ([1, 2, 3, 4, 5, 6] as $sid) {
                    $v = $unified[$t][$sid][$key] ?? null;
                    $style = '';
                    if ($color && $v !== null && $v !== '') {
                        if ((int)$v >= 6) $style = 'background:#f8d7da;';
                        elseif ((int)$v >= 4) $style = 'background:#fff3cd;';
                        else $style = 'background:#d4edda;';
                    }
                    $cls = $key === 'gm' ? ' gm-val' : ($key === 'al' ? ' cal-val' : '');
                    echo "<span class='site-val$cls' style='$style'>" . ($v === null || $v === '' ? '–' : text($v)) . "</span>";
                }
                echo "</div></td>";
            }
            echo "</tr>";
        }

        echo "<tr><td class='row-label'>BOP/PI/Supp</td>";
        foreach ($teeth as $idx => $t) {
            echo "<td colspan='6' class='" . ($idx === 8 ? 'midline' : '') . "'><div class='site-row'>";
            foreach ([1, 2, 3, 4, 5, 6] as $sid) {
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
}

/**
 * Encounter-list entry point (called by FormReportRenderer).
 * $pid may be $attendant_id (patient id), $formId is form_periodontogram.id.
 */
if (!function_exists('periodontics_report')) {
    function periodontics_report($pid, $encounter, $cols, $formId, $print = true) {
        $header = sqlQuery("SELECT * FROM form_periodontogram WHERE id = ? LIMIT 1", [(int)$formId]);
        if (!$header) {
            echo "<div class='text-danger'>" . text(xl('Exam not found.')) . "</div>";
            return;
        }
        $unified = perio_load_unified((int)$header['id']);
        $upper = [18,17,16,15,14,13,12,11, 21,22,23,24,25,26,27,28];
        $lower = [48,47,46,45,44,43,42,41, 31,32,33,34,35,36,37,38];
        echo "<div class='perio-report-snippet' style='font-size:11px;'>";
        echo "<div class='summary-box' style='background:#f8f9fa;border:1px solid #ccc;padding:8px;margin-bottom:10px;'>";
        echo "<strong>" . text(xl('Diagnosis')) . ":</strong> " . text(perio_option_title('perio_diagnosis', $header['diagnosis'] ?? null)) . " &nbsp;|&nbsp; ";
        echo "<strong>" . text(xl('Grade')) . ":</strong> " . text(perio_option_title('perio_grade', $header['grade'] ?? null)) . " &nbsp;|&nbsp; ";
        echo "<strong>BOP:</strong> " . text($header['bop_percent'] ?? 0) . "% &nbsp;|&nbsp; ";
        echo "<strong>PI:</strong> " . text($header['pi_percent'] ?? 0) . "% &nbsp;|&nbsp; ";
        echo "<strong>" . text(xl('Avg PD')) . ":</strong> " . text($header['avg_pd'] ?? '–') . " mm &nbsp;|&nbsp; ";
        echo "<strong>" . text(xl('Avg AL')) . ":</strong> " . text($header['avg_al'] ?? '–') . " mm";
        if (!empty($header['notes'])) {
            echo "<div><strong>" . text(xl('Notes')) . ":</strong> " . text($header['notes']) . "</div>";
        }
        echo "</div>";
        // Compact arch tables (no full page chrome, styled by encounter list)
        echo "<style>.perio-report-snippet .grid-table{border-collapse:collapse;width:100%;table-layout:fixed;margin-bottom:10px}.perio-report-snippet .grid-table th,.perio-report-snippet .grid-table td{border:1px solid #999;text-align:center;padding:2px 1px}.perio-report-snippet .row-label{font-weight:bold;background:#eee;width:90px;text-align:left!important;padding-left:5px!important}.perio-report-snippet .tooth-num{background:#444;color:#fff;font-weight:bold}.perio-report-snippet .midline{border-left:3px solid #000!important}.perio-report-snippet .site-row{display:flex;gap:1px;justify-content:space-around}.perio-report-snippet .site-val{display:inline-block;min-width:14px;border:1px solid #ccc;padding:0 1px;border-radius:2px}.perio-report-snippet .gm-val{color:#007bff}.perio-report-snippet .cal-val{font-weight:bold;color:#555}.perio-report-snippet .bop-mark{color:#dc3545;font-weight:bold}.perio-report-snippet .pi-mark{color:#b08900;font-weight:bold}.perio-report-snippet .sup-mark{color:#6f42c1;font-weight:bold}</style>";
        perio_render_arch($upper, xl('Upper Arch'), $unified);
        perio_render_arch($lower, xl('Lower Arch'), $unified);
        echo "</div>";
    }
}

// ---- Standalone page (direct access only) ----
$__direct = (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'report.php');
if ($__direct) {
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

    $examHeader = [];
    $link = sqlQuery("SELECT form_id FROM forms WHERE id = ? LIMIT 1", [$formid]);
    $candidates = array_filter([(int)($link['form_id'] ?? 0), $formid]);
    foreach ($candidates as $cand) {
        $row = sqlQuery("SELECT * FROM form_periodontogram WHERE id = ? AND pid = ? LIMIT 1", [$cand, $pid]);
        if ($row) { $examHeader = $row; break; }
    }
    if (empty($examHeader)) {
        die(xlt("Exam not found."));
    }
    $perioId = (int)$examHeader['id'];

    $patientService = new PatientService();
    $patientData = $patientService->findByPid($pid) ?: [];
    $patientName = trim(($patientData['lname'] ?? '') . ', ' . ($patientData['fname'] ?? ''));
    $patientDob = $patientData['DOB'] ?? '';

    $diagnosisTitle = perio_option_title('perio_diagnosis', $examHeader['diagnosis'] ?? null);
    $gradeTitle = perio_option_title('perio_grade', $examHeader['grade'] ?? null);
    $unified = perio_load_unified($perioId);
    $upperTeeth = [18,17,16,15,14,13,12,11, 21,22,23,24,25,26,27,28];
    $lowerTeeth = [48,47,46,45,44,43,42,41, 31,32,33,34,35,36,37,38];
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

    <?php perio_render_arch($upperTeeth, xl('Upper Arch'), $unified); ?>
    <?php perio_render_arch($lowerTeeth, xl('Lower Arch'), $unified); ?>

    <div class="mt-2 small text-muted">
        <?php echo xlt('Sites: MB · B · DB / DL · L · ML. Attachment Level (CAL) = Probing Depth (PD) - Gingival Margin (GM).'); ?>
    </div>

    <div class="no-print mt-4">
        <button onclick="window.print()" class="btn btn-primary"><i class="fa fa-print"></i> <?php echo xlt('Print Report'); ?></button>
        <button onclick="window.close()" class="btn btn-secondary"><?php echo xlt('Close'); ?></button>
    </div>
</body>
</html>
<?php
}
