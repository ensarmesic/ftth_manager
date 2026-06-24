# API Endpoints

Sve rute su definirane u `routes/web.php`. Nema posebnog `/api/` prefiksa osim `/api/notifications`.

Zahtjevi koji vraćaju JSON moraju imati `Accept: application/json` header ili koristiti `axios` (koji to šalje automatski).

---

## Mapa

| Metoda | URL | Akcija |
|---|---|---|
| GET | `/mapa` | Map view (HTML) |
| POST | `/mapa/plan` | Snimi kompletan plan s mape u bazu |
| POST | `/mapa/draft` | Upsert nacrta projekta |
| POST | `/mapa/sugestije` | Snimi predložene Auto ODO ormariće |

### POST /mapa/plan

```json
{
  "project_id": 1,
  "plan": "{
    \"odfs\": [{ \"name\": \"ODF-01\", \"lat\": 44.449, \"lng\": 18.65, \"fiber_capacity\": 144, \"port_count\": 48 }],
    \"cabinets\": [{ \"name\": \"FTTH 1-1-1\", \"lat\": 44.450, \"lng\": 18.651, \"odf_index\": 0 }],
    \"houses\": [{ \"label\": \"K-001\", \"lat\": 44.451, \"lng\": 18.652, \"cabinet_index\": 0 }],
    \"routes\": [{ \"route_type\": \"feeder\", \"path\": [[44.449,18.65],[44.450,18.651]], \"fiber_count\": 12, \"microduct_type\": \"14/10\" }],
    \"appendix_items\": []
  }"
}
```

`plan` je JSON string (ne objekt). Koristi se `odf_index`/`cabinet_index` za referenciranje elemenata koji su kreirani u istom planu.

### POST /mapa/draft

```json
{
  "project_id": 1,
  "draft": "{ ... JSON stanje nacrta ... }"
}
```

---

## Projekti

| Metoda | URL | Akcija |
|---|---|---|
| GET | `/projekti` | Lista projekata (HTML) |
| POST | `/projekti` | Kreiraj projekat |
| GET | `/projekti/{id}/pregled` | Pregled projekta (HTML) |
| PUT/PATCH | `/projekti/{id}` | Ažuriraj projekat |
| DELETE | `/projekti/{id}` | Obriši projekat |
| POST | `/projekti/{id}/odo-plan/preview` | Auto ODO preview |
| POST | `/projekti/{id}/odo-plan/confirm` | Potvrdi Auto ODO plan |
| GET | `/projekti/{id}/validacija` | JSON validacija projekta |
| POST | `/projekti/{id}/drop-trase/popuni` | Kreiraj nedostajuće drop trase |
| GET | `/projekti/{id}/geojson` | Eksport GeoJSON |
| POST | `/projekti/{id}/dxf` | Eksport DXF |
| GET | `/projekti/{id}/fiber-schema-dxf` | Eksport DXF fiber sheme |
| GET | `/projekti/{id}/print` | Print prikaz (HTML) |

### POST /projekti/{id}/odo-plan/preview

```json
{
  "max_houses_per_odo": 12,
  "max_house_to_odo_m": 120,
  "max_branch_distance_m": 120,
  "max_gap_m": 100,
  "preferred_fill_min": 8,
  "create_drop_routes": false
}
```

Odgovor (200):
```json
{
  "project": { "id": 1, "name": "...", "code": "..." },
  "parameters": { ... },
  "summary": {
    "houses_with_coordinates": 45,
    "proposed_odo_count": 5,
    "average_house_distance_m": 65,
    "score": 78
  },
  "branches": [ { "branch_id": 1, "name": "...", "house_count": 10, "odo_count": 1, "score": 82 } ],
  "warnings": [ { "level": "warning", "message": "...", "element_type": "project", "element_id": 1, "recommendation": "..." } ],
  "cabinets": [ { "name": "FTTH 1-1-1 PRIJEDLOG", "proposed_latitude": 44.45, "proposed_longitude": 18.65, "houses": [...], ... } ]
}
```

### POST /projekti/{id}/odo-plan/confirm

```json
{
  "plan": { ... cijeli objekt iz preview odgovora ... },
  "create_drop_routes": false
}
```

Odgovor (201):
```json
{
  "message": "Kreirano 5 ODO ormarica.",
  "created": 5,
  "linked_houses": 45,
  "created_routes": 0
}
```

### GET /projekti/{id}/validacija

Odgovor (200):
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
  "materials": {
    "odf_count": 1,
    "odo_count": 5,
    "house_count": 45,
    "route_length_m": 1250,
    "fiber_12_m": 800,
    "microduct_14_10_m": 800
  }
}
```

---

## ODF

| Metoda | URL | Akcija |
|---|---|---|
| GET | `/odf` | Lista ODF-a (HTML) |
| POST | `/odf` | Kreiraj ODF |
| PUT/PATCH | `/odf/{id}` | Ažuriraj ODF |
| PATCH | `/odf/{id}/pozicija` | Ažuriraj koordinate ODF-a |
| DELETE | `/odf/{id}` | Obriši ODF |

### POST /odf

```json
{
  "project_id": 1,
  "name": "ODF-01",
  "address": "Ulica bb",
  "fiber_capacity": 144,
  "port_count": 48,
  "latitude": 44.449,
  "longitude": 18.65
}
```

---

## ODO (Ormarići)

| Metoda | URL | Akcija |
|---|---|---|
| GET | `/ormarici` | Lista ormarića (HTML) |
| POST | `/ormarici` | Kreiraj ODO |
| PUT/PATCH | `/ormarici/{id}` | Ažuriraj ODO |
| PATCH | `/ormarici/{id}/pozicija` | Ažuriraj koordinate |
| DELETE | `/ormarici/{id}` | Obriši ODO |
| POST | `/ormarici/{id}/povezi-kuce` | Poveži kuće na ODO |

### POST /ormarici/{id}/povezi-kuce

```json
{
  "house_ids": [1, 2, 3]
}
```

---

## Kuće

| Metoda | URL | Akcija |
|---|---|---|
| GET | `/kuce` | Lista kuća (HTML) |
| POST | `/kuce` | Kreiraj kuću |
| PUT/PATCH | `/kuce/{id}` | Ažuriraj kuću |
| PATCH | `/kuce/{id}/pozicija` | Ažuriraj koordinate |
| DELETE | `/kuce/{id}` | Obriši kuću |

---

## Trase

| Metoda | URL | Akcija |
|---|---|---|
| GET | `/trase` | Lista trasa (HTML) |
| POST | `/trase` | Kreiraj trasu |
| PUT/PATCH | `/trase/{id}` | Ažuriraj trasu |
| PATCH | `/trase/{id}/geometrija` | Ažuriraj geometriju trase |
| POST | `/trase/{id}/split` | Podijeli trasu na dvije |
| POST | `/trase/{id}/join` | Spoji s drugom trasom (lista ID-a) |
| POST | `/trase/{id}/join/{otherId}` | Spoji dvije specifične trase |
| POST | `/trase/dxf` | Uvezi trase iz DXF fajla |
| DELETE | `/trase/{id}` | Obriši trasu |

### PATCH /trase/{id}/geometrija

```json
{
  "path": [[44.449, 18.65], [44.450, 18.651], [44.451, 18.652]]
}
```

Dužina se automatski preračunava iz novih koordinata.

---

## Krakovi

| Metoda | URL | Akcija |
|---|---|---|
| GET | `/krakovi` | Lista krakova (HTML) |
| POST | `/krakovi` | Kreiraj krak |
| PUT/PATCH | `/krakovi/{id}` | Ažuriraj krak |
| PATCH | `/krakovi/reorder` | Promijeni redoslijed krakova |
| DELETE | `/krakovi/{id}` | Obriši krak |

---

## Izvještaji

| Metoda | URL | Akcija |
|---|---|---|
| GET | `/izvjestaji` | Pregled izvještaja (HTML) |
| POST | `/izvjestaji/projekti/{id}/stavke-priloga` | Dodaj stavku Prilog 3 |
| DELETE | `/izvjestaji/stavke-priloga/{itemId}` | Obriši stavku Prilog 3 |
| GET | `/izvjestaji/projekti/{id}/prilog-3` | Prilog 3 (HTML) |
| GET | `/splitteri` | Pregled splittera (HTML) |
| GET | `/fiber-sema` | Fiber shema (HTML) |
| GET | `/projekti/{id}/fiber-sema/pdf` | Fiber shema PDF download |

---

## DXF podloga

| Metoda | URL | Akcija |
|---|---|---|
| POST | `/mapa/dxf-layer` | Upload DXF/DWG, detekcija koordinatnog sistema |

Odgovor sadrži detektovani koordinatni sistem i transformacijske parametre. Sam fajl se čuva u browser IndexedDB, ne na serveru.

---

## Notifikacije

| Metoda | URL | Akcija |
|---|---|---|
| GET | `/api/notifications` | Real-time notifikacije (nedodijeljene kuće/ODO-i) |

Odgovor:
```json
{
  "count": 2,
  "items": [
    "5 kuca nema dodijeljeni ODO.",
    "2 ODO ormarica nema povezani ODF."
  ]
}
```

Poziva se periodično s klijenta za prikaz badge broja u navigaciji.

---

## Ostale stranice

| URL | Opis |
|---|---|
| `/` | Redirect na `/mapa` |
| `/provjera-projekta` | Provjera projekta (HTML) |
| `/postavke` | Postavke aplikacije (HTML) |
