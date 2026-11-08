<div align="center">

# 🦷 Periodontograma — OpenEMR

### Boca completa · 6 sitios por diente · FDI / Universal / Palmer / Haderup

[![Plataforma](https://img.shields.io/badge/OpenEMR-8.x-2ea44f?style=flat-square)](https://open-emr.org)
[![Licencia](https://img.shields.io/badge/license-GPL--3.0-4c8bf5?style=flat-square)](https://github.com/openemr/openemr/blob/master/LICENSE)
[![Chart](https://img.shields.io/badge/chart-72×96%20vertical-0d6efd?style=flat-square)](#)
[![BD](https://img.shields.io/badge/DB-MariaDB%2010.2%2B-f28b82?style=flat-square)](table.sql)
[![i18n](https://img.shields.io/badge/i18n-xl()%20inglés%20origen-ffc107?style=flat-square)](#traducciones)

Un periodontograma estilo perio-tools.com como formulario de consulta de OpenEMR.
Cada diente es una tarjeta con un **diente SVG vertical** (dibujable en Inkscape),
línea azul **MG**, línea roja **PS**, y toggles **BOP ● / Placa ◆ / Supuración ▲** por sitio.

</div>

---

## ✨ Funciones

| Función | Detalle |
|---|---|
| 🟢🟡🔴 | 6 sitios por diente (MB · B · DB / DL · L · ML) con color por PS: **1–3 mm 🟢, 4–5 mm 🟡, ≥6 mm 🔴** |
| 🔵🔴 | Polilíneas MG (azul) y PS (roja) en chart vertical 72×96, CEJ en `y=48` |
| 🦷 | Clic en el número del diente → **Presente / Ausente / Implante** (ausente no cuenta en estadísticas) |
| 📏 | **Movilidad** 0/I/II/III (Miller) + **Furcación** V/L/P 0/I/II/III (Hamp, molares y 14/24) |
| 🔴🟡🟣 | **BOP ●, Placa ◆, Supuración ▲** por sitio |
| 📊 | **PS prom., NIC prom., BOP %, PI %** en tiempo real. `NIC = PS − MG` (MG con signo: `+` hiperplasia, `−` recesión) |
| 🔢 | **FDI / Universal / Palmer / Haderup** con recambiado instantáneo (sin re-render) |
| 🖨️ | Informe imprimible (`report.php`) + escritura dual a tabla legacy para migración |

---

## 📸 Disposición

```
┌─ Barra: [FDI|Universal|Palmer|Haderup]  PS prom. · NIC prom. · BOP% · PI%  [Guardar] ┐
├─ Arcada superior:  [18][17]…[11] │ [21]…[28]   (corona ↓, raíz ↑)                  │
│   cada diente: cabecera + movilidad + furcación + SVG vertical + 6×(PS/MG)         │
├─ Arcada inferior:  [48]…[41] │ [31]…[38]       (corona ↑, raíz ↓)                  │
└─ Leyenda: 🟢 ≤3mm  🟡 4–5mm  🔴 ≥6mm  ● BOP  ◆ Placa  ▲ Supuración               ┘
```

---

## 🗄️ Modelo de datos (v2)

```
form_periodontogram              (cabecera: diagnóstico, grado, bop/ps_prom/nic_prom/pi)
  └─ form_periodontogram_tooth   (1 fila/diente: estado, movilidad, furcación_*)
       └─ form_periodontogram_site (1 fila/sitio 1..6: profundidad, margen, sangrado, placa, supuración)

form_periodontogram_detail       (legacy V/P × 3, se mantiene para migración)
```

Sitios: `1=MB, 2=B, 3=DB, 4=DL, 5=L, 6=ML` (vista oclusal, orden perio-tools).
MG es `SMALLINT -12…+12`. `nivel_inserción = profundidad − margen` (calculado en PHP).

---

## 🚀 Instalación

**Instalación nueva** (clínica nueva):
```bash
mysql -u root -p openemr < table.sql        # cabecera + diente + sitio + list_options
cp -r periodontics  interface/forms/periodontics/
```

**Actualización** (desde la versión legacy):
```bash
mysql -u root -p openemr < table_v2.sql     # ADD COLUMN IF NOT EXISTS (MariaDB)
cp -r periodontics  interface/forms/periodontics/
```

Abrir la consulta → **Agregar formulario → Periodontogram**. No requiere registro.

---

## 🖍️ Arte dental personalizado (Inkscape)

Pon tus SVG en `js/tooth-art/` — un archivo por diente, el nombre es el FDI:

| Superiores (raíz ↑, corona ↓) | Inferiores (corona ↑, raíz ↓) |
|---|---|
| `tooth-18.svg` … `tooth-11.svg` | `tooth-48.svg` … `tooth-41.svg` |
| `tooth-21.svg` … `tooth-28.svg` | `tooth-31.svg` … `tooth-38.svg` |
| `tooth-implant.svg` (cualquier implante) | |

Especificación: **72×96 px, viewBox `0 0 72 96`**, CEJ en `y=48`, número en `x=36 y=52` (lo pone el JS, no lo hornees).
Guardar como **SVG plano** (sin `inkscape:`). Puedes usar **dos `<path>`** (corona + raíz) en un mismo archivo.
Partí de `tooth-template-vertical.svg` / `tooth-implant.svg`. Si falta un archivo, se ve la guía y no se rompe nada.

---

## 🔢 Sistemas de numeración

| Sistema | Ejemplo (11 / 18 / 21 / 28 / 31 / 41) |
|---|---|
| FDI | `11` · `18` · `21` · `28` · `31` · `41` |
| Universal | `8` · `1` · `9` · `16` · `24` · `25` |
| Palmer | `┐1` · `┐8` · `┌1` · `┌8` · `└1` · `┘1` |
| Haderup (extendido a 8) | `1+` · `8+` · `+1` · `+8` · `-1` · `1-` |

En BD siempre se guarda FDI (`tooth_fdi`); solo cambia la etiqueta visible.

---

## 🌐 Traducciones

Los textos fuente están en **inglés** y se traducen con `xl()` / `xlt()` / `xla()` / `xls()`.
Pack opcional en español (Argentina Hospital):
```bash
mysql -u root -p openemr < sql/lang_custom.sql
```
Nunca sobrescribe traducciones existentes no vacías. Ajustá `@lang_desc` si tu idioma tiene otro nombre en `lang_languages`.

---

## ⚙️ Stack

- OpenEMR 8.x: `SessionWrapperFactory`, `CsrfUtils`, `PatientService::findByPid()`, `FormService::addForm()`
- Bootstrap + FontAwesome, JS vanilla, sin compilación
- Referencia: [periodontalchart-online.com](https://www.periodontalchart-online.com/) (Ramseier, Univ. de Berna, *Periodontol 2000* 2024)

## 📝 Licencia

GPL-3.0. El arte dental en `js/tooth-art/` pertenece a su autor; las plantillas son MIT.
