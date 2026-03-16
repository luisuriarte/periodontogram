<?php
/**
 * report.php — Periodontogram
 * Read-only report view for the periodontal examination
 */
require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");
require_once("$srcdir/translation.inc.php");
require_once("$srcdir/date_functions.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (!CsrfUtils::verifyCsrfToken($_GET["csrf_token_form"] ?? '')) {
    CsrfUtils::csrfNotVerified();
}

$pid      = $_SESSION['pid'] ?? 0;
$encounter= $_SESSION['encounter'] ?? 0;
$formid   = (int)($_GET['id'] ?? 0);

if (!$pid || !$encounter || !$formid) {
    die(xlt("Error: Invalid patient, encounter, or form ID."));
}

// Load Exam Header
$examHeader = sqlQuery(
    "SELECT * FROM form_periodontogram WHERE id = ? AND pid = ?",
    [$formid, $pid]
) ?: [];

if (empty($examHeader)) {
    die(xlt("Exam not found."));
}

// Load Exam Details
$examDetails = [];
$res = sqlStatement(
    "SELECT * FROM form_periodontogram_detail WHERE periodontogram_id = ? ORDER BY tooth_fdi, surface, point",
    [$formid]
);
while ($row = sqlFetchArray($res)) {
    $examDetails[] = $row;
}

// Map details to JS-ready object
$detailMap = [];
foreach ($examDetails as $row) {
    $key = $row['tooth_fdi'] . '_' . $row['surface'] . '_' . $row['point'];
    $detailMap[$key] = $row;
}

// Diagnostic titles
function getOptionTitle($list_id, $option_id) {
    if (!$option_id) return xlt('None');
    $res = sqlQuery("SELECT title FROM list_options WHERE list_id = ? AND option_id = ?", [$list_id, $option_id]);
    return $res['title'] ?? $option_id;
}
$diagnosisTitle = getOptionTitle('perio_diagnosis', $examHeader['diagnosis']);
$gradeTitle     = getOptionTitle('perio_grade', $examHeader['grade']);

// Teeth Config (FDI)
$upperTeeth = [18,17,16,15,14,13,12,11, 21,22,23,24,25,26,27,28];
$lowerTeeth = [48,47,46,45,44,43,42,41, 31,32,33,34,35,36,37,38];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?php echo xlt('Periodontogram Report'); ?></title>
    <?php Header::setupHeader(['bootstrap', 'fontawesome']); ?>
    <style>
        body { font-size: 11px; padding: 20px; }
        .report-header { margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        .grid-table { border-collapse: collapse; width: 100%; margin-bottom: 20px; table-layout: fixed; }
        .grid-table th, .grid-table td { border: 1px solid #999; text-align: center; padding: 1px; }
        .row-label { font-weight: bold; background: #eee; width: 100px; text-align: left !important; padding-left: 5px !important; }
        .tooth-num { background: #444; color: #fff; font-weight: bold; }
        .midline { border-left: 2px solid #000 !important; }
        .depth-val { display: inline-block; width: 18px; }
        .depth-1 { background: #d4edda; } /* healthy */
        .depth-2 { background: #fff3cd; } /* warning */
        .depth-3 { background: #f8d7da; } /* danger */
        .bop-mark { color: #dc3545; font-weight: bold; }
        .sup-mark { color: #6f42c1; font-weight: bold; }
        .summary-box { background: #f8f9fa; border: 1px solid #ccc; padding: 10px; margin-bottom: 20px; }
        
        /* SVG CHART STYLES */
        .chart-container {
            background: #222;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
            text-align: center;
        }
        .perio-svg {
            display: block;
            margin: 0 auto;
            max-width: 100%;
            height: auto;
            background: #111;
        }
        .svg-title {
            color: #eee;
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 5px;
            text-transform: uppercase;
            background: #333;
            padding: 2px 5px;
        }
        .chart-row {
            display: flex;
            gap: 25px;
            margin-bottom: 30px;
            margin-top: 15px;
        }
        .chart-row .chart-container {
            flex: 1;
        }

        @media print {
            .no-print { display: none; }
            .chart-container { background: #fff !important; border: 1px solid #ccc; }
            .perio-svg { background: #fff !important; filter: invert(0); }
            .svg-title { color: #000 !important; background: #eee !important; }
        }
    </style>
</head>
<body>
    <div class="report-header">
        <div class="row">
            <div class="col-6">
                <h3><?php echo xlt('Periodontal Examination Report'); ?></h3>
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
            <div class="col-3"><b><?php echo xlt('BOP'); ?>:</b> <?php echo text($examHeader['bop_percent']); ?>%</div>
        </div>
        <div class="row mt-2">
            <div class="col-12"><b><?php echo xlt('Notes'); ?>:</b> <?php echo text($examHeader['notes']); ?></div>
        </div>
    </div>

    <?php
    function renderReportGrid($teeth, $title, $labelMapping) {
        global $detailMap;
        echo "<h5 class='mt-4'>$title</h5>";
        
        echo "<table class='grid-table'>";
        
        // Tooth Numbers
        echo "<tr><td class='row-label'>Tooth #</td>";
        foreach ($teeth as $idx => $t) {
            $class = ($idx === 8) ? 'midline' : '';
            echo "<td colspan='3' class='tooth-num $class'>$t</td>";
        }
        echo "</tr>";

        // Rows based on labelMapping: [ ['label', 'type', 'surface'], ... ]
        foreach ($labelMapping as $rowCfg) {
            $label = $rowCfg[0];
            $type  = $rowCfg[1];
            $surf  = $rowCfg[2]; // V/P (using DB terminology)
            $surfText = ($surf === 'V') ? 'F' : (($surf === 'P' && in_array($teeth[0], [18,28])) ? 'P' : 'L');

            echo "<tr><td class='row-label'>$label ($surfText)</td>";
            foreach ($teeth as $idx => $t) {
                $mClass = ($idx === 8) ? 'midline' : '';
                echo "<td colspan='3' class='$mClass'><div style='display:flex; justify-content:space-around;'>";
                for ($pt = 1; $pt <= 3; $pt++) {
                    $key = "{$t}_{$surf}_{$pt}";
                    $val = $detailMap[$key] ?? null;
                    echo "<span>";
                    if ($type === 'pd' || $type === 'gm') {
                        $v = ($type === 'pd') ? ($val['probing_depth'] ?? '') : ($val['recession'] ?? '');
                        $c = '';
                        if ($type === 'pd' && $v !== '') {
                            if ($v >= 6) $c = 'depth-3';
                            elseif ($v >= 4) $c = 'depth-2';
                        }
                        echo "<span class='depth-val $c'>" . ($v !== '' ? text($v) : '-') . "</span>";
                    } elseif ($type === 'cal') {
                        if (isset($val['probing_depth']) && isset($val['recession'])) {
                            echo "<b>" . ($val['probing_depth'] + $val['recession']) . "</b>";
                        } else {
                            echo "-";
                        }
                    } elseif ($type === 'bop_sup') {
                        if (@$val['bleeding'])    echo "<span class='bop-mark'>●</span>";
                        if (@$val['suppuration']) echo "<span class='sup-mark'>●</span>";
                        if (!@$val['bleeding'] && !@$val['suppuration']) echo " ";
                    } elseif ($type === 'fur' && $pt === 2) {
                        echo ($val['furcation'] && $val['furcation'] > 0) ? text($val['furcation']) : "-";
                    } elseif ($type === 'mob' && $pt === 2 && $surf === 'V') {
                        echo ($val['mobility'] && $val['mobility'] > 0) ? text($val['mobility']) : "-";
                    }
                    echo "</span>";
                }
                echo "</div></td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }

    $upperRows = [
        ['Mobility', 'mob', 'V'],
        ['Furcation', 'fur', 'V'],
        ['PD', 'pd', 'V'],
        ['GM', 'gm', 'V'],
        ['CAL', 'cal', 'V'],
        ['BOP/Supp', 'bop_sup', 'V'],
        ['BOP/Supp', 'bop_sup', 'P'],
        ['CAL', 'cal', 'P'],
        ['GM', 'gm', 'P'],
        ['PD', 'pd', 'P'],
        ['Furcation', 'fur', 'P'],
    ];

    $lowerRows = [
        ['Furcation', 'fur', 'V'],
        ['PD', 'pd', 'V'],
        ['GM', 'gm', 'V'],
        ['CAL', 'cal', 'V'],
        ['BOP/Supp', 'bop_sup', 'V'],
        ['Mobility', 'mob', 'V'],
        ['BOP/Supp', 'bop_sup', 'P'],
        ['CAL', 'cal', 'P'],
        ['GM', 'gm', 'P'],
        ['PD', 'pd', 'P'],
        ['Furcation', 'fur', 'P'],
    ];
    ?>

    <!-- Upper Charts -->
    <div class="chart-row">
        <div class="chart-container">
            <div class="svg-title"><?php echo xlt('Upper Arch - Facial'); ?></div>
            <svg id="svg-upper-v" class="perio-svg" width="900" height="180" viewBox="0 0 900 180"></svg>
        </div>
        <div class="chart-container">
            <div class="svg-title"><?php echo xlt('Upper Arch - Palatal'); ?></div>
            <svg id="svg-upper-p" class="perio-svg" width="900" height="180" viewBox="0 0 900 180"></svg>
        </div>
    </div>

    <?php renderReportGrid($upperTeeth, xlt('Upper Arch Data'), $upperRows); ?>

    <!-- Lower Charts -->
    <div class="chart-row mt-4">
        <div class="chart-container">
            <div class="svg-title"><?php echo xlt('Lower Arch - Facial'); ?></div>
            <svg id="svg-lower-v" class="perio-svg" width="900" height="180" viewBox="0 0 900 180"></svg>
        </div>
        <div class="chart-container">
            <div class="svg-title"><?php echo xlt('Lower Arch - Lingual'); ?></div>
            <svg id="svg-lower-p" class="perio-svg" width="900" height="180" viewBox="0 0 900 180"></svg>
        </div>
    </div>

    <?php renderReportGrid($lowerTeeth, xlt('Lower Arch Data'), $lowerRows); ?>

    <!-- Script to draw SVGs on report load -->
    <!-- Global context for JS -->
    <script>
        window.upperTeeth = <?php echo json_encode($upperTeeth); ?>;
        window.lowerTeeth = <?php echo json_encode($lowerTeeth); ?>;
        window.existingData = <?php echo json_encode($detailMap ?? (object)[]); ?>;
        var webRoot = "<?php echo $GLOBALS['web_root']; ?>";
    </script>
    <script src="<?php echo $GLOBALS['web_root']; ?>/interface/forms/periodontics/js/periodontogram.js?v=<?php echo time(); ?>"></script>
    <script>
        $(document).ready(function() {
            if (typeof PerioChart !== 'undefined') {
                PerioChart.init();
            }
        });
    </script>

    <div class="no-print mt-4">
        <button onclick="window.print()" class="btn btn-primary"><i class="fa fa-print"></i> <?php echo xlt('Print Report'); ?></button>
        <button onclick="window.close()" class="btn btn-secondary"><?php echo xlt('Close'); ?></button>
    </div>
</body>
</html>
