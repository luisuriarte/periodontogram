/**
 * PerioChart - SVG Rendering Engine
 */
const PerioChart = {
    canvasWidth: 900,
    canvasHeight: 180,
    toothWidth: 900 / 16,
    scale: 5, // 1mm = 5px
    upperCEJ: 90,
    lowerCEJ: 90,
    molarFDI: [18,17,16,26,27,28,48,47,46,36,37,38],

    safeInt: function(val) {
        const i = parseInt(val);
        return isNaN(i) ? 0 : i;
    },

    init: function() {
        this.drawBase('svg-upper-v', true);
        this.drawBase('svg-upper-p', true);
        this.drawBase('svg-lower-v', false);
        this.drawBase('svg-lower-p', false);
        this.updateAll();
    },

    drawBase: function(id, isUpper) {
        const svg = document.getElementById(id);
        if (!svg) return;
        const cejY = isUpper ? this.upperCEJ : this.lowerCEJ;
        
        // Grid Lines
        let html = `<!-- Grid Lines -->`;
        for (let i = -5; i <= 15; i += 2) {
            const y = cejY + (isUpper ? -i : i) * this.scale;
            html += `<line x1="0" y1="${y}" x2="${this.canvasWidth}" y2="${y}" stroke="#444" stroke-opacity="0.3" stroke-width="1" />`;
        }

        // Vertical separators
        for (let i = 1; i < 16; i++) {
            const x = i * this.toothWidth;
            html += `<line x1="${x}" y1="0" x2="${x}" y2="${this.canvasHeight}" stroke="#333" stroke-width="${i===8?2:1}" />`;
        }

        // Draw Stylized Tooth Silhouettes
        const teeth = isUpper ? window.upperTeeth : window.lowerTeeth;
        if (!teeth || !teeth.length) {
            console.error("PerioChart: Teeth array not found for " + id);
            return;
        }

        teeth.forEach((t, idx) => {
            const x = idx * this.toothWidth;
            const centerX = x + this.toothWidth / 2;
            const w = this.toothWidth * 0.8;
            
            // Crown
            const crownHeight = 35;
            const crownY = isUpper ? cejY : cejY - crownHeight;
            html += `<rect x="${centerX - w/2}" y="${crownY}" width="${w}" height="${crownHeight}" fill="#ffffff" fill-opacity="0.25" rx="4" />`;
            
            // Root (stylized triangle)
            const rootHeight = 50;
            const rootY = isUpper ? cejY : cejY;
            const rootTipY = isUpper ? cejY - rootHeight : cejY + rootHeight;
            html += `<path d="M ${centerX - w/2},${rootY} L ${centerX + w/2},${rootY} L ${centerX},${rootTipY} Z" fill="#ffffff" fill-opacity="0.15" />`;
            
            // Tooth Label
            html += `<text x="${centerX}" y="${isUpper?175:15}" fill="#ffffff" font-size="10" font-weight="bold" text-anchor="middle">${t}</text>`;
        });
        
        svg.innerHTML = html + `<g id="${id}-data"></g>`;
    },

    updateAll: function() {
        this.renderArch('svg-upper-v', window.upperTeeth, true, 'V');
        this.renderArch('svg-upper-p', window.upperTeeth, true, 'P');
        this.renderArch('svg-lower-v', window.lowerTeeth, false, 'V');
        this.renderArch('svg-lower-p', window.lowerTeeth, false, 'P');
    },

    renderArch: function(id, teeth, isUpper, surfaceType) {
        const container = document.getElementById(id + '-data');
        if (!container) return;

        let gmPath = "";
        let pdPath = "";
        let fillPath = "";
        let markers = "";

        const cejY = isUpper ? this.upperCEJ : this.lowerCEJ;
        const dir = isUpper ? -1 : 1;
        let points = [];

        teeth.forEach((t, tIdx) => {
            const centerX = (tIdx + 0.5) * this.toothWidth;
            [1, 2, 3].forEach((pt, ptIdx) => {
                const x = centerX + (ptIdx - 1) * (this.toothWidth / 3.5);
                
                const pdInput = $(`.pd-input[data-tooth="${t}"][data-surface="${surfaceType}"][data-point="${pt}"][data-type="pd"]`);
                const gmInput = $(`.pd-input[data-tooth="${t}"][data-surface="${surfaceType}"][data-point="${pt}"][data-type="gm"]`);
                const bopCheck = $(`.bop-check[data-tooth="${t}"][data-surface="${surfaceType}"][data-point="${pt}"]`);
                const supCheck = $(`.sup-check[data-tooth="${t}"][data-surface="${surfaceType}"][data-point="${pt}"]`);

                const pd = this.safeInt(pdInput.val());
                const gm = this.safeInt(gmInput.val());
                const hasBop = bopCheck.is(':checked');
                const hasSup = supCheck.is(':checked');

                const yGM = cejY + (gm * dir * this.scale);
                const yPD = cejY + ((gm + pd) * dir * this.scale);

                points.push({ x, yGM, yPD });

                if (hasBop) {
                    markers += `<circle cx="${x}" cy="${isUpper?yPD - 3:yPD + 3}" r="3" fill="#dc3545" />`;
                }
                if (hasSup) {
                    markers += `<circle cx="${x}" cy="${isUpper?yPD - 8:yPD + 8}" r="3" fill="#6f42c1" />`;
                }
            });
        });

        if (points.length > 0) {
            gmPath = "M " + points.map(p => `${p.x},${p.yGM}`).join(" L ");
            pdPath = "M " + points.map(p => `${p.x},${p.yPD}`).join(" L ");
            
            fillPath = "M " + points[0].x + "," + points[0].yGM;
            points.forEach(p => fillPath += ` L ${p.x},${p.yPD}`);
            for (let i = points.length - 1; i >= 0; i--) {
                fillPath += ` L ${points[i].x},${points[i].yGM}`;
            }
            fillPath += " Z";
        }

        container.innerHTML = `
            <path d="${fillPath}" fill="#dc3545" fill-opacity="0.3" />
            <path d="${gmPath}" fill="none" stroke="#007bff" stroke-width="2" stroke-linejoin="round" />
            <path d="${pdPath}" fill="none" stroke="#dc3545" stroke-width="2" stroke-linejoin="round" />
            ${markers}
        `;
    },

    loadFromObject: function(dataObject) {
        window.existingData = dataObject;
        this.init();
    }
};

$(document).ready(function() {
    const upperBody = $('#upper-body');
    const lowerBody = $('#lower-body');

    window.upperTeeth = [18,17,16,15,14,13,12,11, 21,22,23,24,25,26,27,28];
    window.lowerTeeth = [48,47,46,45,44,43,42,41, 31,32,33,34,35,36,37,38];

    function renderRow(container, label, teeth, type, surface) {
        let html = `<tr><td class="row-label">${label} (${surface})</td>`;
        teeth.forEach((t, idx) => {
            const isMidline = (idx === 8);
            const midlineClass = isMidline ? 'midline' : '';
            html += `<td colspan="3" class="${midlineClass}"><div style="display:flex; justify-content:space-around;">`;
            for (let pt = 1; pt <= 3; pt++) {
                const dbSurface = (surface === 'F') ? 'V' : 'P';
                const key = `${t}_${dbSurface}_${pt}`;
                const val = (window.existingData && window.existingData[key]) ? window.existingData[key] : {};
                
                if (type === 'pd') {
                    const pdVal = (val.probing_depth !== undefined && val.probing_depth !== null) ? val.probing_depth : '';
                    html += `<input type="number" class="pd-input depth-calc" data-tooth="${t}" data-surface="${dbSurface}" data-point="${pt}" data-type="pd" value="${pdVal}" min="0" max="20">`;
                } else if (type === 'gm') {
                    const gmVal = (val.recession !== undefined && val.recession !== null) ? val.recession : '';
                    html += `<input type="number" class="pd-input depth-calc" data-tooth="${t}" data-surface="${dbSurface}" data-point="${pt}" data-type="gm" value="${gmVal}" min="0" max="20">`;
                } else if (type === 'cal') {
                    const calVal = (val.probing_depth !== undefined && val.probing_depth !== null && val.recession !== undefined && val.recession !== null) 
                        ? (parseInt(val.probing_depth) + parseInt(val.recession)) : '--';
                    html += `<span class="cal-val" id="cal_${key}">${calVal}</span>`;
                } else if (type === 'bop') {
                    const checked = val.bleeding == 1 ? 'checked' : '';
                    html += `<input type="checkbox" class="bop-check" data-tooth="${t}" data-surface="${dbSurface}" data-point="${pt}" ${checked}>`;
                } else if (type === 'sup') {
                    const checked = val.suppuration == 1 ? 'checked' : '';
                    html += `<input type="checkbox" class="sup-check" data-tooth="${t}" data-surface="${dbSurface}" data-point="${pt}" ${checked}>`;
                } else if (type === 'fur') {
                    if (pt === 2 && PerioChart.molarFDI.includes(t)) {
                        const furVal = (val.furcation !== undefined && val.furcation !== null) ? val.furcation : '0';
                        html += `<select class="mini-sel fur-sel" data-tooth="${t}" data-surface="${dbSurface}" data-point="${pt}"><option value="0" ${furVal=='0'?'selected':''}>-</option><option value="1" ${furVal=='1'?'selected':''}>I</option><option value="2" ${furVal=='2'?'selected':''}>II</option><option value="3" ${furVal=='3'?'selected':''}>III</option></select>`;
                    } else html += `<span style="width:20px;"></span>`;
                } else if (type === 'mob') {
                    if (pt === 2 && dbSurface === 'V') {
                        const mobVal = (val.mobility !== undefined && val.mobility !== null) ? val.mobility : '0';
                        html += `<select class="mini-sel mob-sel" data-tooth="${t}" data-surface="${dbSurface}" data-point="${pt}"><option value="0" ${mobVal=='0'?'selected':''}>0</option><option value="1" ${mobVal=='1'?'selected':''}>I</option><option value="2" ${mobVal=='2'?'selected':''}>II</option><option value="3" ${mobVal=='3'?'selected':''}>III</option></select>`;
                    } else html += `<span style="width:20px;"></span>`;
                }
            }
            html += `</div></td>`;
        });
        html += `</tr>`;
        container.append(html);
    }

    if (upperBody.length) {
        renderRow(upperBody, 'Mobility', upperTeeth, 'mob', 'F');
        renderRow(upperBody, 'Furcation', upperTeeth, 'fur', 'F');
        renderRow(upperBody, 'PD', upperTeeth, 'pd', 'F');
        renderRow(upperBody, 'GM', upperTeeth, 'gm', 'F');
        renderRow(upperBody, 'CAL', upperTeeth, 'cal', 'F');
        renderRow(upperBody, 'BOP / Supp', upperTeeth, 'bop', 'F');
        renderRow(upperBody, 'BOP / Supp', upperTeeth, 'bop', 'P');
        renderRow(upperBody, 'CAL', upperTeeth, 'cal', 'P');
        renderRow(upperBody, 'GM', upperTeeth, 'gm', 'P');
        renderRow(upperBody, 'PD', upperTeeth, 'pd', 'P');
        renderRow(upperBody, 'Furcation', upperTeeth, 'fur', 'P');

        renderRow(lowerBody, 'Furcation', lowerTeeth, 'fur', 'F');
        renderRow(lowerBody, 'PD', lowerTeeth, 'pd', 'F');
        renderRow(lowerBody, 'GM', lowerTeeth, 'gm', 'F');
        renderRow(lowerBody, 'CAL', lowerTeeth, 'cal', 'F');
        renderRow(lowerBody, 'BOP / Supp', lowerTeeth, 'bop', 'F');
        renderRow(lowerBody, 'Mobility', lowerTeeth, 'mob', 'F');
        renderRow(lowerBody, 'BOP / Supp', lowerTeeth, 'bop', 'P');
        renderRow(lowerBody, 'CAL', lowerTeeth, 'cal', 'P');
        renderRow(lowerBody, 'GM', lowerTeeth, 'gm', 'P');
        renderRow(lowerBody, 'PD', lowerTeeth, 'pd', 'P');
        renderRow(lowerBody, 'Furcation', lowerTeeth, 'fur', 'P');
        
        PerioChart.init();
    }

    function updatePoint(tooth, surface, point) {
        const key = `${tooth}_${surface}_${point}`;
        const pdInput = $(`.pd-input[data-tooth="${tooth}"][data-surface="${surface}"][data-point="${point}"][data-type="pd"]`);
        const gmInput = $(`.pd-input[data-tooth="${tooth}"][data-surface="${surface}"][data-point="${point}"][data-type="gm"]`);
        const calSpan = $(`#cal_${key}`);
        const pdVal = parseInt(pdInput.val());
        const gmVal = parseInt(gmInput.val());

        pdInput.removeClass('depth-1 depth-2 depth-3');
        if (!isNaN(pdVal)) {
            if (pdVal >= 6) pdInput.addClass('depth-3');
            else if (pdVal >= 4) pdInput.addClass('depth-2');
            else if (pdVal >= 1) pdInput.addClass('depth-1');
        }
        if (!isNaN(pdVal) && !isNaN(gmVal)) calSpan.text(pdVal + gmVal);
        else calSpan.text('--');

        PerioChart.updateAll();
    }

    function calculateBOP() {
        const bleedingSites = $('.bop-check:checked').length;
        const totalSites = $('.bop-check').length;
        const pct = totalSites > 0 ? ((bleedingSites / totalSites) * 100).toFixed(1) : 0;
        $('#bop-pct').text(pct);
        PerioChart.updateAll();
    }

    $(document).on('input', '.depth-calc', function() { updatePoint($(this).data('tooth'), $(this).data('surface'), $(this).data('point')); });
    $(document).on('change', '.bop-check, .sup-check', function() { calculateBOP(); });

    $('.depth-calc').first().trigger('input');
    calculateBOP();

    $('#btn-save').on('click', function() {
        const details = [];
        $(`.pd-input[data-type="pd"]`).each(function() {
            const t = $(this).data('tooth'), s = $(this).data('surface'), p = $(this).data('point');
            const pdVal = $(this).val(), gmVal = $(`.pd-input[data-tooth="${t}"][data-surface="${s}"][data-point="${p}"][data-type="gm"]`).val();
            const bopVal = $(`.bop-check[data-tooth="${t}"][data-surface="${s}"][data-point="${p}"]`).is(':checked') ? 1 : 0;
            const supVal = $(`.sup-check[data-tooth="${t}"][data-surface="${s}"][data-point="${p}"]`).is(':checked') ? 1 : 0;
            let furVal = null; const furEl = $(`.fur-sel[data-tooth="${t}"][data-surface="${s}"][data-point="${p}"]`); if (furEl.length) furVal = furEl.val();
            let mobVal = null; const mobEl = $(`.mob-sel[data-tooth="${t}"][data-surface="${s}"][data-point="${p}"]`); if (mobEl.length) mobVal = mobEl.val();
            if (pdVal !== '' || gmVal !== '' || bopVal === 1 || supVal === 1 || (furVal && furVal!='0') || (mobVal && mobVal!='0')) {
                details.push({ tooth_fdi: t, surface: s, point: p, probing_depth: pdVal, recession: gmVal, bleeding: bopVal, suppuration: supVal, furcation: furVal, mobility: mobVal });
            }
        });
        const payload = { diagnosis: $('#perio_diagnosis').val(), grade: $('#perio_grade').val(), notes: $('#perio_notes').val(), details: details };
        $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
        $.post(webRoot + '/interface/forms/periodontics/save.php', { csrf_token_form: csrfToken, formid: formId, payload: JSON.stringify(payload) }, function(resp) {
            if (resp.success) { alert(resp.message); window.location.href = webRoot + '/interface/patient_file/encounter/encounter_top.php'; }
            else { alert('Error: ' + resp.message); $('#btn-save').prop('disabled', false).html('<i class="fa fa-save"></i> Save Exam'); }
        }).fail(function() { alert('Server error'); $('#btn-save').prop('disabled', false).html('<i class="fa fa-save"></i> Save Exam'); });
    });
});
