-- =============================================================================
-- lang_custom.sql -- Periodontogram V2 (form_periodontics)
-- Language: Spanish (Latin American) - manual optional import
-- Safe to run any number of times, on a fresh install or on an upgrade.
-- RULE: an existing non-empty translation is NEVER overwritten.
-- Run it as ONE script in ONE session (user variables and a temporary table are used).
-- =============================================================================
START TRANSACTION;

SET @lang_desc := 'Spanish (Latin American)';
SET @lang_id   := (SELECT lang_id   FROM lang_languages WHERE lang_description = @lang_desc LIMIT 1);
SET @lang_code := (SELECT lang_code FROM lang_languages WHERE lang_description = @lang_desc LIMIT 1);

SELECT IF(@lang_id IS NULL,
          'ERROR: language not found, nothing will be written',
          CONCAT('Language found: ', @lang_code)) AS check_language;

DROP TEMPORARY TABLE IF EXISTS tmp_perio_lang;
CREATE TEMPORARY TABLE tmp_perio_lang (
  constant_name VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  definition    TEXT         CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (constant_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

INSERT INTO tmp_perio_lang (constant_name, definition) VALUES
('Periodontal Chart', 'Periodontograma'),
('Periodontal Chart Report', 'Informe de Periodontograma'),
('Periodontal Examination', 'Examen Periodontal'),
('Periodontal exam saved successfully', 'Examen periodontal guardado correctamente'),
('Error saving exam', 'Error al guardar el examen'),
('No encounter or patient specified', 'Sin paciente o consulta activa'),
('No data provided', 'Sin datos'),
('Error: Invalid patient, encounter, or form ID.', 'Error: Paciente, consulta o ID de formulario inválido'),
('Exam not found.', 'Examen no encontrado'),
('-- Select --', '-- Seleccionar --'),
('Diagnosis', 'Diagnóstico'),
('Grade', 'Grado'),
('Clinical Notes', 'Notas clínicas'),
('Clinical notes', 'Notas clínicas'),
('Notes', 'Notas'),
('Date', 'Fecha'),
('Tooth', 'Diente'),
('Tooth status', 'Estado del diente'),
('Status', 'Estado'),
('Present', 'Presente'),
('Missing', 'Ausente'),
('Implant', 'Implante'),
('Mobility', 'Movilidad'),
('Furcation', 'Furcación'),
('Buccal', 'Vestibular'),
('Lingual', 'Lingual'),
('Palatal', 'Palatino'),
('Probing Depth', 'Profundidad de sondaje'),
('Gingival Margin', 'Margen gingival'),
('Attachment Level', 'Nivel de inserción'),
('Average Probing Depth', 'Profundidad de sondaje promedio'),
('Average Attachment Level', 'Nivel de inserción promedio'),
('Avg PD', 'Prof. sondaje prom.'),
('Avg AL', 'NIC prom.'),
('BOP', 'BOP'),
('PI', 'PI'),
('Bleeding', 'Sangrado'),
('Bleeding on Probing', 'Sangrado al sondaje'),
('Plaque', 'Placa'),
('Suppuration', 'Supuración'),
('Healthy (1-3 mm)', 'Sano (1-3 mm)'),
('Warning (4-5 mm)', 'Alerta (4-5 mm)'),
('Danger (6 mm or more)', 'Peligro (6 mm o más)'),
('Save Exam', 'Guardar examen'),
('Saving...', 'Guardando...'),
('Cancel', 'Cancelar'),
('Close', 'Cerrar'),
('Print', 'Imprimir'),
('Print Report', 'Imprimir informe'),
('Tooth Numbering', 'Numeración dentaria'),
('Click tooth number to cycle Present / Missing / Implant. AL = PD - GM.', 'Haga clic en el número de diente para ciclar Presente / Ausente / Implante. NIC = PS - MG.'),
('Sites', 'Sitios'),
('Sites: MB · B · DB / DL · L · ML. Attachment Level (CAL) = Probing Depth (PD) - Gingival Margin (GM).', 'Sitios: MB · B · DB / DL · L · ML. Nivel de inserción (NIC) = Profundidad de sondaje (PS) - Margen gingival (MG).'),
('Upper Arch', 'Arcada superior'),
('Lower Arch', 'Arcada inferior'),
('None', 'Ninguno'),
('FDI', 'FDI'),
('Universal', 'Universal'),
('Palmer', 'Palmer'),
('Haderup', 'Haderup'),
('Periodontal Health', 'Salud periodontal'),
('Gingivitis', 'Gingivitis'),
('Periodontitis Stage I', 'Periodontitis estadio I'),
('Periodontitis Stage II', 'Periodontitis estadio II'),
('Periodontitis Stage III', 'Periodontitis estadio III'),
('Periodontitis Stage IV', 'Periodontitis estadio IV'),
('Aggressive Periodontitis', 'Periodontitis agresiva'),
('No Diagnosis', 'Sin diagnóstico'),
('Grade A - Slow progression', 'Grado A - Progresión lenta'),
('Grade B - Moderate progression', 'Grado B - Progresión moderada'),
('Grade C - Rapid progression', 'Grado C - Progresión rápida'),
('N/A', 'N/A'),
('Grade 0 - No mobility', 'Grado 0 - Sin movilidad'),
('Grade I - < 1mm horizontal', 'Grado I - < 1 mm horizontal'),
('Grade II - > 1mm horizontal', 'Grado II - > 1 mm horizontal'),
('Grade III - Vertical mobility', 'Grado III - Movilidad vertical'),
('Grade I', 'Grado I'),
('Grade II', 'Grado II'),
('Grade III', 'Grado III'),
('Patient', 'Paciente'),
('Encounter', 'Consulta');

-- A. Add the source constants that do not exist yet
INSERT INTO lang_constants (constant_name)
SELECT t.constant_name
FROM tmp_perio_lang t
WHERE @lang_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM lang_constants c WHERE c.constant_name = t.constant_name
  );

-- B. Fill definitions that exist but are EMPTY (a non-empty one is never touched)
UPDATE lang_definitions d
INNER JOIN lang_constants c ON c.cons_id = d.cons_id
INNER JOIN tmp_perio_lang t  ON c.constant_name = t.constant_name
SET d.definition = t.definition
WHERE d.lang_id = @lang_id
  AND IFNULL(d.definition, '') = '';

-- C. Insert definitions for constants that have NO row for this language
INSERT INTO lang_definitions (cons_id, lang_id, definition)
SELECT c.cons_id, @lang_id, t.definition
FROM tmp_perio_lang t
INNER JOIN lang_constants c ON c.constant_name = t.constant_name
WHERE @lang_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM lang_definitions d
    WHERE d.cons_id = c.cons_id AND d.lang_id = @lang_id
  );

-- D. Record in lang_custom ONLY what ended up with OUR definition
INSERT INTO lang_custom (lang_description, lang_code, constant_name, definition)
SELECT @lang_desc, @lang_code, t.constant_name, t.definition
FROM tmp_perio_lang t
INNER JOIN lang_constants c   ON c.constant_name = t.constant_name
INNER JOIN lang_definitions d ON d.cons_id = c.cons_id AND d.lang_id = @lang_id
                              AND d.definition = t.definition COLLATE utf8mb4_bin
WHERE @lang_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM lang_custom lc
    WHERE lc.lang_code = @lang_code
      AND lc.constant_name = t.constant_name COLLATE utf8mb4_bin
  );

-- E. Report
SELECT COUNT(*) AS strings_in_file FROM tmp_perio_lang;

SELECT t.constant_name, d.definition AS kept_definition, t.definition AS our_definition
FROM tmp_perio_lang t
INNER JOIN lang_constants c   ON c.constant_name = t.constant_name
INNER JOIN lang_definitions d ON d.cons_id = c.cons_id AND d.lang_id = @lang_id
WHERE IFNULL(d.definition, '') <> ''
  AND d.definition <> t.definition COLLATE utf8mb4_bin
ORDER BY t.constant_name;

COMMIT;
DROP TEMPORARY TABLE IF EXISTS tmp_perio_lang;
