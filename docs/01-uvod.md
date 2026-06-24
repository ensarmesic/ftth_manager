# Uvod

FTTH Manager je web aplikacija za planiranje, vizualizaciju i dokumentaciju pasivnih optičkih FTTH mreža. Namijenjena je projektantima koji rade na izgradnji optičke infrastrukture — od ODF tačke do zadnjeg priključka.

Aplikacija radi lokalno (bez interneta), baza podataka je SQLite fajl, nema prijave korisnika.

---

## Tipičan tok rada na projektu

```
1. Kreiraj projekat  →  /projekti
2. Otvori mapu       →  /mapa?project=ID
3. Postavi ODF       →  alat "1 ODF" na mapi
4. Nacrtaj rovove i trase  →  alat "2 Trasa"
5. Označi kuće       →  alat "3 Kuće"
6. Pokreni Auto ODO  →  alat "4 FTTH" → Preview → Potvrdi → Sačuvaj na mapi
7. Provjeri projekt  →  /provjera-projekta
8. Generiši izvještaje  →  /izvjestaji  ili  /fiber-sema
9. Eksportuj DXF / PDF
```

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
