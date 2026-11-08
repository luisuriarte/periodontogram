# Rediseño Periodontograma estilo perio-tools.com

## Objetivo
Reemplazar la grilla tabular actual (inputs diminutos, SVG con triángulos) por un chart visual tipo https://www.periodontalchart-online.com (Universidad de Berna - Ramseier 2024) integrado como form OpenEMR.

## Alcance acordado
- ✅ Réplica visual completa (no solo tabla mejorada)
- ✅ 6 sitios por diente con gráfico
- ✅ Estado dental: presente / ausente / implante
- ✅ Movilidad 0-3 (Miller) y furcación 0-3 (Hamp)
- ✅ BOP + placa (PI) + supuración por sitio
- ✅ Estadística global: Avg PD, Avg AL, %BOP, %PI
- ✅ Migración a nuevo esquema (no mantener limitación V/P+3pts)

## Diferencias clave vs. sitio referencia

| Feature | perio-tools | Este rediseño v2 |
|---|---|---|
| 6 sitios | MB, B, DB, ML, L, DL | Igual (site 1..6) |
| PD / GM / AL | AL = PD - GM (GM con signo: + hiperplasia, - recesión) | Igual. Se corrige bug actual (antes PD+GM con recession unsigned) |
| Diente ausente | X gris | Clic en número de diente cicla estado |
| Implante | icono | Igual, distinto color + no calcula CAL como diente |
| Movilidad | por diente | Selector 0/I/II/III en cabecera del diente |
| Furcación | por raíz (vestibular/palatal/lingual) | 3 selects compactos (solo molares + 14,24) |
| BOP/PI | dot por sitio | checkbox dot + color rojo/amarillo |
| FDI/Universal | toggle | Toggle incluido |
| Voice / QR / Export | sí | fuera de alcance inicial (requiere WebSpeech API + servidor) |
| Historia | comparar exámenes | botón "Copiar de examen anterior" |

## Mapeo de datos legacy

Esquema actual: `surface V/P × point 1,2,3` = 6 sitios, pero sin distinguir MB vs ML correctamente y sin estado dental.

Mapeo a nuevo:

```
V + 1 (mesial vestibular) -> site 1 = MB
V + 2 (medio vestibular) -> site 2 = B
V + 3 (distal vestibular) -> site 3 = DB
P + 1 (mesial palatino/lingual) -> site 6 = ML  (ojo: invertido por cuadrante)
P + 2 -> site 5 = L
P + 3 -> site 4 = DL
```

> Para cuadrantes 1 y 3 el orden mesial/distal depende del lado. El script `migrate_legacy_to_v2.php` normaliza a MB/B/DB/ML/L/DL visto desde oclusal (como perio-tools).

El campo `recession` legacy (unsigned, 0..12) se migra a `gingival_margin` con signo: `GM = -recession` si se interpretaba como recesión. Para no perder datos: migration flag `gm_sign_convention='legacy_recession_negative'`.

## Nuevo esquema (table_v2.sql)

### form_periodontogram (header - ALTER)
+ `avg_pd DECIMAL(5,2)`, `avg_al DECIMAL(5,2)`, `pi_percent DECIMAL(5,2)`, `bop_sites`/`bop_total` ya existen, + `teeth_present TINYINT`, + `teeth_implant TINYINT`, + `chart_version TINYINT DEFAULT 2`

### form_periodontogram_detail (nueva tabla)
Una fila por sitio (hasta 6 por diente) + columnas de diente repetidas (denormalizado para query simplificada) o mejor: tabla separada `form_periodontogram_tooth`:

**form_periodontogram_tooth** (una fila por diente):
- id, periodontogram_id, pid, encounter, tooth_fdi
- status ENUM('present','missing','implant') DEFAULT 'present'
- mobility TINYINT 0-3
- furcation_buccal, furcation_lingual, furcation_palatal TINYINT 0-3
- notes VARCHAR(255)

**form_periodontogram_site** (una fila por sitio):
- id, periodontogram_id, tooth_id (FK), pid, encounter
- site TINYINT 1..6 (1=MB,2=B,3=DB,4=DL,5=L,6=ML)  — orden oclusal como perio-tools
- probing_depth TINYINT NULL
- gingival_margin SMALLINT NULL (-12..+12)
- attachment_level SMALLINT GENERATED ALWAYS AS (probing_depth - gingival_margin) STORED — o calculado en PHP para compat. MySQL 5.7+
- bleeding TINYINT(1), plaque TINYINT(1), suppuration TINYINT(1)

> Se mantiene `form_periodontogram_detail` legacy como respaldo. El nuevo `save.php` detecta `chart_version` y escribe en ambas tablas durante transición (dual-write) hasta validar.

## UI propuesta (new.php v2)

```
┌─ Header OpenEMR: Paciente, Encounter, Diagnóstico, Grado, Notas, BOP%/PI% ─┐
├─ Toolbar: [FDI|Universal] [Mostrar: PD/GM/BOP/Placa] [Copiar anterior] [Guardar]┤
├─ Upper Arch (16 dientes) ───────────────────────────────────────────┐
│ [18][17][16]...[11] | [21]...[28]                                    │
│ cada diente: card SVG con 6 números, líneas PD(roja)/GM(azul)        │
├─ Lower Arch (16 dientes) ───────────────────────────────────────────┤
└─ Leyenda: 1-3 verde, 4-5 amarillo, ≥6 rojo, ● BOP, ◆ PI ────────────┘
```

Detalle por diente (SVG 64×54):
- Fondo blanco / gris si ausente / celeste si implante
- Línea CEJ horizontal negra
- Polilínea azul GM (3 pts vestibular), polilínea roja PD
- Números clicables → popover inline con PD, GM, toggles BOP/PI/SUP
- Flecha para navegar con teclado (Tab en orden de sondaje: 16→26 vestibular, luego palatal)

Se eliminan los 4 SVG gigantes actuales (900×180 triángulos) y se reemplazan por 32 mini-charts (perf + legibilidad).

## JS (js/periodontalChart.js v2)
Clase `PerioChartV2`:
- `state: Map<tooth, {status,mobility,furc:{b,l,p}, sites:Map<site,{pd,gm,bop,plaque,sup} >}>`
- `renderAll()` → DOM efficient, no jQuery grid rebuild each keystroke
- `updateLine(tooth)` → recalcula SVG path
- `stats()` → Avg PD, Avg CAL, BOP%, PI%
- `serialize()` → payload para save.php (v2)
- `load(payload)` → carga examen existente / copiar anterior
- `cycleStatus(tooth)` → presente→ausente→implante

Input: number inputs con `inputmode="numeric"`, validación 0-12, GM -12..12, navegación con flechas/abajo.

## Archivos a crear/modificar
1. `table_v2.sql` — migración (idempotente)
2. `new.php` — reescrito (mantener `?v2=1` fallback)
3. `js/periodontalChart.js` — motor v2 (javascript vanilla + jQuery solo para ajax)
4. `save.php` — acepta payload v2, dual-write legacy si `legacy_map=true`
5. `report.php` — renderiza v2 (imprimible)
6. `migrate_legacy_to_v2.php` — conversor oneshot

## Plan de pruebas
- [ ] Crear examen nuevo, ingresar PD/GM/BOP/PI en 2 dientes, guardar, recargar → datos intactos
- [ ] Marcar diente ausente → se excluye de estadística, no guarda sitios
- [ ] Marcar implante → línea distinta, no calcula furcación
- [ ] Comparar Avg PD/AL con cálculo manual
- [ ] Imprimir report.php → SVG vectorial nítido
- [ ] Migrar examen legacy (script) → verificar CAL = PD - GM coincide

## Fuera de alcance (fase 2)
- Dictado por voz (WebSpeech), QR, import/export JSON, microbiología, análisis de riesgo PRA, comparación histórica gráfica, FDI/Universal automático por locale.
