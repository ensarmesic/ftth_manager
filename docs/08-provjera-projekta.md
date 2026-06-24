# Provjera projekta

**URL:** `/provjera-projekta`

Automatska detekcija grešaka i upozorenja u projektu. Radi u realnom vremenu — svaki put kad se otvori stranica, vrši se nova provjera.

---

## Nivoi validacije

| Nivo | Boja | Značenje |
|---|---|---|
| `error` | Crvena | Ozbiljna greška — projekt ne može biti ispravan bez popravke |
| `warning` | Žuta | Upozorenje — treba popraviti prije finalizacije |
| `info` | Plava | Informacija — nije kritično, ali treba provjeriti |
| `ok` | Zelena | Sve je ispravno |

---

## Provjere po elementu

### Projekt

| Provjera | Nivo | Rješenje |
|---|---|---|
| Nema ODF-a | Warning | Dodaj ODF prije potvrde mreznog plana |
| Nema ODO ormarića | Info | Pokreni automatsko planiranje |
| Nema kuća | Info | Dodaj kuće iz mape ili liste |

### ODF

| Provjera | Nivo | Rješenje |
|---|---|---|
| Nema koordinata | Error | Postavi ODF na mapi |
| Nema ni jednog ODO-a | Warning | Poveži najmanje jedan ODO na ODF |
| Prekoračen kapacitet vlakana (> 100%) | Error | Smanji broj ODO-a ili povećaj kapacitet ODF-a |
| Kapacitet skoro popunjen (> 85%) | Warning | Razmotriti dodavanje novog ODF-a |

### Kuća

| Provjera | Nivo | Rješenje |
|---|---|---|
| Nema koordinata | Error | Postavi kuću na mapi |
| Nema dodijeljenog ODO-a | Warning | Dodijeli kuću ODO ormaricu |
| Nema drop trase | Warning | Nacrtaj ili automatski kreiraj drop trasu |
| Udaljenost od ODO-a > 120 m | Warning | Razmotriti novi ODO ili drugačije grupisanje |
| Udaljenost od kraka > 60 m | Warning | Pomjeri kuću ili dodaj krak bliže objektu |
| Kuća i njen ODO su na različitim krakovima | Error | Ponovi Auto ODO ili ručno ispravi vezu |

### ODO

| Provjera | Nivo | Rješenje |
|---|---|---|
| Nema koordinata | Error | Postavi ODO na mapi |
| Nema povezanog ODF-a (i nema roditeljskog ODO-a) | Warning | Poveži ODO s najbližim ODF-om |
| Neispravna splitter konfiguracija (> 3 splittera ili > 4 porta) | Error | Koristi najviše 3 splittera sa po 4 porta |
| Nema nijedne kuće | Info | Poveži kuće na ODO |
| Više od 12 kuća | Error | Rastereti ODO ili kreiraj dodatni ODO |
| Nedovoljno splittera za broj kuća | Error | Postavi ispravan broj splittera |
| Udaljenost od kraka > 10 m | Warning | Pomjeri ODO na najbližu tačku trase |

### Trasa

| Provjera | Nivo | Rješenje |
|---|---|---|
| Nema geometrije (path) | Warning | Uredi geometriju trase na mapi |
| Manje od 2 tačke | Error | Dodaj najmanje dvije tačke trase |
| Dužina = 0 | Error | Uredi geometriju trase |
| Nema kabla (fiber_count) | Warning | Unesi broj niti kabla |
| Nema mikrocijevi (osim trench) | Warning | Unesi profil mikrocijevi |
| Nema tipa polaganja | Warning | Odaberi podzemno ili zračno polaganje |
| Drop trasa nema ciljnu kuću | Error | Postavi to_type=house i to_id |
| Broj vlakana < broja kuća spojenih na ODO (iskorištenost > 100%) | Error | Povećaj kapacitet kabla |
| Iskorištenost > 80% | Warning | Planiraj rezervni kapacitet |

---

## API endpoint

`GET /projekti/{id}/validacija`

Vraća JSON s:
```json
{
  "project": { "id": 1, "name": "..." },
  "items": [
    {
      "level": "warning",
      "message": "K-001 nema drop trasu.",
      "element_type": "house",
      "element_id": 42,
      "recommendation": "Nacrtaj ili automatski kreiraj drop trasu."
    }
  ],
  "materials": { ... }
}
```

`element_type` može biti: `project`, `odf`, `cabinet`, `house`, `route`

---

## Autocreate drop trasa

Na stranici provjere projekta (i pregleda projekta) dugme **"Popuni drop trase"** automatski kreira drop trase za sve kuće koje:
- Imaju dodijeljeni ODO
- Imaju koordinate
- Još nemaju drop trasu

Svaka kreirana drop trasa:
- Tip: `drop`, status: `planned`
- Mikrocijev: `10/8`, vlakna: 4
- Putanja: prati geometriju sekundarnog kraka ako je kuća dovoljno blizu (≤ 90 m od kraka i ODO ≤ 35 m od kraka), inače ravna linija ODO → kuća
