# Matrica uloga i dozvola

| Operacija | Administrator | Projektant | Teren | Pregled |
|---|:---:|:---:|:---:|:---:|
| Pregled projekata, mape, izvještaja i dokumentacije | ✓ | ✓ | ✓ | ✓ |
| Izmjena projekta, mreže, vlakana i materijala | ✓ | ✓ | — | — |
| DXF/CSV/PDF/GeoJSON izvoz i štampa | ✓ | ✓ | — | — |
| Snimanje terenskih tačaka i fotografija | ✓ | — | ✓ | — |
| Snapshot i JSON backup projekta | ✓ | ✓ | — | — |
| Vraćanje snapshota ili JSON backupa | ✓ | — | — | — |
| Brisanje projekata, mrežnih elemenata i importovanih slojeva | ✓ | — | — | — |
| Sistemske postavke, GIS import i health podaci | ✓ | — | — | — |

## Tehničke dozvole

- `project.view`: čitanje projektnih i izvještajnih podataka.
- `project.edit`: projektantske izmjene mreže i optičke šeme.
- `project.export`: projektni PDF, CSV, DXF, GeoJSON i štampa.
- `project.backup`: kreiranje snapshota i JSON backupa.
- `field.capture`: unos terenskih tačaka i fotografija.
- `settings.manage`: aplikacijske i GIS postavke.
- `system.manage`: health i ostale sistemske operacije.
- `destructive`: brisanje i vraćanje podataka.

Postojeći korisnici se migracijom postavljaju na administratora kako nadogradnja ne bi nenamjerno ukinula pristup. Novi korisnik bez izričito odabrane uloge dobija ulogu `viewer`.
