<?php
/**
 * new.php — Periodontograma
 * Formulario principal de registro del examen periodontal
 */
require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/translation.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$formid   = (int)($_GET['id'] ?? 0);
$csrf_token = CsrfUtils::collectCsrfToken();
$pid      = $_SESSION['pid']        ?? 0;
$encounter= $_SESSION['encounter']  ?? 0;
$userId   = $_SESSION['authUserID'] ?? 0;

if (empty($_SESSION['authUserID'])) {
    header("Location: $web_root/interface/login/login.php?site=default");
    exit;
}

// Cargar datos existentes de la cabecera
$examHeader = [];
$examDetails = [];
if ($formid) {
    $examHeader = sqlQuery(
        "SELECT * FROM form_periodontogram WHERE id = ? AND pid = ?",
        [$formid, $pid]
    ) ?: [];

    $res = sqlStatement(
        "SELECT * FROM form_periodontogram_detail WHERE periodontogram_id = ? ORDER BY tooth_fdi, surface, point",
        [$formid]
    );
    while ($row = sqlFetchArray($res)) {
        $examDetails[] = $row;
    }
}

// Cargar list_options para los selects
function getListOptions($list_id) {
    $opts = [];
    $res = sqlStatement(
        "SELECT option_id, title FROM list_options WHERE list_id = ? AND activity = 1 ORDER BY seq",
        [$list_id]
    );
    while ($row = sqlFetchArray($res)) {
        $opts[] = $row;
    }
    return $opts;
}
$diagOptions  = getListOptions('perio_diagnosis');
$gradeOptions = getListOptions('perio_grade');

// Dientes adultos por cuadrante (FDI)
// Cuadrante 1: 18-11 (superior derecho), 2: 21-28 (superior izquierdo)
// Cuadrante 4: 48-41 (inferior derecho), 3: 31-38 (inferior izquierdo)
$upperRight = [18,17,16,15,14,13,12,11];
$upperLeft  = [21,22,23,24,25,26,27,28];
$lowerLeft  = [31,32,33,34,35,36,37,38];
$lowerRight = [41,42,43,44,45,46,47,48];

$upperTeeth = array_merge($upperRight, $upperLeft); // 16 dientes superiores
$lowerTeeth = array_merge($lowerRight, $lowerLeft); // 16 dientes inferiores

// Molares para furca: 16,17,18,26,27,28,36,37,38,46,47,48
$molarFDI = [16,17,18,26,27,28,36,37,38,46,47,48];

// Convertir detalles a mapa JS
$detailMap = [];
foreach ($examDetails as $row) {
    $key = $row['tooth_fdi'] . '_' . $row['surface'] . '_' . $row['point'];
    $detailMap[$key] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo xlt('Periodontogram'); ?></title>
    <?php Header::setupHeader(['jquery-ui', 'bootstrap', 'fontawesome']); ?>
    <style>
        :root {
            --color-green:  #28a745;
            --color-yellow: #ffc107;
            --color-orange: #fd7e14;
            --color-red:    #dc3545;
            --color-text-light: #fff;
        }

        body { font-size: 13px; }

        h2 { font-size: 1.3rem; margin-bottom: 0.5rem; }

        /* ========================
           GRILLA PERIODONTAL
        ======================== */
        .perio-wrap {
            overflow-x: auto;
            margin-top: 10px;
        }

        table.perio-grid {
            border-collapse: collapse;
            font-size: 11px;
            white-space: nowrap;
        }

        table.perio-grid th,
        table.perio-grid td {
            border: 1px solid #ccc;
            text-align: center;
            padding: 1px 2px;
            vertical-align: middle;
        }

        /* Etiqueta de fila (izquierda) */
        table.perio-grid td.row-label {
            font-weight: bold;
            background: #f0f0f0;
            padding: 2px 6px;
            text-align: right;
            border-right: 2px solid #999;
            min-width: 80px;
        }

        /* Celda de número de diente */
        table.perio-grid td.tooth-num {
            background: #3a3a3a;
            color: #fff;
            font-weight: bold;
            font-size: 12px;
            min-width: 22px;
            height: 22px;
        }

        /* Cuadrante separador */
        table.perio-grid td.midline {
            border-left: 3px solid #333 !important;
        }

        /* Input numeric depth inputs */
        input.pd-input {
            width: 26px;
            border: none;
            text-align: center;
            background: transparent;
            font-size: 11px;
            padding: 0;
        }
        input.pd-input:focus {
            outline: 1px solid #007bff;
        }

        /* Depth Risk Colors */
        .depth-0   { background: #ffffff; }
        .depth-1   { background: #d4edda; }  /* 1–3 mm Green (Healthy) */
        .depth-2   { background: #fff3cd; }  /* 4–5 mm Yellow (Warning) */
        .depth-3   { background: #f8d7da; }  /* ≥6 mm Red (Danger) */

        /* Checkbox de sangrado */
        input.bop-check {
            width: 14px;
            height: 14px;
            cursor: pointer;
            accent-color: #dc3545;
        }

        /* Checkbox de supuración */
        input.sup-check {
            width: 14px;
            height: 14px;
            cursor: pointer;
            accent-color: #6f42c1;
        }

        /* CAL (Calculated) Cell */
        span.cal-val {
            font-weight: bold;
            color: #555;
        }

        /* Select compacto */
        select.mini-sel {
            width: 40px;
            font-size: 10px;
            padding: 0;
            border: 1px solid #ccc;
            text-align: center;
        }

        /* Arco separator */
        .arc-header th {
            background: #4a6fa5;
            color: #fff;
            font-size: 12px;
            padding: 3px 6px;
            text-align: left;
        }

        .arc-separator td {
            height: 10px;
            background: #e9e9e9;
        }

        /* Panel de resumen BOP */
        #bop-summary {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 14px;
            background: #e9f5ff;
            border: 1px solid #cce5ff;
        }

        .form_section {
            padding: 10px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            margin-bottom: 10px;
        }

        /* ========================
           SVG CHART STYLES
        ======================== */
        .chart-container {
            background: #222;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            overflow-x: auto;
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
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            background: #333;
            padding: 4px 0;
            border-bottom: 1px solid #444;
        }
        .chart-row {
            display: flex;
            gap: 25px;
            margin-bottom: 30px;
            margin-top: 15px; /* Extra top margin below the form */
            flex-wrap: wrap;
        }
        .chart-row .chart-container {
            flex: 1;
            min-width: 450px;
        }
    </style>
    <?php formHeader("Form: Periodontogram"); ?>
    <script>
        var csrfToken  = '<?php echo attr($csrf_token); ?>';
        var webRoot    = '<?php echo $web_root; ?>';
        var formId     = <?php echo (int)$formid; ?>;
        var molarFDI   = <?php echo json_encode($molarFDI); ?>;
        var existingData = <?php echo json_encode($detailMap); ?>;
    </script>
</head>
<body class="body_top">
<div class="container-fluid">
    <h2><i class="fa fa-tooth"></i> <?php echo xlt('Periodontal Examination'); ?></h2>

    <input type="hidden" id="csrf_token_form" value="<?php echo attr($csrf_token); ?>">
    <input type="hidden" id="form_id" value="<?php echo attr($formid); ?>">

    <!-- Panel diagnóstico y notas -->
    <div class="form-section row mb-4 p-3 bg-light border rounded">
        <div class="col-md-4">
            <label class="font-weight-bold"><?php echo xlt('Diagnosis'); ?></label>
            <select id="perio_diagnosis" class="form-control form-control-sm">
                <option value=""><?php echo xlt('-- Select --'); ?></option>
                <?php foreach ($diagOptions as $opt): ?>
                <option value="<?php echo attr($opt['option_id']); ?>"
                    <?php echo (@$examHeader['diagnosis'] === $opt['option_id']) ? 'selected' : ''; ?>>
                    <?php echo text($opt['title']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="font-weight-bold"><?php echo xlt('Grade'); ?></label>
            <select id="perio_grade" class="form-control form-control-sm">
                <option value=""><?php echo xlt('-- Select --'); ?></option>
                <?php foreach ($gradeOptions as $opt): ?>
                <option value="<?php echo attr($opt['option_id']); ?>"
                    <?php echo (@$examHeader['grade'] === $opt['option_id']) ? 'selected' : ''; ?>>
                    <?php echo text($opt['title']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="font-weight-bold"><?php echo xlt('Clinical Notes'); ?></label>
            <input type="text" id="perio_notes" class="form-control form-control-sm"
                   value="<?php echo attr($examHeader['notes'] ?? ''); ?>">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <span id="bop-summary">BOP: <span id="bop-pct">0</span>%</span>
        </div>
    </div>

    <!-- Gráficos Visuales Superiores -->
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

    <!-- Grilla periodontal superior -->
    <div class="perio-wrap">
        <table class="perio-grid" id="grid-upper">
            <thead>
                <tr class="arc-header">
                    <th><?php echo xlt('UPPER ARCH'); ?></th>
                    <?php foreach ($upperTeeth as $i => $t): ?>
                    <td colspan="3"
                        class="tooth-num <?php echo ($i === 8) ? 'midline' : ''; ?>">
                        <?php echo $t; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody id="upper-body">
                <!-- Filas generadas por JS -->
            </tbody>
        </table>
    </div>

    <div style="height:8px;"></div>

    <!-- Gráficos Visuales Inferiores -->
    <div class="chart-row mt-3">
        <div class="chart-container">
            <div class="svg-title"><?php echo xlt('Lower Arch - Facial'); ?></div>
            <svg id="svg-lower-v" class="perio-svg" width="900" height="180" viewBox="0 0 900 180"></svg>
        </div>
        <div class="chart-container">
            <div class="svg-title"><?php echo xlt('Lower Arch - Lingual'); ?></div>
            <svg id="svg-lower-p" class="perio-svg" width="900" height="180" viewBox="0 0 900 180"></svg>
        </div>
    </div>

    <!-- Grilla periodontal inferior -->
    <div class="perio-wrap">
        <table class="perio-grid" id="grid-lower">
            <thead>
                <tr class="arc-header">
                    <th><?php echo xlt('LOWER ARCH'); ?></th>
                    <?php foreach ($lowerTeeth as $i => $t): ?>
                    <td colspan="3"
                        class="tooth-num <?php echo ($i === 8) ? 'midline' : ''; ?>">
                        <?php echo $t; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody id="lower-body">
                <!-- Filas generadas por JS -->
            </tbody>
        </table>
    </div>

    <!-- Leyenda -->
    <div class="mt-2" style="font-size:11px;">
        <span style="background:#d4edda;padding:2px 8px;border:1px solid #ccc;margin-right:4px;">≤3mm</span>
        <span style="background:#fff3cd;padding:2px 8px;border:1px solid #ccc;margin-right:4px;">4–5mm</span>
        <span style="background:#f8d7da;padding:2px 8px;border:1px solid #ccc;margin-right:4px;">≥6mm</span>
        <span style="color:#dc3545;margin-left:8px;"><b>●</b> <?php echo xlt('Bleeding'); ?></span>
        <span style="color:#6f42c1;margin-left:8px;"><b>●</b> <?php echo xlt('Suppuration'); ?></span>
    </div>

    <!-- Botones -->
    <div class="mt-3">
        <button type="button" id="btn-save" class="btn btn-success">
            <i class="fa fa-save"></i> <?php echo xlt('Save Exam'); ?>
        </button>
        <a href="<?php echo $GLOBALS['form_exit_url']; ?>" class="btn btn-secondary ml-2"
           onclick="top.restoreSession()">
            <?php echo xlt('Cancel'); ?>
        </a>
    </div>
</div>

    <!-- Global context for JS -->
    <script>
        var csrfToken = "<?php echo $csrf_token; ?>";
        var formId = "<?php echo (int)($formid ?? 0); ?>";
        var webRoot = "<?php echo $GLOBALS['web_root']; ?>";
        window.upperTeeth = <?php echo json_encode($upperTeeth); ?>;
        window.lowerTeeth = <?php echo json_encode($lowerTeeth); ?>;
        window.existingData = <?php echo json_encode($detailMap ?? (object)[]); ?>;
    </script>
    <script src="<?php echo $GLOBALS['web_root']; ?>/interface/forms/periodontics/js/periodontogram.js?v=<?php echo time(); ?>"></script>
</body>
</html>
<?php formFooter(); ?>
