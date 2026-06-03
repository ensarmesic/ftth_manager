# FTTH Manager - FTTH inteligencija projekta, 2026-06-03

## Implementirano

- Server-side automatsko planiranje ODO ormarića iz stvarnih kuća u bazi.
- Preview plana bez upisa u bazu.
- Potvrda plana kroz DB transakciju.
- Automatsko povezivanje kuća na potvrđene ODO ormariće.
- Automatski broj splittera po pravilu 1 splitter na 1-4 kuće, 2 na 5-8, 3 na 9-12.
- Maksimalan kapacitet ODO grupe je 12 kuća.
- Kuće bez koordinata se preskaču u algoritmu i prijavljuju u preview summary.
- Najbliži ODF iz istog projekta se dodjeljuje predloženom ODO-u ako postoji.
- Projektna validacija sa `level`, `message`, `element_type`, `element_id`, `recommendation`.
- Materijalni summary za ODF, ODO, kuće, korisnike, splittere, mikrocijevi i optičke kablove.
- Izvještaji sada prikazuju projektnu validaciju i materijalni obračun po projektu.

## Algoritam grupisanja

Planiranje koristi nearest-neighbor pristup:

1. Uzimaju se kuće iz projekta koje imaju latitude i longitude.
2. Kuće se sortiraju po koordinatama.
3. Prva nepokrivena kuća otvara novu grupu.
4. U grupu se dodaje najbliža kuća trenutnom medoidu dok grupa ne dosegne kapacitet ili dok najbliža kuća ne pređe `max_distance_m`.
5. Grupa se zaključava i algoritam nastavlja sa sljedećom nepokrivenom kućom.

Nijedna kuća ne može biti u dvije grupe i nijedna grupa ne može imati više od 12 kuća.

## Medoid

Za svaku grupu se računa medoid:

- Za svaku kuću u grupi računa se zbir udaljenosti do svih drugih kuća.
- Kuća sa najmanjim zbirom udaljenosti je medoid.
- Predloženi ODO se stavlja na koordinatu medoida.
- Centroid se računa samo kao informativni podatak.

Medoid je izabran jer je realna lokacija postojeće kuće, za razliku od centroida koji može završiti na slučajnoj tački između objekata.

## Score plana

Score je 0-100 i računa se iz:

- 40% prosječna udaljenost kuća do ODO
- 25% prosječna popunjenost ODO ormarića
- 20% broj upozorenja
- 15% broj ODO ormarića u odnosu na idealni minimum

Prosječna udaljenost do 60 m je odlična, 60-120 m srednja, a preko 120 m loša. Iskorištenost 70-100% je dobra, ispod 50% generiše upozorenje.

## Rute

Dodane rute:

- `POST /projekti/{project}/odo-plan/preview` (`projects.odo-plan.preview`)
- `POST /projekti/{project}/odo-plan/confirm` (`projects.odo-plan.confirm`)
- `GET /projekti/{project}/validacija` (`projects.validation`)

Postojeće rute nisu mijenjale URL adrese niti imena.

## Promijenjeni fajlovi

- `app/Services/FtthIntelligenceService.php`
- `app/Http/Controllers/FtthController.php`
- `routes/web.php`
- `resources/views/ftth/reports.blade.php`
- `database/migrations/2026_05_29_000003_create_houses_table.php`
- `database/migrations/2026_06_01_000001_add_mediasky_fields.php`
- `tests/Feature/FtthIntelligenceTest.php`

## Testovi

Dodani testovi pokrivaju:

- preview plana ne snima ništa u bazu
- potvrđeni plan kreira ODO ormariće
- potvrđeni plan povezuje kuće
- ODO nikad nema više od 12 kuća
- splitter_count se računa pravilno
- kuće bez koordinata se preskaču i prijavljuju
- kuća iz drugog projekta ne može biti povezana
- plan se rollbackuje ako sadrži neispravne podatke
- validacija pronalazi kuću bez ODO
- validacija pronalazi ODO bez ODF
- validacija pronalazi trasu bez kabla/mikrocijevi
- materijalni obračun sabira dužine po tipu kabla i mikrocijevi

## Završne provjere

Pokrenuto:

- `php artisan test`
- `npm run build`
- `php artisan view:cache`
- `php artisan route:list`
- `git diff --check`

## Ostaje za narednu fazu

- Povezati novi server-side preview direktno u map UI bez narušavanja postojećih CAD/GIS alata.
- Dodati vizuelni preview planiranih ODO ormarića na mapi preko novog API-ja.
- Dodati historiju zadnjeg plana ako se uvede posebna tabela za planove.
- Nakon browser QA stabilnosti razmotriti split `FtthController`.
