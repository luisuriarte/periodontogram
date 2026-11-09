<?php
/**
 * new.php — Periodontal Chart (v2, perio-tools style)
 * Full-mouth chart with 6 sites per tooth, tooth status, mobility, furcation,
 * BOP/plaque/suppuration per site and global statistics.
 *
 * Data model (see table_v2.sql):
 *   form_periodontogram            (header, chart_version=2)
 *   form_periodontogram_tooth      (one row per present/missing/implant tooth)
 *   form_periodontogram_site       (one row per site, site 1..6)
 * Legacy tables (form_periodontogram_detail) remain readable via migration.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/translation.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Services\PatientService;

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$csrf_token = CsrfUtils::collectCsrfToken($session);
$formid = (int)($_GET['id'] ?? 0);
$pid = (int)($session->get('pid') ?? $_GET['pid'] ?? 0);
$encounter = (int)($session->get('encounter') ?? $_GET['encounter'] ?? 0);

// NOTE: Do NOT redirect to login here. globals.php already enforces
// authentication and load_form.php already passed the ACL check.
// In OpenEMR 8.4 the auth key lives in the Symfony session wrapper
// ($session->get('authUserID')), not necessarily in $_SESSION.

// ---- Patient demographics via Service (OpenEMR core) ----
$patientService = new PatientService();
$patientData = [];
if ($pid > 0) {
    // findByPid returns associative array with demographics
    $p = $patientService->findByPid($pid);
    if (!empty($p)) {
        $patientData = $p;
    }
}
$patientName = trim(($patientData['lname'] ?? '') . ', ' . ($patientData['fname'] ?? ''));
if ($patientName === ',') {
    $patientName = '';
}
$patientDob = $patientData['DOB'] ?? '';

// ---- Form header (OpenEMR forms table -> form_periodontogram) ----
// Resolve forms.id and form_periodontogram.id cleanly with fallback to encounter
$examHeader = [];
$perioId = 0;
$formsId = 0;

$incomingId = (int)($_GET['id'] ?? 0);
if ($incomingId > 0) {
    // 1. Check if incomingId is a forms.id specifically for periodontics
    $link = sqlQuery("SELECT id, form_id FROM forms WHERE id = ? AND formdir = 'periodontics' LIMIT 1", [$incomingId]);
    if ($link && !empty($link['form_id'])) {
        $formsId = (int)$link['id'];
        $candId = (int)$link['form_id'];
        $row = sqlQuery("SELECT * FROM form_periodontogram WHERE id = ? AND pid = ? LIMIT 1", [$candId, $pid]);
        if ($row) {
            $examHeader = $row;
            $perioId = (int)$row['id'];
        }
    }
    // 2. Direct lookup: incomingId might be form_periodontogram.id directly
    if (!$perioId) {
        $row = sqlQuery("SELECT * FROM form_periodontogram WHERE id = ? AND pid = ? LIMIT 1", [$incomingId, $pid]);
        if ($row) {
            $examHeader = $row;
            $perioId = (int)$row['id'];
            $chkF = sqlQuery("SELECT id FROM forms WHERE form_id = ? AND formdir = 'periodontics' LIMIT 1", [$perioId]);
            if ($chkF) {
                $formsId = (int)$chkF['id'];
            }
        }
    }
}

// 3. Fallback: if no specific id resolved, look for latest exam for this patient & encounter
if (!$perioId && $encounter > 0) {
    $row = sqlQuery("SELECT * FROM form_periodontogram WHERE pid = ? AND encounter = ? ORDER BY id DESC LIMIT 1", [$pid, $encounter]);
    if ($row) {
        $examHeader = $row;
        $perioId = (int)$row['id'];
        $chkF = sqlQuery("SELECT id FROM forms WHERE form_id = ? AND formdir = 'periodontics' LIMIT 1", [$perioId]);
        if ($chkF) {
            $formsId = (int)$chkF['id'];
        }
    }
}

// ---- Load v2 data if available, else convert legacy ----
$v2Teeth = null;
$v2Available = true;
try {
    $chk = sqlQuery("SELECT `id` FROM `form_periodontogram_tooth` WHERE periodontogram_id = ? LIMIT 1", [$perioId]);
    $v2Available = ($chk !== false && $chk !== null);
} catch (Exception $e) {
    $v2Available = false;
}

$legacyDetails = [];
if ($perioId || $formid) {
    $srcId = $perioId ?: $formid;
    // Try legacy detail table (still present for migration)
    $res = sqlStatement(
        "SELECT * FROM form_periodontogram_detail WHERE periodontogram_id = ? ORDER BY tooth_fdi, surface, point",
        [$srcId]
    );
    while ($row = sqlFetchArray($res)) {
        $legacyDetails[] = $row;
    }
}

// Build legacy -> v2 conversion in PHP (server side) for initial JS state
// Legacy: surface V/P x point 1,2,3 (mesial/mid/distal)
// V2 sites: 1=MB, 2=B, 3=DB, 4=DL, 5=L, 6=ML (occlusal view)
// Mapping function mirrors perio-tools convention (not FDI side-dependent).
function legacyToV2($tooth, $surface, $point)
{
    // V mesial->MB(1), V mid->B(2), V distal->DB(3)
    // P mesial->ML(6), P mid->L(5),  P distal->DL(4)
    $map = [
        'V' => [1 => 1, 2 => 2, 3 => 3],
        'P' => [1 => 6, 2 => 5, 3 => 4],
    ];
    return $map[$surface][$point] ?? null;
}

$v2State = []; // fdi => [status, mobility, furc[], sites[ site => [pd,gm,bop,plaque,sup] ] ]
if ($v2Available && $perioId) {
    $resT = sqlStatement(
        "SELECT * FROM form_periodontogram_tooth WHERE periodontogram_id = ? ORDER BY tooth_fdi",
        [$perioId]
    );
    while ($t = sqlFetchArray($resT)) {
        $fdi = (int)$t['tooth_fdi'];
        $v2State[$fdi] = [
            'status' => $t['status'] ?? 'present',
            'mobility' => isset($t['mobility']) ? (int)$t['mobility'] : 0,
            'furcation' => [
                'buccal' => isset($t['furcation_buccal']) ? (int)$t['furcation_buccal'] : 0,
                'lingual' => isset($t['furcation_lingual']) ? (int)$t['furcation_lingual'] : 0,
                'palatal' => isset($t['furcation_palatal']) ? (int)$t['furcation_palatal'] : 0,
            ],
            'notes' => $t['notes'] ?? '',
            'sites' => [],
        ];
        $resS = sqlStatement(
            "SELECT * FROM form_periodontogram_site WHERE periodontogram_id = ? AND tooth_fdi = ? ORDER BY site",
            [$perioId, $fdi]
        );
        while ($s = sqlFetchArray($resS)) {
            $site = (int)$s['site'];
            $pd = isset($s['probing_depth']) && $s['probing_depth'] !== null ? (int)$s['probing_depth'] : null;
            $gm = isset($s['gingival_margin']) && $s['gingival_margin'] !== null ? (int)$s['gingival_margin'] : null;
            $v2State[$fdi]['sites'][$site] = [
                'pd' => $pd,
                'gm' => $gm,
                'bop' => (int)($s['bleeding'] ?? 0),
                'plaque' => (int)($s['plaque'] ?? 0),
                'supp' => (int)($s['suppuration'] ?? 0),
            ];
        }
    }
}

// If no v2 state but legacy exists, convert
if (empty($v2State) && !empty($legacyDetails)) {
    foreach ($legacyDetails as $row) {
        $fdi = (int)$row['tooth_fdi'];
        $site = legacyToV2($fdi, $row['surface'], (int)$row['point']);
        if (!$site) continue;
        if (!isset($v2State[$fdi])) {
            $v2State[$fdi] = [
                'status' => 'present',
                'mobility' => isset($row['mobility']) ? (int)$row['mobility'] : 0,
                'furcation' => [
                    'buccal' => ($row['surface'] === 'V' && isset($row['furcation'])) ? (int)$row['furcation'] : 0,
                    'lingual' => 0,
                    'palatal' => ($row['surface'] === 'P' && ($fdi >= 11 && $fdi <= 28) && isset($row['furcation'])) ? (int)$row['furcation'] : 0,
                ],
                'notes' => '',
                'sites' => [],
            ];
        }
        // Legacy recession is unsigned (0..12, recession positive). Convert to signed GM: GM = -recession.
        // PD legacy unchanged. This preserves CAL = PD - GM = PD + recession_old.
        $pd = isset($row['probing_depth']) && $row['probing_depth'] !== null ? (int)$row['probing_depth'] : null;
        $rec = isset($row['recession']) && $row['recession'] !== null ? (int)$row['recession'] : null;
        $gm = ($rec !== null) ? -$rec : null;
        $v2State[$fdi]['sites'][$site] = [
            'pd' => $pd,
            'gm' => $gm,
            'bop' => (int)($row['bleeding'] ?? 0),
            'plaque' => 0,
            'supp' => (int)($row['suppuration'] ?? 0),
        ];
    }
}

// ---- List options via ListService-style query, output translated via xl() ----
function getListOptionsTranslated($list_id)
{
    $opts = [];
    $res = sqlStatement(
        "SELECT option_id, title, seq FROM list_options WHERE list_id = ? AND activity = 1 ORDER BY seq",
        [$list_id]
    );
    while ($row = sqlFetchArray($res)) {
        // Title is English source stored in DB; xl() resolves to current language at runtime
        $opts[] = [
            'option_id' => $row['option_id'],
            'title' => xl($row['title']),
            'seq' => $row['seq'],
        ];
    }
    return $opts;
}
$diagOptions = getListOptionsTranslated('perio_diagnosis');
$gradeOptions = getListOptionsTranslated('perio_grade');
$statusOptions = getListOptionsTranslated('perio_tooth_status');
if (empty($statusOptions)) {
    $statusOptions = [
        ['option_id' => 'present', 'title' => xl('Present')],
        ['option_id' => 'missing', 'title' => xl('Missing')],
        ['option_id' => 'implant', 'title' => xl('Implant')],
    ];
}

// ---- FDI teeth layout ----
$upperTeeth = [18,17,16,15,14,13,12,11, 21,22,23,24,25,26,27,28];
$lowerTeeth = [48,47,46,45,44,43,42,41, 31,32,33,34,35,36,37,38];
$molarFDI = [16,17,18,26,27,28,36,37,38,46,47,48,14,24]; // furcation-relevant

// Translation map for JS (all strings via xl/xlt in PHP, then json_encoded)
$jsTranslations = [
    'present' => xl('Present'),
    'missing' => xl('Missing'),
    'implant' => xl('Implant'),
    'mobility' => xl('Mobility'),
    'furcation' => xl('Furcation'),
    'probingDepth' => xl('Probing Depth'),
    'gingivalMargin' => xl('Gingival Margin'),
    'attachmentLevel' => xl('Attachment Level'),
    'bleeding' => xl('Bleeding on Probing'),
    'plaque' => xl('Plaque'),
    'suppuration' => xl('Suppuration'),
    'saveExam' => xl('Save Exam'),
    'saving' => xl('Saving...'),
    'savedOk' => xl('Periodontal exam saved successfully'),
    'saveError' => xl('Error saving exam'),
    'cancel' => xl('Cancel'),
    'clickToChangeStatus' => xl('Click to change tooth status (present/missing/implant)'),
    'toothStatus' => xl('Tooth status'),
    'upperArch' => xl('Upper Arch'),
    'lowerArch' => xl('Lower Arch'),
    'averagePd' => xl('Average Probing Depth'),
    'averageAl' => xl('Average Attachment Level'),
    'bopPercent' => xl('BOP'),
    'piPercent' => xl('PI'),
    'healthy' => xl('Healthy (1-3 mm)'),
    'warning' => xl('Warning (4-5 mm)'),
    'danger' => xl('Danger (6 mm or more)'),
    'buccal' => xl('Buccal'),
    'lingual' => xl('Lingual'),
    'palatal' => xl('Palatal'),
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo xlt('Periodontal Chart'); ?></title>
    <?php Header::setupHeader(['jquery', 'jquery-ui', 'bootstrap', 'fontawesome']); ?>
    <style>
        :root { --pd-green:#28a745; --pd-amber:#ffc107; --pd-red:#dc3545; --gm-blue:#007bff; --implant:#17a2b8; }
        body { font-size: 12px; }
        .perio-toolbar { position: sticky; top:0; z-index: 10; background:#fff; padding:6px 0; border-bottom:1px solid #dee2e6; }
        .arch-section { margin-top:18px; }
        .arch-title { font-weight:700; letter-spacing:.5px; text-transform:uppercase; font-size:12px; color:#495057; margin-bottom:6px; }
        .teeth-row { display:flex; gap:6px; overflow-x:auto; padding:6px 2px 12px 2px; align-items:stretch; }
        .teeth-row .midline-spacer { margin:0 10px; border-left:3px solid #343a40; }
        .tooth-card {
            flex:0 0 118px; min-width:118px; background:#fff; border:1px solid #ced4da; border-radius:8px;
            display:flex; flex-direction:column; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,.06);
        }
        .tooth-card.status-missing { background:#f1f3f5; opacity:.65; }
        .tooth-card.status-missing .tooth-art { display:none; }
        .tooth-card.status-implant { border-color:var(--implant); }
        .tooth-header { display:flex; align-items:center; justify-content:space-between; padding:4px 6px; background:#343a40; }
        .tooth-card.status-missing .tooth-header { background:#6c757d; }
        .tooth-card.status-implant .tooth-header { background:var(--implant); }
        .tooth-num {
            background:transparent; border:0; color:#fff; font-weight:700; font-size:14px; padding:0 2px; cursor:pointer; line-height:1;
        }
        .tooth-num:hover { text-decoration:underline; }
        .tooth-status-badge { font-size:9px; color:#fff; border:1px solid rgba(255,255,255,.6); border-radius:10px; padding:1px 5px; text-transform:uppercase; letter-spacing:.4px; }
        .tooth-controls { display:flex; gap:3px; padding:4px 4px 2px; background:#f8f9fa; border-bottom:1px solid #e9ecef; }
        .tooth-controls select { flex:1; font-size:10px; padding:1px 2px; border-radius:4px; }
        .tooth-controls label { font-size:9px; margin:0; color:#6c757d; }
        .fur-row { display:flex; gap:2px; padding:2px 4px 4px; background:#f8f9fa; border-bottom:1px solid #e9ecef; }
        .fur-row select { flex:1; font-size:9px; padding:0 1px; }
        .tooth-card.tooth-upper .sites-grid { border-bottom:1px solid #e9ecef; }
        .tooth-card.tooth-upper .fur-row { border-top:1px solid #e9ecef; border-bottom:1px solid #e9ecef; }
        .tooth-card.tooth-upper .tooth-controls { border-top:1px solid #e9ecef; border-bottom:none; }
        .tooth-card.tooth-upper .fur-row + .tooth-controls { border-top:none; }
        .tooth-chart { position:relative; background:#fff; aspect-ratio: 72 / 96; }
        .tooth-chart svg { display:block; width:100%; height:100%; }
        .tooth-art { position:absolute; inset:0; width:100%; height:100%; object-fit:contain; }
        .sites-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:2px; padding:4px; background:#fff; }
        .site-cell { border:1px solid #e9ecef; border-radius:5px; padding:2px 2px 3px; text-align:center; }
        .site-cell .site-label { font-size:8px; color:#868e96; letter-spacing:.3px; }
        .site-cell input { width:100%; border:0; text-align:center; font-size:11px; padding:1px 0; background:transparent; }
        .site-cell input:focus { outline:1px solid #007bff; border-radius:3px; }
        .site-cell input.pd-input { font-weight:700; }
        .site-cell input.gm-input { color:var(--gm-blue); font-size:10px; }
        .pd-healthy { background:#d4edda !important; }
        .pd-warning { background:#fff3cd !important; }
        .pd-danger  { background:#f8d7da !important; }
        .site-toggles { display:flex; gap:2px; justify-content:center; margin-top:2px; }
        .site-toggles button { font-size:9px; width:16px; height:16px; border-radius:50%; border:1px solid #ced4da; background:#fff; cursor:pointer; line-height:1; }
        .site-toggles button.bop-on { background:#dc3545; color:#fff; border-color:#dc3545; }
        .site-toggles button.plaque-on { background:#ffc107; color:#212529; border-color:#ffc107; }
        .site-toggles button.supp-on { background:#6f42c1; color:#fff; border-color:#6f42c1; }
        .statsbar { display:flex; gap:8px; flex-wrap:wrap; }
        .stat-pill { background:#e7f5ff; border:1px solid #a5d8ff; border-radius:20px; padding:4px 12px; font-weight:700; }
        .legend-dot { display:inline-block; width:10px; height:10px; border-radius:50%; vertical-align:middle; }
        .num-note { font-size:10px; color:#6c757d; }
        @media print { .no-print { display:none !important; } .teeth-row { overflow:visible; flex-wrap:nowrap; } }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <h2 class="mb-1"><i class="fa fa-tooth"></i> <?php echo xlt('Periodontal Chart'); ?></h2>
        <div class="statsbar" id="statsbar">
            <span class="stat-pill"><span id="stat-pd">0.0</span> mm <?php echo xlt('Avg PD'); ?></span>
            <span class="stat-pill"><span id="stat-al">0.0</span> mm <?php echo xlt('Avg AL'); ?></span>
            <span class="stat-pill">BOP <span id="stat-bop">0</span>%</span>
            <span class="stat-pill">PI <span id="stat-pi">0</span>%</span>
            <span class="stat-pill" id="stat-teeth">32/32</span>
        </div>
    </div>

    <div class="text-muted mb-2">
        <?php if (!empty($patientName)): ?>
            <strong><?php echo text($patientName); ?></strong>
            <?php if (!empty($patientDob)): ?> · <?php echo text($patientDob); ?><?php endif; ?>
        <?php endif; ?>
        <?php if (!empty($examHeader['date'])): ?> · <?php echo text($examHeader['date']); ?><?php endif; ?>
    </div>

    <!-- Diagnosis / notes -->
    <div class="row mb-3 p-3 bg-light border rounded no-print">
        <div class="col-md-4">
            <label class="font-weight-bold" for="perio_diagnosis"><?php echo xlt('Diagnosis'); ?></label>
            <select id="perio_diagnosis" class="form-control form-control-sm">
                <option value=""><?php echo xlt('-- Select --'); ?></option>
                <?php foreach ($diagOptions as $opt): ?>
                <option value="<?php echo attr($opt['option_id']); ?>"
                    <?php echo (($examHeader['diagnosis'] ?? '') === $opt['option_id']) ? 'selected' : ''; ?>>
                    <?php echo text($opt['title']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="font-weight-bold" for="perio_grade"><?php echo xlt('Grade'); ?></label>
            <select id="perio_grade" class="form-control form-control-sm">
                <option value=""><?php echo xlt('-- Select --'); ?></option>
                <?php foreach ($gradeOptions as $opt): ?>
                <option value="<?php echo attr($opt['option_id']); ?>"
                    <?php echo (($examHeader['grade'] ?? '') === $opt['option_id']) ? 'selected' : ''; ?>>
                    <?php echo text($opt['title']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="font-weight-bold" for="perio_notes"><?php echo xlt('Clinical Notes'); ?></label>
            <input type="text" id="perio_notes" class="form-control form-control-sm"
                   value="<?php echo attr($examHeader['notes'] ?? ''); ?>"
                   placeholder="<?php echo xla('Clinical notes'); ?>">
        </div>
    </div>

    <!-- Toolbar -->
    <div class="perio-toolbar d-flex flex-wrap gap-2 align-items-center no-print">
        <span class="font-weight-bold mr-2"><?php echo xlt('Tooth Numbering'); ?>:</span>
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" id="btn-fdi" class="btn btn-primary"><?php echo xlt('FDI'); ?></button>
            <button type="button" id="btn-univ" class="btn btn-outline-secondary"><?php echo xlt('Universal'); ?></button>
            <button type="button" id="btn-palmer" class="btn btn-outline-secondary"><?php echo xlt('Palmer'); ?></button>
            <button type="button" id="btn-haderup" class="btn btn-outline-secondary"><?php echo xlt('Haderup'); ?></button>
        </div>
        <span class="ml-3 text-muted num-note">
            <?php echo xlt('Click tooth number to cycle Present / Missing / Implant. AL = PD - GM.'); ?>
        </span>
        <div class="ml-auto d-flex gap-2">
            <button type="button" id="btn-print" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-print"></i> <?php echo xla('Print'); ?>
            </button>
            <button type="button" id="btn-save" class="btn btn-sm btn-success">
                <i class="fa fa-save"></i> <span id="save-label"><?php echo xlt('Save Exam'); ?></span>
            </button>
            <a href="<?php echo $GLOBALS['form_exit_url']; ?>" class="btn btn-sm btn-secondary" onclick="top.restoreSession()">
                <?php echo xlt('Cancel'); ?>
            </a>
        </div>
    </div>

    <!-- Ach instructions -->
    <div class="alert alert-light border mt-2 small">
        <strong><?php echo xlt('Sites'); ?>:</strong> MB · B · DB / DL · L · ML &nbsp;|&nbsp;
        <span class="d-inline-block px-2 rounded pd-healthy">≤3 mm</span>
        <span class="d-inline-block px-2 rounded pd-warning">4-5 mm</span>
        <span class="d-inline-block px-2 rounded pd-danger">≥6 mm</span> &nbsp;|&nbsp;
        <span class="legend-dot" style="background:#dc3545"></span> <?php echo xlt('Bleeding'); ?> &nbsp;
        <span class="legend-dot" style="background:#ffc107"></span> <?php echo xlt('Plaque'); ?> &nbsp;
        <span class="legend-dot" style="background:#6f42c1"></span> <?php echo xlt('Suppuration'); ?> &nbsp;|&nbsp;
        <span style="color:#007bff">—</span> GM &nbsp;
        <span style="color:#dc3545">—</span> PD
    </div>

    <!-- Arches rendered by JS -->
    <div class="arch-section">
        <div class="arch-title"><?php echo xlt('Upper Arch'); ?></div>
        <div class="teeth-row" id="teeth-upper"></div>
    </div>

    <div class="arch-section">
        <div class="arch-title"><?php echo xlt('Lower Arch'); ?></div>
        <div class="teeth-row" id="teeth-lower"></div>
    </div>

    <div class="mt-3">
        <button type="button" id="btn-save-bottom" class="btn btn-success no-print">
            <i class="fa fa-save"></i> <?php echo xlt('Save Exam'); ?>
        </button>
        <a href="<?php echo $GLOBALS['form_exit_url']; ?>" class="btn btn-secondary no-print" onclick="top.restoreSession()">
            <?php echo xlt('Cancel'); ?>
        </a>
    </div>
</div>

<script>
    window.PerioI18n = <?php echo json_encode($jsTranslations); ?>;
    window.PerioConfig = {
        csrfToken: <?php echo json_encode($csrf_token); ?>,
        formId: <?php echo json_encode((int)($formsId ?: $perioId)); ?>,
        formsId: <?php echo json_encode((int)$formsId); ?>,
        perioId: <?php echo json_encode((int)$perioId); ?>,
        webRoot: <?php echo json_encode($GLOBALS['web_root']); ?>,
        upperTeeth: <?php echo json_encode($upperTeeth); ?>,
        lowerTeeth: <?php echo json_encode($lowerTeeth); ?>,
        molarFDI: <?php echo json_encode($molarFDI); ?>,
        initialState: <?php echo json_encode($v2State); ?>,
        legacyDetails: <?php echo json_encode($legacyDetails); ?>,
        v2Available: <?php echo json_encode($v2Available); ?>,
        saveUrl: <?php echo json_encode($GLOBALS['web_root'] . '/interface/forms/periodontics/save.php'); ?>,
        exitUrl: <?php echo json_encode($GLOBALS['form_exit_url']); ?>
    };
</script>
<script src="<?php echo $GLOBALS['web_root']; ?>/interface/forms/periodontics/js/periodontalChart.js?v=<?php echo time(); ?>"></script>
</body>
</html>