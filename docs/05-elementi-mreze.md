# Elementi mreže

---

## ODF (Optički distribucionih frame)

**URL:** `/odf`

Centralna tačka mreže s koje kreće distribucija vlakana.

| Polje | Opis |
|---|---|
| Naziv | Npr. "ODF-01" |
| Adresa | Lokacija ODF-a |
| Kapacitet vlakana (`fiber_capacity`) | Ukupan broj vlakana na ODF-u (default 144) |
| Portovi (`port_count`) | Broj optičkih portova (default 48) |
| Koordinate | Lat/lng, postavljaju se na mapi |

**Kapacitet u upotrebi** = zbir `splitter_count` svih ODO-a koji su direktno vezani na ovaj ODF (bez `parent_cabinet_id`).

Upozorenje se generiše ako je iskorištenost > 85%, greška ako je > 100%.

---

## ODO / FTTH ormarić

**URL:** `/ormarici`

Splitter ormarić u polju koji distribuira signal do kuća.

| Polje | Opis |
|---|---|
| Naziv | Npr. "FTTH 1-1-1" |
| Adresa | Lokacija ormarića |
| ODF (`odf_id`) | Nadređeni ODF (nullable ako je pod drugim ODO-om) |
| Roditeljski ODO (`parent_cabinet_id`) | Nullable — za kaskadne ODO konfiguracije |
| Krak (`branch_id`) | Sekundarni krak kojemu pripada |
| Broj splittera (`splitter_count`) | 1–3 |
| Portova po splitteru (`ports_per_splitter`) | Uvijek 4 |
| **Kapacitet** | Computed: `splitter_count × 4` (max 12) |
| Koordinate | Lat/lng |

### Boja na mapi

Svaki ODO dobija boju prema svom ID-u iz fiksne palete od 12 boja — tako se vizualno razlikuju ODO-i jednog od drugog.

Boja prstena označava popunjenost:
- Zelena: < 60%
- Žuta: 60–80%
- Narančasta: 80–95%
- Crvena: 95–100%

### Povezi kuće na ODO

Dugme "Povezi kuće" na ODO kartici/property panelu otvara selekciju kuća. Selektovane kuće dobijaju `cabinet_id` tog ODO-a.

---

## Kuće / priključci

**URL:** `/kuce`

Krajnje tačke mreže — objekti koji se priključuju na optičku mrežu.

| Polje | Opis |
|---|---|
| Oznaka (`label`) | Npr. "K-001" |
| Adresa | Fizička adresa objekta |
| ODO (`cabinet_id`) | Ormarić kojemu je priključena |
| Krak (`branch_id`) | Sekundarni krak (auto-dodjeljuje se pri crtanju trase) |
| Status | `planned` |
| Koordinate | Lat/lng |

---

## Trase

**URL:** `/trase`

Linijski elementi mreže — fizički putevi kabela.

| Polje | Opis |
|---|---|
| Naziv | Auto-generisan (npr. "Sekundarni krak 1") |
| Tip (`route_type`) | `trench`, `backbone`, `feeder`, `distribution`, `drop` |
| Polazna tačka (`from_type`, `from_id`) | `odf` ili `cabinet` |
| Odredišna tačka (`to_type`, `to_id`) | `cabinet` ili `house` |
| Tip instalacije | `underground` (podzemno) ili `aerial` (vazdušno) |
| Trench grupa (`trench_group`) | Za grupiranje rovova u obračunu |
| Broji kao rov (`counts_as_trench`) | Boolean — za override trench obračuna |
| Dužina rova (`duct_length_m`) | Dužina iskopa u metrima |
| Dužina vlakna (`fiber_length_m`) | Dužina optičkog kabla (= dužina rova za kabelske trase) |
| Broj vlakana (`fiber_count`) | 4, 12, 24 ili 48 |
| Broj mikrocijevi (`microduct_count`) | Broj mikrocijevi u kanalizaciji |
| Tip mikrocijevi (`microduct_type`) | `14/10` ili `10/8` |
| Status | `planned`, `in_progress`, `built` |
| Geometrija (`path`) | JSON array `[[lat, lng], ...]` |
| Napomena | Slobodan tekst |

### Dužine

Dužine se uvijek računaju automatski iz geometrije — ručni unos dužine moguć je samo kad nema nacrtane geometrije.

Za `trench` tip: `fiber_length_m = 0`, `microduct_count = 0`, `microduct_type = null`.

### Split trase

Trasa se može podijeliti na dvije na bilo kojoj tački. Nova tačka se dodaje na geometriju, dužine se dijele proporcionalno.

### Join trasa

Dvije trase koje dijele krajnju tačku mogu se spojiti u jednu. Geometrija se spaja, dužine sabiraju. Tehnički podaci (mikrocijev, vlakna) preuzimaju se iz prve odabrane trase.

---

## Krakovi (Network branches)

**URL:** `/krakovi`

Logička organizacija mreže u primarne i sekundarne grane.

| Polje | Opis |
|---|---|
| Naziv | Npr. "Sekundarni krak 1-1" |
| Šifra (`code`) | Npr. "1.1" |
| ODF (`odf_id`) | ODF kojemu krak pripada |
| Roditeljski krak (`parent_branch_id`) | Za hijerarhijsku strukturu |
| Trasa (`route_id`) | Trasa kojoj je krak vezan (1:1) |
| Tip | `primary` (backbone/feeder) ili `secondary` (distribution) |
| Redoslijed (`sort_order`) | Za drag-and-drop sortiranje |

### Automatsko kreiranje krakova

Svaka novokreirana trasa tipa `backbone`, `feeder` ili `distribution` **automatski** dobija krak. Naziv i šifra kraka nasljeđuju se iz naziva trase.

Brisanje trase briše i njen krak, a ODO-i koji su bili na tom kraku se odvezuju.

### Drag-and-drop sortiranje

Na stranici `/krakovi` krakovi se mogu sortirati drag-and-dropom. Redoslijed utiče na prikaz u fiber shemi.

### Ciklus provjera

Ako bi novi roditeljski krak napravio kružnu hijerarhiju (A → B → A), server vraća grešku 422.
