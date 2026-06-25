# Uvod

FTTH Manager je web aplikacija za planiranje, vizualizaciju i dokumentaciju pasivnih optičkih FTTH mreža. Namijenjena je projektantima koji rade na izgradnji optičke infrastrukture — od ODF tačke do zadnjeg priključka.

Aplikacija radi lokalno (bez interneta), baza podataka je SQLite fajl, nema prijave korisnika.

---

## Tipičan tok rada na projektu

### Korak 1 — Kreiraj projekat

Idi na `/projekti` → **Novi projekat**. Unesite naziv, šifru projekta i lokaciju. Šifra se koristi u imenima eksportovanih fajlova i fiber šemi.

Više detalja: [03-projekti.md](03-projekti.md)

---

### Korak 2 — Postavi ODF na mapi

Otvori mapu (`/mapa`), odaberi projekt iz padajućeg menija, pa klikni alat **"1 ODF"** i postavi ODF marker na fizičku lokaciju razvodnog ormara. Unesi kapacitet vlakana i portova.

---

### Korak 3 — Učitaj DXF podlogu (opciono)

Ako imaš DXF/DWG situacioni plan terena, učitaj ga kao pozadinsku podlogu alat om **"Učitaj DXF"** u toolbaru. Podloga se čuva lokalno u browseru (IndexedDB) — preživi reload, ne šalje se na server.

Više detalja: [04-mapa.md](04-mapa.md#dxf-podloga)

---

### Korak 4 — Nacrtaj rovove i trase

Alat **"2 Trasa"**:
1. Kliknite startnu tačku trase (ODF ili kraj prethodne trase)
2. Nastavite klikati tačku po tačku
3. Dvostruki klik ili Enter završava crtanje
4. U modalu koji se otvori odaberite tip trase, broj vlakana, tip instalacije i mikrocijevi

Preporučeni redosljed crtanja:
- Prvo **rovove** (`trench`) — fizički iskopi
- Zatim **backbone/feeder** trase — od ODF-a prema terenu
- Zatim **distribution** trase — sekundarni krakovi

Koristite `O` za ORTHO mod (horizontalno/vertikalno crtanje) i `R` za OSM routing (trase prate ulice).

---

### Korak 5 — Dodaj kuće

Alat **"3 Kuće"** — kliknite na lokaciju svakog pretplatnika koji treba optički priključak. Unesite oznaku (npr. "K-001") i adresu.

---

### Korak 6 — Raspored ODO ormarića

**Automatski (preporučeno):**
1. Klikni alat **"4 FTTH"** → Auto ODO Preview
2. Algoritam predlaže pozicije ormarića na osnovu lokacija kuća i trasa
3. Preglej prijedloge i score plana
4. Klikni **Potvrdi** — ormarići se kreiraju, kuće se dodjeljuju
5. Opciono: uključi **"Kreiraj drop trase"** uz potvrdu

**Ručno:**
- Dodaj ormarić direktno s mape ili s `/ormarici`
- Poveži kuće na ormarić dugmetom **"Poveži kuće"**

Više detalja: [06-auto-odo.md](06-auto-odo.md)

---

### Korak 7 — Sačuvaj plan

Klikni **"Sačuvaj na mapi"** (ili `Ctrl+S`). Sve trase, ormarici i kuće iz nacrta postaju trajni zapisi u bazi.

> Do ovog klika, podaci postoje samo kao privremeni nacrt koji se auto-čuva svakih 700 ms, ali nisu u bazi podataka.

---

### Korak 8 — Provjeri projekt

Idi na `/provjera-projekta` ili `/projekti/{id}/pregled`. Aplikacija prikazuje sve validacijske greške i upozorenja razvrstane po ozbiljnosti (crvena/žuta/plava). Adresuj sve crvene greške prije finalizacije.

Više detalja: [08-provjera-projekta.md](08-provjera-projekta.md)

---

### Korak 9 — Popuni drop trase (ako nisu automatski kreirane)

Na stranici provjere projekta klikni **"Popuni drop trase"** — kreira drop trase za sve kuće koje imaju dodijeljeni ODO ali nemaju nacrtanu trasu.

---

### Korak 10 — Generiši izvještaje i eksportuj

| Akcija | Gdje |
|--------|------|
| Materijalni obračun (Prilog 3) | `/izvjestaji/projekti/{id}/prilog-3` |
| Fiber šema (web / PDF / DXF) | `/fiber-sema` |
| Pregled splittera | `/splitteri` |
| DXF crtež (za AutoCAD) | `/projekti` → dugme **DXF** |
| GeoJSON (za GIS) | `/projekti` → dugme **GeoJSON** |
| Print / PDF pregled | `/projekti` → dugme **Print** |

Više detalja: [07-izvjestaji.md](07-izvjestaji.md)

---

## Rječnik pojmova

| Pojam | Značenje |
|---|---|
| **ODF** | Optički distribucionih frame — glavna tačka s koje kreće optička mreža. Ima kapacitet vlakana i portova. |
| **ODO / FTTH ormarić** | Splitter ormarić u polju. Sadrži 1–3 splittera, svaki s 4 porta = max 12 kuća po ormariću. |
| **Kuća / priključak** | Krajnja tačka mreže — fizički objekat koji se priključuje na ODO. |
| **Trasa** | Linijska geometrija kabela na mapi s tehničkim podacima (tip, dužina, mikrocijev, vlakna). |
| **Krak** | Logička grana mreže. Svaka trasa tipa backbone/feeder/distribution automatski dobija krak. |
| **Glavni rov** | Fizički iskop — prikazuje se ispod ostalih slojeva, nema mikrocijev ni vlakna. |
| **Drop trasa** | Trasa od ODO do kuće — mikrocijev 10/8, 4 vlakna. |
| **Nacrt (draft)** | Privremeno stanje crtanja na mapi koje se auto-čuva svakih 700 ms, ali nije snimljeno u bazu. |
| **Plan** | Potvrđeno stanje nacrta — snima se u bazu klikom "Sačuvaj na mapi". |
| **Prilog 3** | Standardni izvještaj prema FI 130 normativu: popis sahtova, bušenja, materijala. |
| **Fiber shema** | Vizualni raspored vlakana po splitterima s dodjelom vlakana ODF-u. |

---

## Tipovi trase

| `route_type` | Naziv u UI | Opis |
|---|---|---|
| `trench` | Glavni rov | Fizički iskop, nema kabla ni mikrocijevi |
| `backbone` | Backbone | ODF okosnica |
| `feeder` | Primarni krak | ODF → ODO |
| `distribution` | Sekundarni krak | ODO → ODO |
| `drop` | Drop trasa | ODO → kuća |

---

## Statusi trase

| Status | Opis |
|---|---|
| `planned` | Planirana — samo na papiru |
| `in_progress` | U izgradnji |
| `built` | Izgrađena |
