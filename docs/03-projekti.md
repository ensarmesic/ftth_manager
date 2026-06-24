# Upravljanje projektima

Svaki projekat je izolovan entitet s vlastitom mapom, nacrtom i podacima. Elementi jednog projekta ne mogu se koristiti u drugom projektu.

---

## Stranice

| URL | Opis |
|---|---|
| `/projekti` | Lista svih projekata s paginacijom |
| `/projekti/{id}/pregled` | Detaljan pregled projekta |
| `/mapa?project={id}` | Mapa filtrirana na jedan projekat |

---

## Kreiranje projekta

Na `/projekti` klikom na "Novi projekat" otvara se forma s poljima:

| Polje | Obavezno | Opis |
|---|---|---|
| Naziv | Da | Pun naziv projekta |
| Šifra (code) | Auto | Automatski se generiše iz naziva (slug), može se promijeniti. Mora biti jedinstvena. |
| Lokacija | Da | Mjesto izgradnje |
| Investitor | Ne | Naziv investitora |
| Status | Da | `planning`, `active`, `paused`, `completed` |
| Datum početka | Ne | |
| Rok završetka | Ne | |
| Napomena | Ne | Max 2000 znakova |

---

## Pregled projekta (`/projekti/{id}/pregled`)

Stranica prikazuje:

- **Statistike** — broj ODF-a, ODO-a, kuća, trasa, krakova
- **ODF kapacitet** — iskorištenost po ODF-u (vlakna)
- **Krakovi** — lista s brojem ODO-a po kraku
- **Validacijska upozorenja** — direktan prikaz provjere projekta
- **Materijalni obračun** — dužine kablova i mikrocijevi po tipu
- **Akcije** — eksport GeoJSON, eksport DXF, print prikaz

---

## Statusi projekta

| Status | Značenje |
|---|---|
| `planning` | U fazi planiranja |
| `active` | Aktivna izgradnja |
| `paused` | Pauziran |
| `completed` | Završen |

---

## Brisanje projekta

Brisanje projekta briše sve zavisne elemente (ODF, ODO, kuće, trase, krakove, nacrt, materijale, stavke Priloga 3) zbog kaskadnog brisanja u bazi.

---

## Eksport projekta

### GeoJSON (`GET /projekti/{id}/geojson`)

Preuzima punu geometriju mreže kao GeoJSON FeatureCollection. Svaki element (ODF, ODO, kuća, trasa) je zasebna Feature s `properties` objektom koji sadrži sve tehničke podatke.

### DXF (`POST /projekti/{id}/dxf`)

Generiše DXF crtež mreže. Zahtijeva POST jer klijent šalje opcije (npr. da li uključiti DXF pozadinsku podlogu). Rezultat se direktno preuzima kao `.dxf` fajl.

### Print (`GET /projekti/{id}/print`)

Formatiran HTML pregled projekta prilagođen za štampu.
