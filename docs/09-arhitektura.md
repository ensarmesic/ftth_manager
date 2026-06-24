# Tehnička arhitektura

---

## Stack

| Sloj | Tehnologija |
|---|---|
| Backend | PHP 8.3, Laravel 13 |
| Baza podataka | SQLite (fajl `database/database.sqlite`) |
| Frontend CSS | Tailwind CSS v4 (Vite build) |
| Frontend JS mapa | Vanilla JS, ~4300 linija, nije dio Vite builda |
| Mapa | Leaflet.js 1.9 |
| Koordinatna transformacija | proj4.js |
| PDF | barryvdh/laravel-dompdf |
| Test runner | PHPUnit 12 |

---

## Struktura direktorijuma

```
app/
  Http/Controllers/
    Concerns/
      ManagesFtthData.php   ← shared trait za sve controllere
    BranchController.php
    CabinetController.php
    HouseController.php
    MapController.php
    MapLayerController.php
    OdfController.php
    ProjectController.php
    ReportController.php
    RouteController.php
  Models/
    Cabinet.php
    House.php
    MapDraft.php
    Material.php
    NetworkBranch.php
    NetworkRoute.php        ← tabela: routes (ne network_routes!)
    Odf.php
    Project.php
    ProjectAppendixItem.php
  Services/
    FtthIntelligenceService.php  ← sva planiranja i validacija
public/
  js/
    ftth-map.js             ← mapa (~4300 linija, nije Vite build)
    ftth-dxf-layer.js       ← DXF canvas rendering
resources/
  css/app.css               ← Tailwind v4 (Vite build)
  js/app.js                 ← Vite entry
  views/ftth/
    layout.blade.php
    map.blade.php           ← glavni map view
    ...
routes/web.php
```

---

## Kako funkcioniše inicijalni load mape

Kada korisnik otvori `/mapa?project=1`:

1. `MapController::map()` čita sve elemente iz baze za taj projekat (ODF, ODO, kuće, trase, nacrt).
2. Serijalizuje ih u `window.ftthMapConfig.data` — jedan JSON blob koji se ubacuje direktno u Blade view kao `<script>` tag.
3. `window.ftthMapConfig.endpoints` sadrži sve URL-ove za API pozive (generisane s `route()` helperom).
4. Kad se stranica učita, `ftth-map.js` čita `window.ftthMapConfig.data` i crta sve elemente na Leaflet mapu.

Ovo je **jednosmjerna veza** server→klijent pri učitavanju. Nakon toga sve se radi AJAX-om.

---

## Sistem modova mape (state machine)

Mapa u svakom trenutku je u jednom **modu** — globalna varijabla `mode`. Promjena moda radi se funkcijom `setMode(next)`.

```
Dostupni modovi:
  pan, select, odf, cabinet, house, draw, trench-draw,
  manhole, boring-fi-130, ruler, branch-source,
  connect, connect-houses, trace, join, split
```

`setMode()` pri svakoj promjeni:
- Otkazuje aktivan edit trase ako se izlazi iz edit moda
- Briše mjerač ako se izlazi iz ruler moda
- Resetuje selekciju ako se izlazi iz select moda
- Vizualno označava aktivan toolbar dugme (`ring-2` klasa)
- Upisuje opis u status bar (`#cad-command`)

Svaki toolbar dugme ima ID `mode-{naziv}` i addEventListener koji poziva `setMode(m)`.

---

## Hit layer pattern (nevidljivi sloj za klik)

Svaka nacrtana trasa na mapi ima **dva** Leaflet layera:

```javascript
// Vizualni sloj — tanak, stilizovan
const line = L.polyline(points, { weight: 3, color: '#...', ... }).addTo(map);

// Hit sloj — nevidljiv, debeo 14px, prima klikove
const hitLine = L.polyline(points, { weight: 14, opacity: 0, interactive: true }).addTo(map);
```

Vizualni sloj je `interactive: false` — ne prima klikove. Hit sloj je nevidljiv ali prima sve klikove i mouseover evente. Ovako tanke linije (3px) imaju komfornu površinu za klik (14px) bez vizualnog kompromisa.

Lookup mape za brzi pristup po ID-u:
```javascript
routeLayerById[route.id]    // vizualni sloj
routeHitLayerById[route.id] // hit sloj
routeLabelsById[route.id]   // array label layera
```

---

## Layer registry — upravljanje slojevima

`layerRegistry` je objekat koji prati sve Leaflet layere po tipu:
```javascript
layerRegistry = {
  odf: [],
  odo: [],
  houses: [],
  trench: [],
  backbone: [],
  distribution: [],
  drop: [],
  trace: [],
  dxf: [],
}
```

`trackLayer(layer, type)` — dodaje layer u registry i označava ga s `layer._ftthLayerType`.

Kad korisnik uključi/isključi vidljivost sloja u panelu, iterira se po `layerRegistry[type]` i svaki se pokazuje/skriva. Ovo je zašto toggle slojeva radi momentalno — nema upita na server.

---

## Dva odvojena undo/redo sistema

Mapa ima **dva** neovisna undo/redo mehanizma:

### 1. Draft undo/redo (`undoStack` / `redoStack`)

Za elemente u nacrtu (nove ODF, ODO, kuće, nacrtane trase koje još nisu snimljene). Svaka akcija je objekt `{ undo: fn, redo: fn }`.

```javascript
pushUndo({ undo: () => { /* ukloni s mape */ }, redo: () => { /* vrati na mapu */ } });
```

Ctrl+Z → `undoLast()` → `action.undo()` → prebaci akciju u `redoStack`.

### 2. Route edit undo/redo (`routeEditUndoStack` / `routeEditRedoStack`)

Poseban stack samo za edit mode trase (pomjeranje vrhova, dodavanje/brisanje tačaka na geometriji). Aktivira se dok je trasa u edit modu, briše se pri napuštanju.

### 3. Map history (`mapHistory`)

Treći mehanizam za **snimljene** elemente. Npr. brisanje snimljene trase registruje u `mapHistory.push({ undo: async () => { /* recreate via POST */ } })`. Undo zapravo pravi novi API poziv i vraća trasu u bazu.

---

## Draft/Plan tok — kako se nacrt pretvara u plan

### Nacrt (draft) — privremeno stanje

Dok korisnik crta, svi elementi žive samo u memoriji JS-a:
- `draftOdfs[]` — markeri ODF-a u nacrtu
- `draftCabinets[]` — markeri ODO-a u nacrtu
- `housePoints[]` — koordinate kuća (snimljene + nacrt)
- `branches[]` / `branchMeta[]` — nacrtane trase s metapodacima
- `draftAppendixItems[]` — sahtovi i bušenja u nacrtu

**Auto-save** — timer od 700 ms poziva `draftPayload()` koji serijalizuje sve gore navedeno u JSON i šalje `POST /mapa/draft`. Server upisuje u `map_drafts` (upsert po `project_id`).

`draftPayload()` koristi `odf_index` umjesto `odf_id` za ODO-e koji su i sami u nacrtu — jer još nemaju pravi ID iz baze.

### Restore nacrta

Kad se otvori projekat koji ima nacrt, poziva se `restoreDraft(payload)`:
1. Briše sve u memoriji i s mape
2. Iz payloada rekonstruira sve drafte: kreira nove Leaflet markere/linije, dodaje ih na mapu, registruje ih

Korisnik vidi tačno ono što je ostavio — bez da je bilo šta snimljeno u bazu.

### Plan — commit nacrta u bazu

Klik "Sačuvaj na mapi" → `POST /mapa/plan` s punim JSON payloadom.

Server u jednoj DB transakciji:
1. Kreira ODF-e (dobijaju prave ID-eve)
2. Kreira ODO-e (rješava `odf_index` → pravi `odf_id`)
3. Kreira kuće
4. Kreira trase + za svaku poziva `createBranchForRoute()`
5. Kreira stavke Prilog 3
6. Auto-dodjeljuje kuće sekundarnim krakovima (`assignCreatedHousesToDraftBranches`)
7. Briše nacrt (`map_drafts`)

Ako bilo koji korak ne uspije → rollback, ništa se ne snima.

---

## Auto-naming trasa i ormarića

JavaScript strana prati sve korištene nazive (i snimljene i u nacrtu) da generira sljedeći jedinstven naziv.

### Naziv sljedeće trase

```javascript
nextRouteName('distribution')
// → skenira data.routes + branchMeta
// → izvlači brojeve iz naziva "Sekundarni krak 3"
// → vraća "Sekundarni krak 4"
```

### Naziv sljedećeg kraka iz ODO-a

```javascript
nextCabinetBranchName(cabinet)
// → čita kôd grane iz naziva ormarića (npr. "FTTH 1-1-2" → code "1.1")
// → skenira sve trase s tim prefiksom
// → vraća "Sekundarni krak 1.1.3"
```

Server ima istu logiku u `ManagesFtthData::nextSequentialProjectName()` za slučaj kad se element kreira direktno bez mape.

---

## Quick branch workflow

Desni klik na ODO ormarić na mapi → "Novi krak iz ovog ormarića" poziva `startBranchFromCabinet(cabinet)`:

1. Popuni `route-start-source` input s `cabinet:{id}`
2. Postavi `route-draw-type` na `distribution`
3. Auto-generiraj naziv kraka (`nextCabinetBranchName`)
4. Pozovi `setMode('draw')`
5. Odmah dodaj prvu tačku na koordinatu ormarića

Korisnik samo nastavlja klikati dalje po mapi — prva tačka je već na ODO-u.

---

## ORTHO mod

```javascript
function applyOrthoPoint(latlng) {
    if (!orthoEnabled || !activeBranch.length) return latlng;
    const last = activeBranch[activeBranch.length - 1];
    const latDiff = Math.abs(latlng.lat - last.lat);
    const lngDiff = Math.abs(latlng.lng - last.lng);
    return latDiff >= lngDiff
        ? L.latLng(latlng.lat, last.lng)   // veći pomak je lat → zakači lng
        : L.latLng(last.lat, latlng.lng);  // veći pomak je lng → zakači lat
}
```

Svaki klik pri crtanju prolazi kroz `applyOrthoPoint()`. Ako je ORTHO aktivan, nova tačka se zaključava na horizontalnu ili vertikalnu liniju od prethodne tačke, zavisno od toga koji pomak je veći.

---

## Snap sistem

Snap se računa na svaki `mousemove` event. Provjerava se udaljenost kursora (u pikselima) od:
- Svih ODF markera
- Svih ODO markera
- Svih kuća
- Krajnjih tačaka snimljenih trasa
- Vrhova trase u edit modu

Tolerancija: 30 piksela (`snapPixelTolerance`). Udaljenost u pikselima se računa iz geo koordinata kroz Leaflet projekciju.

Kada je snap aktivan:
- Kursor se vizualno "zaljepi" (snap indikator krug)
- Nova tačka koristi tačnu koordinatu snapovane tačke
- Status bar prikazuje `Snap: [naziv elementa]`

---

## Fiber trace — optički put kuće

Alat `trace` na mapi: klik na kuću prikazuje pun optički put od kuće do ODF-a.

Algoritam:
1. Nađi ODO kuće (`house.cabinet_id`)
2. Nađi drop trasu koja ide od tog ODO do te kuće (`traceRouteFor('drop', ...)`)
3. Prođi kroz `cabinetSupplyChain(cabinet)` — prati `parent_cabinet_id` lanac do vrha
4. Za svaki ODO u lancu nađi supply trasu prema roditelju (`supplyRouteToCabinet`)
5. Nacrta sve pronađene trase žutom bojom na mapi

Ako nema eksplicitnih trasa, koristi se logički spoj linijama.

---

## Provjera projekta — integracija s mapom

`runProjectCheck()` na mapi:
1. Poziva `GET /projekti/{id}/validacija` (JSON)
2. Prikazuje liste u panelu (boja po nivou: crvena/žuta/plava/zelena)
3. Svaki klik na stavku poziva `focusValidationItem(item)`:
   - Panna mapu na taj element (`map.setView`)
   - Otvara popup ako element ima marker (`layer.openPopup()`)
   - Upisuje poruku u status bar

Ovo je zašto klik na upozorenje "K-001 nema drop trasu" odmah navigira na tu kuću na mapi.

---

## ManagesFtthData trait

`app/Http/Controllers/Concerns/ManagesFtthData.php`

Svi controlleri koriste ovaj trait. Sadrži:

### Geometrijska matematika

**`polylineLength(array $points): int`**
Haversine formula za zbir dužina segmenata polilinije. Vraća metre, zaokruženo.

**`projectPointToRoute(float $lat, float $lng, NetworkRoute $route): array`**
Projektuje tačku na trasu — nalazi najbližu tačku na svim segmentima. Vraća `{lat, lng, distance_m, chainage_m, segment_index}`. Osnova za sve operacije koje zahtijevaju "gdje na trasi je ovaj element".

**`routePathBetween(NetworkRoute $route, array $start, array $end): array`**
Izvlači podskup geometrije trase između dvije projekcijske tačke. Koristi chainage za orijentaciju, radi i za obrnuti smjer.

**`dropPathForHouse(Cabinet $cabinet, House $house): array`**
Pametni routing drop trase:
- Nađi najbliži sekundarni krak od ormarića
- Ako je ormarić ≤ 35 m od kraka i kuća ≤ 90 m od kraka → prati geometriju kraka između projekcija, pa ide do kuće
- Inače → ravna linija ODO → kuća

**`compactPath(array $path): array`**
Uklanja duplikate i tačke koje su bliže od 0.0000001° (≈ 1 cm). Sprječava degenerirane geometrije.

### Krak logika

**`createBranchForRoute(NetworkRoute $route): ?NetworkBranch`**
Automatski kreira `NetworkBranch` za novu trasu. Ne kreira za `drop` i `trench`. Pokušava nasljediti ODF od izvorne tačke ili od projekta ako postoji samo jedan ODF. Dodjeljuje tip `primary` za backbone/feeder, `secondary` za distribution.

**`syncBranchForRoute(NetworkRoute $route): void`**
Ažurira krak kad se trasa mijenja (naziv, tip, ODF). Ako je trasa promijenjena u `drop`/`trench` — briše krak.

**`deleteRouteWithBranch(NetworkRoute $route): void`**
U transakciji: odvezuje ODO-e od kraka, odvezuje dječje krakove, briše krak, briše trasu.

### Validacioni helperi

**`ensureBelongsToProject(string $model, mixed $id, int $projectId, string $field): void`**
Koristi Laravel validator (ne direktan upit) da provjeri da element pripada projektu. Baca validacijsku grešku, ne 404.

**`branchWouldCreateCycle(int $branchId, ?int $parentBranchId): bool`**
Prati `parent_branch_id` lanac gore i provjerava da li bi novi roditelj napravio ciklus. Čuva `$visited` skup da sprečava beskonačnu petlju u slučaju već postojeće korupcije.

---

## FtthIntelligenceService — detalji

`app/Services/FtthIntelligenceService.php`

### Chainage sistem

Chainage je udaljenost projekcije elementa od početka trase. Koristi se za sortiranje kuća "po redu duž trase" unutar jednog kraka.

```
Trasa: A ------- B ------- C ------- D
                 ↑
               Kuća 1
               chainage = udaljenost A→projekcija(K1)
```

Kuće se sortiraju po chainageu prije grupisanja, pa grupe prate fizički redoslijed duž kabla.

### Medoid vs centroid

```
Centroid: matematički srednji punkt
          → može pasti između zgrada (na ulici, u zraku)

Medoid:   stvarna kuća s najmanjim ukupnim zbirom udaljenosti do svih
          → uvijek je na stvarnoj lokaciji (kuće)
          → predloženi ODO se stavlja na projekciju medoida na trasu
```

### Praznina (gap) u chainageu

Ako su dvije uzastopne kuće (po chainageu) razdvojene s > `max_gap_m` (default 100 m), algoritam prekida grupu i počinje novu. Ovo sprječava da jedan ODO pokrije kuće s dvaju strana ulice s dugačkim razmakom.

### Validacija plana pri potvrdi

`confirmOdoPlan()` radi stroge provjere koje `preview` ne radi:
- Svaka kuća je prisutna tačno jednom (detekcija duplikata po ID-u)
- Nijedna kuća koja već ima ODO ne pojavljuje se u planu (osim ako je to bio prethodni Auto plan s istim imenom)
- ODF i kuće moraju biti iz istog projekta
- Sve je u jednoj DB transakciji — bilo koja greška rollbackuje sve

---

## Model baze podataka

### Tabele i ključna polja

```sql
projects          id, name, code, location, investor, status, start_date, deadline
odfs              id, project_id, name, address, fiber_capacity, port_count, latitude, longitude
cabinets          id, project_id, odf_id, parent_cabinet_id, branch_id, branch_order,
                  name, address, splitter_count, ports_per_splitter, latitude, longitude
houses            id, project_id, cabinet_id, branch_id, label, address, status, latitude, longitude
routes            id, project_id, odf_id, cabinet_id, from_type, from_id, to_type, to_id,
                  name, route_type, installation_type, trench_group, counts_as_trench,
                  trench_length_m, duct_length_m, fiber_length_m, fiber_count,
                  microduct_count, microduct_type, cable_type, status, path, note
network_branches  id, project_id, odf_id, parent_branch_id, route_id, name, code, type, sort_order
map_drafts        id, project_id, payload (JSON)
materials         id, project_id, name, unit, planned_quantity, unit_price
project_appendix_items  id, project_id, type, quantity, unit, note,
                        latitude, longitude, length_m, angle_deg, width_m
```

### Napomene o shemi

- `NetworkRoute` model koristi tabelu `routes` (`protected $table = 'routes'`)
- `path` kolona je JSON array `[[lat, lng], ...]`, castovan na PHP array u modelu
- `Cabinet.capacity` je computed attribute (`splitter_count × ports_per_splitter`) — nije u bazi
- `network_branches.route_id` je UNIQUE — jedan krak = jedna trasa
- `from_type`/`from_id` i `to_type`/`to_id` su polimorfne veze (nije Laravel morfizam, ručno implementovano)
- Sve FK relacije imaju indekse (dodate u migracijama juni 2026)

---

## Frontend JS arhitektura

`public/js/ftth-map.js` je monolitni fajl od ~4300 linija. **Nije transpiliran, nije dio Vite builda** — edituje se direktno. Promjene su trenutne bez build koraka.

### Globalne lookup mape

Za O(1) pristup Leaflet layerima po ID-u:
```javascript
odfMarkerById[id]         // Leaflet marker ODF-a
cabinetMarkerById[id]     // Leaflet marker ODO-a
houseMarkerById[id]       // Leaflet marker kuće
houseMarkerByKey[key]     // marker kuće po "lat,lng" ključu
routeLayerById[id]        // vizualni polyline trase
routeHitLayerById[id]     // hit polyline trase
routeLabelsById[id]       // array label layera trase
```

### `window.ftthMapConfig`

Jednosmjerna veza server → klijent:
```javascript
window.ftthMapConfig = {
  data: {
    odfs: [...],      // svi ODF-i projekta s koordinatama
    cabinets: [...],  // svi ODO-i s kapacitetom
    houses: [...],    // sve kuće
    routes: [...],    // sve trase s path geometrijom
    drafts: [...],    // nacrti projekata (max 1 po projektu)
    appendix_items: [...]
  },
  endpoints: {
    routesBase: '/trase',
    planStore: '/mapa/plan',
    draftStore: '/mapa/draft',
    // ... ostali URL-ovi
  }
}
```

### DXF podloga — kako radi

`public/js/ftth-dxf-layer.js` kreira `<canvas>` element iste veličine kao Leaflet mapa, pozicionira ga iznad mape kroz Leaflet `L.DivOverlay`.

Tok:
1. Korisnik odabere DXF/DWG fajl
2. Fajl se šalje na `POST /mapa/dxf-layer` (samo za detekciju koordinatnog sistema)
3. Server vraća detektovani CRS (MGI zona 6/7 ili WGS84) i transformacijske parametre
4. **Cijeli binarni fajl ostaje u memoriji pregledača** — ne snima se na server
5. `proj4.js` transformira koordinate DXF-a u WGS84
6. Geometrija se renderuje na `<canvas>` elementu
7. Fajl + transformacijski podaci se snimaju u **IndexedDB** pregledača (preživi reload)
8. Pri sljedećem učitavanju projekta, fajl se čita iz IndexedDB i ponovo renderuje

---

## Testovi

```
tests/
  Feature/
    FtthIntelligenceTest.php     ← Auto ODO planiranje, validacija, materijali
    MediaskyWorkflowTest.php     ← Kompletan workflow od projekta do izvještaja
  Unit/
    ExampleTest.php
```

Testovi koriste in-memory SQLite (`DB_DATABASE=:memory:`). Feature testovi su integracijski — koriste pravi HTTP stack, prave DB transakcije, bez mockova.

```bash
php artisan test
php artisan test --filter FtthIntelligenceTest
php artisan test tests/Feature/FtthIntelligenceTest.php::test_confirmed_plan_creates_cabinets
```

---

## Autorizacija

Aplikacija **nema autorizacijski sistem**. Namijenjena je za lokalno korišćenje. Svi URL-ovi su javno dostupni.
