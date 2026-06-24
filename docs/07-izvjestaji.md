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

## Fiber shema (`/fiber-sema`)

Vizualni raspored vlakana — prikazuje koji splitter/port u ODF-u je zauzet kojim ODO-om.

Organizacija po projektu → ODF → krak → ODO:
- Svaki ODO dobija dodjelu vlakana: npr. "Vlakno 1–2" (za 2 splittera)
- Rezervisana vlakna prikazuju se kao slobodna
- Popunjenost projekta u %

### PDF fiber sheme

`GET /projekti/{id}/fiber-sema/pdf` — generišeizvještaj u PDF (A4 landscape) kroz barryvdh/laravel-dompdf.

Filename: `fiber-shema-{code}-{datum}.pdf`

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

Koordinate su u WGS84 (lat/lng) — pri uvozu u AutoCAD koristiti odgovarajuću projekciju.

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
