<div align="center">

# 🦷 Periodontal Chart — OpenEMR

### Full-mouth chart · 6 sites per tooth · FDI / Universal / Palmer / Haderup

[![Platform](https://img.shields.io/badge/OpenEMR-8.x-2ea44f?style=flat-square)](https://open-emr.org)
[![License](https://img.shields.io/badge/license-GPL--3.0-4c8bf5?style=flat-square)](https://github.com/openemr/openemr/blob/master/LICENSE)
[![Chart](https://img.shields.io/badge/chart-72×96%20vertical-0d6efd?style=flat-square)](#)
[![DB](https://img.shields.io/badge/DB-MariaDB%2010.2%2B-f28b82?style=flat-square)](table.sql)
[![i18n](https://img.shields.io/badge/i18n-xl()%20English%20source-ffc107?style=flat-square)](#translations)

A perio-tools.com style periodontogram as an OpenEMR encounter form.
Each tooth is a card with a **vertical SVG tooth** (Inkscape-drawable),
blue **GM** line, red **PD** line, and **BOP ● / Plaque ◆ / Suppuration ▲** toggles per site.

</div>

---

## ✨ Features

| Feature | Detail |
|---|---|
| 🟢🟡🔴 | 6 sites per tooth (MB · B · DB / DL · L · ML) with color by PD: **1–3 mm 🟢, 4–5 mm 🟡, ≥6 mm 🔴** |
| 🔵🔴 | GM (blue) and PD (red) polylines on a 72×96 vertical chart, CEJ at `y=48` |
| 🦷 | Click tooth number → **Present / Missing / Implant** (missing excluded from stats) |
| 📏 | **Mobility** 0/I/II/III (Miller) + **Furcation** B/L/P 0/I/II/III (Hamp, molars & 14/24) |
| 🔴🟡🟣 | Per-site **BOP ●, Plaque ◆, Suppuration ▲** |
| 📊 | **Avg PD, Avg AL, BOP %, PI %** in real time. `AL = PD − GM` (GM signed: `+` hyperplasia, `−` recession) |
| 🔢 | **FDI / Universal / Palmer / Haderup** instant relabel (no rebuild) |
| 🖨️ | Printable report (`report.php`) + dual-write to legacy table for migration |

---

## 📸 Layout

```
┌─ Toolbar: [FDI|Universal|Palmer|Haderup]  Avg PD · Avg AL · BOP% · PI%  [Save] ┐
├─ Upper Arch:  [18][17]…[11] │ [21]…[28]   (crown ↓, root ↑)                  │
│   each tooth:  header + mobility + furcation + vertical SVG + 6×(PD/GM)      │
├─ Lower Arch:  [48]…[41] │ [31]…[38]       (crown ↑, root ↓)                  │
└─ Legend: 🟢 ≤3mm  🟡 4–5mm  🔴 ≥6mm  ● BOP  ◆ Plaque  ▲ Suppuration             ┘
```

---

## 🗄️ Data model (v2)

```
form_periodontogram              (header: diagnosis, grade, bop/avg_pd/avg_al/pi)
  └─ form_periodontogram_tooth   (1 row/tooth: status, mobility, furcation_*)
       └─ form_periodontogram_site (1 row/site 1..6: probing_depth, gingival_margin, bleeding, plaque, suppuration)

form_periodontogram_detail       (legacy V/P × 3, kept for dual-write / migration)
```

Sites: `1=MB, 2=B, 3=DB, 4=DL, 5=L, 6=ML` (occlusal view, perio-tools order).
GM is `SMALLINT -12…+12`. `attachment_level = probing_depth − gingival_margin` (PHP-computed).

---

## 🚀 Install

**Fresh install** (new clinic):
```bash
mysql -u root -p openemr < table.sql        # header + tooth + site + list_options
cp -r periodontics  interface/forms/periodontics/
```

**Existing install** (upgrade from legacy):
```bash
mysql -u root -p openemr < table_v2.sql     # ADD COLUMN IF NOT EXISTS (MariaDB)
cp -r periodontics  interface/forms/periodontics/
```

Open the encounter → **Add Form → Periodontogram**. No registration needed.

---

## 🖍️ Custom tooth art (Inkscape)

Put your SVGs in `js/tooth-art/` — one file per tooth, filename is FDI:

| Upper (root ↑, crown ↓) | Lower (crown ↑, root ↓) |
|---|---|
| `tooth-18.svg` … `tooth-11.svg` | `tooth-48.svg` … `tooth-41.svg` |
| `tooth-21.svg` … `tooth-28.svg` | `tooth-31.svg` … `tooth-38.svg` |
| `tooth-implant.svg` (any implant) | |

Specs: **72×96 px, viewBox `0 0 72 96`**, CEJ at `y=48`, FDI number at `x=36 y=52` (rendered by JS, don't bake it).
Save as **SVG plain** (no `inkscape:`). You may use **two `<path>`** (crown + root) in one file.
Start from `tooth-template-vertical.svg` / `tooth-implant.svg`. Missing files are ignored (guide rect shows).

---

## 🔢 Numbering systems

| System | Example (11 / 18 / 21 / 28 / 31 / 41) |
|---|---|
| FDI | `11` · `18` · `21` · `28` · `31` · `41` |
| Universal | `8` · `1` · `9` · `16` · `24` · `25` |
| Palmer | `┐1` · `┐8` · `┌1` · `┌8` · `└1` · `┘1` |
| Haderup (extended 8) | `1+` · `8+` · `+1` · `+8` · `-1` · `1-` |

Stored always as FDI (`tooth_fdi`); only the label changes.

---

## 🌐 Translations

Source strings are **English** via `xl()` / `xlt()` / `xla()` / `xls()`.
Optional Spanish (Argentina Hospital) pack:
```bash
mysql -u root -p openemr < sql/lang_custom.sql
```
It never overwrites existing non-empty translations. Adjust `@lang_desc` if your language name differs.

---

## ⚙️ Stack

- OpenEMR 8.x: `SessionWrapperFactory`, `CsrfUtils`, `PatientService::findByPid()`, `FormService::addForm()`
- Bootstrap + FontAwesome, vanilla JS, no build step
- Reference: [periodontalchart-online.com](https://www.periodontalchart-online.com/) (Ramseier, Univ. of Bern, *Periodontol 2000* 2024)

## 📝 License

GPL-3.0. Tooth art in `js/tooth-art/` belongs to its author; templates are MIT.
