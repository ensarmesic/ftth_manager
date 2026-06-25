# Izvještaji i eksport

---

## Pregled izvještaja (`/izvjestaji`)

Centralna stranica s pregledom svih projekata i sljedećim agregatima po projektu:

- Broj ODF-a, ODO-a, kuća, trasa
- Validacijska upozorenja
- Materijalni obračun (dužine kablova i mikrocijevi po tipu)
- Procijenjeni troškovi materijala (ako su unijete cijene)

**Ukupni agregati** (svi projekti zajedno):
- Ukupno projekata, kuća, ODO-a
- Slobodni portovi (ukupan slobodan kapacitet svih ODO-a)
- Ukupna dužina rova i vlakna
- Ukupna vrijednost materijala

---

## Fiber šema (`/fiber-sema`)

Vizualni topološki dijagram cijele mreže — prikazuje logičku strukturu od ODF-a do svakog pretplatnika.

Organizacija: **Projekt → ODF → primarni krakovi → sekundarni krakovi → ODO ormarići → kuće**

Prikaz po elementima:
- **ODF kutija** — lijevo, početak dijagrama
- **Primarni krakovi** — horizontalne grane od ODF-a
- **Sekundarni krakovi** — grane od primarnih
- **ODO ormarići** — s brojem splittera, portova i zauzetošću
- **Kuće** — krajnji pretplatnici s oznakama

Svaki ODO prikazuje dodjelu vlakana (npr. "Vlakno 1–2" za 2 splittera). Popunjenost projekta prikazana je u postocima.

Redosljed primarnih i sekundarnih krakova u dijagramu kontroliše se drag-and-drop sortiranjem na stranici `/krakovi`.

### Eksport fiber šeme

- **PDF** — `GET /projekti/{id}/fiber-sema/pdf` — A4 landscape format, generisan kroz Laravel DomPDF. Filename: `fiber-shema-{code}-{datum}.pdf`
- **DXF** — `GET /projekti/{id}/fiber-schema-dxf` — tehnički crtež topologije za AutoCAD; sadrži slojeve s kutijama, bus linijama, tap tačkama i oznakama

---

## Prilog 3 (`/izvjestaji/projekti/{id}/prilog-3`)

Standardni projektni izvještaj prema FI 130 normativu. Sadrži:

### Segment 1 — Oprema
| Stavka | Izvor |
|---|---|
| ODF ormari | Broj ODF-a iz projekta |
| FTTH ORMARI | Broj ODO-a iz projekta |
| SPLITERI 1/4 | Ukupan `splitter_count` svih ODO-a |
| PROLAZNI SAHTOVI | Stavke tipa `manhole` s mape |
| BUŠENJE FI 130 | Ukupna dužina stavki tipa `boring_fi_130` s mape |

### Segment 2 — Materijali (rekapitulacija)
| Stavka | Izvor |
|---|---|
| GLAVNI ROV | `duct_length_m` svih `trench` trasa |
| MIKRODUKT 4 phi 10 | `duct_length_m × microduct_count` za `10/8` trase |
| MIKRO CIJEV phi 14/10 | `duct_length_m × microduct_count` za `14/10` trase |
| OPTIKA 48/24/12/6/4 niti | `fiber_length_m` grupisano po `fiber_count` |
| PVC CIJEV fi 110 | Jednako bušenju FI 130 |
| KORISNIČKE INSTALACIJE | `broj_kuća × 50 m` (standardna pretpostavka) |
| KORISNIČKA OPTIKA | korisničke instalacije × 1.1 |
| KORISNIČKI ROV | korisničke instalacije / 2 |

### Stavke Prilog 3 s mape

Na stranici `/izvjestaji` (ili `/projekti/{id}/prilog-3`) mogu se dodati ručne stavke:
- **Prolazni šaht** — komadi
- **Bušenje FI 130** — dužina u metrima

Stavke se mogu postaviti i direktno na mapi (alati Saht i FI 130).

---

## Pregled splittera (`/splitteri`)

Tabela svih ODO-a u svim projektima:
- Naziv ODO-a, projekt, ODF
- Broj splittera, portova, iskorištenost
- Lista kuća

Korisno za brzu provjeru slobodnih portova i rasporeda splittera.

---

## Materijalni obračun

Dostupan na stranici pregleda projekta (`/projekti/{id}/pregled`) i kroz API (`GET /projekti/{id}/validacija`).

Sadrži:
- Dužine kablova po broju vlakana (4/12/24/48)
- Dužine mikrocijevi po tipu (14/10, 10/8)
- Dužine s rezervom (default 10%)
- Procijenjenu vrijednost materijala iz tabele `materials`

---

## Eksport GeoJSON

`GET /projekti/{id}/geojson`

GeoJSON FeatureCollection s:
- Point features za ODF, ODO, kuće
- LineString features za trase

Svaki feature ima `properties` s punim skupom atributa elementa.

---

## Eksport DXF

`POST /projekti/{id}/dxf`

Generišse DXF crtež mreže s:
- Slojevima po tipu elementa (trench, backbone, feeder, distribution, drop)
- Tekstualnim oznakama (nazivi ODF-a, ODO-a)
- Opcionalnom DXF podlogom (ako korisnik dostavi podlogu)

Koordinate su automatski konvertovane iz WGS84 u **MGI Gauss-Krüger** (Besselov elipsoid, zona 5/6/7 — automatska detekcija prema longitudi projekta). Ovo je standardni koordinatni sistem koji koriste CAD programi u BiH i okruženju, pa se DXF može direktno otvoriti u AutoCAD-u bez dodatne konverzije projekcije.

---

## DXF fiber shema

`GET /projekti/{id}/fiber-schema-dxf`

DXF verzija fiber sheme — dijagram vlakana u DXF formatu.

---

## Print prikaz

`GET /projekti/{id}/print`

Formatiran HTML pregled projekta s:
- Podacima projekta
- Statistikama mreže
- Listom elemenata
- Materijalnim obračunom

Prilagođen za štampu (`@media print` CSS).
