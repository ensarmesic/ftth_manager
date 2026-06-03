# FTTH Manager - QA i refactor izvjestaj, 2026-06-03

## Ručna / smoke QA provjera

U ovom okruženju nije dostupan pravi browser sa DevTools konzolom, pa je urađena lokalna HTTP smoke provjera preko `php artisan serve` za:

- Dashboard `/`
- Mapa `/mapa`
- Projekti `/projekti`
- ODF `/odf`
- ODO ormarići `/ormarici`
- Kuće `/kuce`
- Korisnici `/korisnici`
- Trase `/trase`
- Materijali `/materijali`
- Izvještaji `/izvjestaji`

Sve navedene stranice vraćaju HTTP 200 nakon popravke layouta.

## Popravljeno

- Popravljena runtime greška na `/mapa`: zajednički layout je prikazivao sidebar quick stats i na map viewu, iako mapa ne šalje `$stats`. Quick stats se sada prikazuju samo kada postoje potrebne varijable.
- Centralizovana validacija koordinata kroz helper metode `latitudeRules()` i `longitudeRules()`.
- Zadržane postojeće AJAX rute i formati odgovora.
- `public/js/ftth-map.js` nije mijenjan.

## Refactor procjena

`FtthController` je i dalje velik i treba ga podijeliti, ali puni refactor nije urađen u ovoj fazi jer nije bilo moguće ručno klik-testirati CAD/GIS mapu u browseru i provjeriti konzolne greške. Mapa ima najviše AJAX tokova i najviše zavisnosti između metoda, pa je sigurnije prvo uraditi browser QA sa DevTools.

## Plan podjele kontrolera

- `DashboardController`
  - `dashboard`

- `MapController`
  - `map`
  - `storePlan`
  - `storeDraft`
  - `storeSuggestedCabinets`
  - `updateOdfPosition`
  - `updateCabinetPosition`
  - `updateHousePosition`
  - shared map response helpers

- `ProjectController`
  - `projects`
  - `storeProject`
  - `updateProject`
  - `deleteProject`
  - `nextProjectCode`

- `OdfController`
  - `odfs`
  - `storeOdf`
  - `updateOdf`
  - `deleteOdf`

- `CabinetController`
  - `cabinets`
  - `storeCabinet`
  - `updateCabinet`
  - `deleteCabinet`

- `HouseController`
  - `houses`
  - `storeHouse`
  - `updateHouse`
  - `deleteHouse`

- `SubscriberController`
  - `subscribers`
  - `storeSubscriber`
  - `updateSubscriber`
  - `deleteSubscriber`

- `RouteController`
  - `routes`
  - `storeRoute`
  - `updateRoute`
  - `updateRouteGeometry`
  - `deleteRoute`
  - `importDxf`
  - `parseDxfPolylines`
  - `polylineLength`

- `MaterialController`
  - `materials`
  - `storeMaterial`
  - `updateMaterial`
  - `deleteMaterial`
  - `calculateMaterials`

- `ReportController`
  - `reports`
  - `splitters`
  - `fiberSchema`
  - `projectCheck`
  - `settings`

Shared validation/helper layer:

- `ensureBelongsToProject`
- `ensureCabinetHouseCapacity`
- `latitudeRules`
- `longitudeRules`
- route geometry validation helpers
- common JSON/form error response helpers

## Kontroleri trenutno

- `Controller`
- `FtthController`

Puni split je ostavljen za narednu fazu nakon browser QA provjere CAD/GIS mape.

## Rute

URL adrese i imena ruta su očuvani. Posebno su provjerene rute za:

- `/mapa`
- `/projekti`
- `/odf`
- `/ormarici`
- `/kuce`
- `/korisnici`
- `/trase`
- `/materijali`

## Završne provjere

Pokrenuto:

- `php artisan test`
- `npm run build`
- `php artisan view:cache`
- `php artisan route:list`
- `git diff --check`

Dodatno za rute:

- `php artisan route:list --path=mapa`
- `php artisan route:list --path=projekti`
- `php artisan route:list --path=odf`
- `php artisan route:list --path=ormarici`
- `php artisan route:list --path=kuce`
- `php artisan route:list --path=korisnici`
- `php artisan route:list --path=trase`
- `php artisan route:list --path=materijali`

## Ostaje za narednu fazu

- Pravi browser QA sa Ctrl+F5 i DevTools konzolom.
- Klik-test CAD/GIS alata: OSM/satelit, dodavanje elemenata, crtanje trase, edit geometrije, snap, ESC/Backspace, brisanje i property panel.
- Nakon toga siguran split `FtthController` u manje kontrolere.
