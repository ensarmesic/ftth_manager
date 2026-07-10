# FTTH Manager

Web aplikacija za planiranje, vizualizaciju i dokumentaciju pasivnih optičkih FTTH mreža. Namijenjena projektantima i izvođačima koji rade na izgradnji optičke infrastrukture — od ODF tačke do zadnjeg priključka.

---

## Šta aplikacija radi

- **Višeprojekatni rad** — svaki projekat ima svoju izoliranu mapu, nacrt i podatke
- **Interaktivni map editor** — crtanje trasa, postavljanje ODF-a, ormarića i kuća direktno na satelitskoj mapi
- **Automatski raspored ODO ormarića** — algoritam grupiše kuće po krakovima i predlaže optimalan raspored
- **Krakovi i grane** — organizacija mreže u primarne i sekundarne krakove s drag-and-drop sortiranjem
- **DXF podloge** — uvoz katastarske ili projektne podloge kao pozadinski sloj na mapi, s automatskom transformacijom koordinata (MGI Gauss-Krüger → WGS84). DWG nije podržan — sačuvaj ga kao DXF prije uvoza.
- **Izvještaji** — fiber shema, prilog 3, pregled splittera, obračun materijala
- **Eksport** — GeoJSON, DXF crteži, PDF, print prikaz
- **Provjera projekta** — automatska detekcija neuređenih drop trasa, nepokrivanog kapaciteta, grešaka u vezama

---

## Funkcionalnosti

### Mapa i planiranje

- Satelitska osnova (ESRI/Leaflet)
- Crtanje klikanjem tačaka na mapi — Glavni rov, Primarni, Sekundarni, Drop
- Snap na postojeće elemente (ODF, ormarić, krajnja tačka trase)
- ORTHO mod za horizontalne/vertikalne linije (taster `O`)
- Ruler (mjerni alat) za brzo mjerenje udaljenosti
- Auto-save nacrta svakih 700 ms, restore pri sljedećem otvaranju projekta

### Elementi mreže

| Element            | Opis                                                       |
| ------------------ | ---------------------------------------------------------- |
| ODF                | Optički distribucionih frame — kapacitet vlakana i portova |
| ODO / FTTH ormarić | Splitter ormarić — broj splittera i portova po splitteru   |
| Kuća / priključak  | Krajnja tačka — vezana za ormarić i krak                   |
| Trasa              | Geometrija kabela — tip, dužina, mikrocijev, broj vlakana  |
| Krak               | Grana mreže — grupiše trase i kuće                         |

### Tipovi trase

- **Glavni rov** — fizički iskop, bez mikrocijevi, prikazuje se ispod ostalih slojeva
- **Backbone** — okosnica mreže
- **Primarni krak** — ODF → ormarić
- **Sekundarni krak** — ormarić → ormarić
- **Drop trasa** — ormarić → kuća (mikrocijev 10/8, 4 niti)

### DXF podloge (po projektu)

- Uvoz `.dxf` fajlova kao mapu pozadinu (DWG nije podržan — sačuvaj kao DXF)
- Automatska detekcija projekcije: MGI zona 6, MGI zona 7 ili WGS84
- Canvas rendering — nema usporavanja ni za velike fajlove
- Svaka podloga je vezana za projekat i ne prelazi u druge projekte
- Čuvanje u IndexedDB (preživi reload, bez servera)

### Izvještaji i eksport

| Izvještaj          | Opis                                          |
| ------------------ | --------------------------------------------- |
| Fiber shema        | Vizualni raspored vlakana po splitterima, PDF |
| Prilog 3           | Popis sahtova i bušenja (FI 130)              |
| Pregled splittera  | Iskorištenost portova po ormarićima           |
| Obračun materijala | Količine kabela, mikroducta, rovova           |
| GeoJSON            | Puna geometrija mreže                         |
| DXF eksport        | Plan mreže kao DXF crtež (s podlogama)        |
| Print prikaz       | Formatiran izvještaj za štampu                |

---

## Tehnologije

| Sloj                       | Tehnologija                      |
| -------------------------- | -------------------------------- |
| Backend                    | PHP 8.3+ · Laravel 13            |
| Frontend                   | Tailwind CSS v4 · Vite 8         |
| Mapa                       | Leaflet.js 1.9                   |
| Koordinatna transformacija | proj4.js                         |
| Baza podataka              | SQLite (bez potrebe za serverom) |
| PDF generisanje            | barryvdh/laravel-dompdf          |

---

## Sistemski zahtjevi

### Minimalno

| Komponenta     | Zahtjev                                                    |
| -------------- | ---------------------------------------------------------- |
| PHP            | 8.3 ili noviji                                             |
| PHP ekstenzije | `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, `fileinfo` |
| Node.js        | 18 ili noviji _(samo za build)_                            |
| Disk           | 200 MB (aplikacija + zavisnosti)                           |
| RAM            | 512 MB                                                     |

### Preporučeno

| Komponenta         | Preporuka                              |
| ------------------ | --------------------------------------- |
| RAM                | 1 GB ili više                           |
| PHP `memory_limit` | `256M` minimalno · `1G` za veliki DXF   |
| OS                 | Windows 10/11 · Ubuntu 22.04+ · macOS 13+ |

> **Podloge:** Mapa učitava samo DXF pozadinske slojeve. DWG fajlovi nisu podržani — sačuvaj ih kao DXF (Save As → DXF) iz AutoCAD-a/FreeCAD-a prije uvoza.

---

## Instalacija

```bash
git clone https://github.com/ensarmesic/ftth_manager.git
cd ftth_manager
composer run setup
```

`setup` skripta automatski:

1. Instalira PHP zavisnosti (`composer install`)
2. Kreira `.env` iz `.env.example` i generiše app key
3. Pokreće sve database migracije (kreira SQLite bazu)
4. Instalira JS zavisnosti i builda frontend (`npm run build`)

### Pokretanje razvojnog servera

```bash
composer run dev
```

Pokreće paralelno:

- Laravel app na `http://127.0.0.1:8000`
- Vite HMR na `http://127.0.0.1:5173`

### Produkcijski build

```bash
npm run build
php artisan optimize
```

Serviraj s Apache, Nginx ili drugim web serverom — `public/` direktorij je web root.

---

## Konfiguracija

```env
APP_NAME="FTTH Manager"
APP_ENV=local        # production za produkciju
APP_DEBUG=true       # false u produkciji
APP_URL=http://localhost

DB_CONNECTION=sqlite # SQLite — nije potreban host/port
```

Za veliki DXF fajlove povećaj memory limit:

```env
PHP_MEMORY_LIMIT=1G
```

---

## Tok rada (tipični projekt)

1. Otvori mapu → odaberi ili kreiraj projekat
2. Postavi ODF na mapi _(korak "1 ODF")_
3. Nacrtaj Glavni rov i krakove _(korak "2 Trasa")_
4. Označi kuće _(korak "3 Kuće")_
5. Pokreni automatski raspored ODO ormarića _(korak "4 FTTH")_
6. Provjeri i potvrdi raspored → **Sačuvaj na mapi**
7. Otvori _Provjera projekta_ i popravi upozorenja
8. Generiši izvještaje i eksportuj DXF / PDF

---

## Tabele baze

| Tabela                   | Sadržaj                                  |
| ------------------------ | ---------------------------------------- |
| `projects`               | Projekti                                 |
| `odfs`                   | ODF tačke s koordinatama                 |
| `cabinets`               | ODO/FTTH ormarići s koordinatama         |
| `houses`                 | Kuće / priključci s koordinatama         |
| `routes`                 | Trase — tip, dužine, geometrija (`path`) |
| `network_branches`       | Krakovi i grane                          |
| `project_appendix_items` | Sahtovi i bušenja na mapi                |
| `map_drafts`             | Radna verzija plana (JSON) po projektu   |
| `materials`              | Materijali projekta                      |

---

## Licenca

© 2025 Ensar Mešić. Sva prava zadržana.

Zabranjeno kopiranje, distribucija ili korištenje koda bez pisane dozvole autora.
