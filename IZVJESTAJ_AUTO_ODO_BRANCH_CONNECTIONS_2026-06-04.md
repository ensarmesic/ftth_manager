# IZVJESTAJ AUTO ODO BRANCH CONNECTIONS 2026-06-04

## Odredjivanje krakova

Auto ODO planiranje prvo ucitava trase projekta koje imaju geometriju i tip `main`, `glavna`, `secondary`, `sekundarna`, `distribution` ili `distribuciona`. Svaka takva trasa predstavlja krak. Ako naziv ili tip trase sadrzi broj, taj broj se koristi kao `branch_index`; inace se indeksi dodjeljuju redom.

Ako projekat nema definisane krakove, planiranje prelazi na fallback po geografskoj blizini i vraca warning:

`Nema definisanih krakova. Planiranje koristi samo geografsku blizinu i rezultat moze biti netacan.`

## Dodjela kuca krakovima

Za svaku kucu sa koordinatama racuna se najbliza projekcija na svaku trasu/krak. Kuca se dodjeljuje najblizem kraku samo ako je udaljenost manja ili jednaka `max_branch_distance_m`, default 60 m. Kuce van tog limita idu u `unassigned_houses` i ulaze u score penalty.

## Chainage

`chainage_m` je udaljenost od pocetka trase do projekcije kuce na tu trasu. Racuna se segment po segment kroz geometriju trase, a kuce unutar kraka se sortiraju po tom podatku.

## Formiranje ODO grupa

Grupe se formiraju odvojeno po svakom kraku. Kuce iz razlicitih krakova se ne mijesaju. Nova grupa se otvara kada je dostignuto 12 kuca, kada je razmak po `chainage_m` veci od `max_gap_m`, ili kada bi nova kuca bila predaleko od predlozenog ODO-a.

Svaka grupa u preview-u ima `branch_id`, `route_id`, `branch_index`, `group_index`, broj kuca, splittere, utilizaciju, prosjecnu i maksimalnu udaljenost, warninge i score.

## Imenovanje ODO ormarica

Preview naziv je `FTTH {branch_index}-{group_index} PRIJEDLOG`. Nakon potvrde snima se stvarni naziv bez sufiksa, npr. `FTTH 1-1`, `FTTH 1-2`, `FTTH 2-1`.

## Postavljanje ODO na trasu

Za svaku grupu prvo se pronalazi medoid kuca. ODO lokacija nije centroid, nego projekcija medoida na pripadajucu trasu/krak.

## Povezivanje kuca na ODO

Confirm radi u DB transakciji. Kreira ODO, povezuje samo kuce iz njegove grupe i odbija plan ako grupa sadrzi vise od 12 kuca, ako se kuca ponavlja, ako je iz drugog projekta ili ako se mijesaju krakovi. `splitter_count` se racuna automatski: 1-4 kuce = 1, 5-8 = 2, 9-12 = 3.

## Povezivanje ODO na ODF

Preview za svaki ODO pronalazi najblizi ODF iz istog projekta i racuna udaljenost. Ako ODF ne postoji, preview je dozvoljen uz warning. Confirm odbija ODF iz drugog projekta.

## Drop preview

Preview prikazuje drop veze ODO -> kuca kao linije, ali ih ne snima. Drop trase se snimaju samo ako je `create_drop_routes=true`; tada moraju biti poznati `cable_type` i `microduct_type`, inace se plan odbija i transakcija se rollbackuje.

## Preview na mapi

`public/js/ftth-map.js` koristi backend preview endpoint. Preview slojevi su odvojeni: `layers.autoPlanMarkers`, `layers.autoPlanLines`, `layers.autoPlanHighlights`. Novi preview cisti stare preview slojeve. Panel prikazuje analizu plana, score, ODO grupe, warninge i nedodijeljene kuce.

## Blokiranje Auto ODO

Dodan je state `autoPlanning` sa `loading`, `previewActive` i `currentPreview`. Ako je request u toku, novi klik se ignorise. Prije planiranja prekida se crtanje, editovanje ili mjerenje uz potvrdu korisnika. Dodani su loading tekst, timeout i error handling.

## CAD-like kontrole

Command bar sada prikazuje `Command`, aktivni alat, instrukciju, broj tacaka, duzinu, snap status i ORTHO status. Dodani su keyboard shortcuti: `S`, `L`, `M`, `E`, `Esc`, `Enter`, `Backspace`, `Delete` i `O`. Shortcuti se ne aktiviraju kada je fokus u inputu, selectu, textareai ili modalu.

Dodane su snap opcije za ODF, ODO, kuce i cvorove trase, te ORTHO mode za poravnanje nove tacke horizontalno ili vertikalno u odnosu na prethodnu.

## Testovi

Dodani i prosireni su testovi za:

- kucama sa razlicitih krakova nije dozvoljeno mijesanje
- dodjelu najblizem kraku samo unutar `max_branch_distance_m`
- udaljene kuce idu u `unassigned`
- sortiranje po `chainage_m`
- veliki razmak po kraku pravi novu grupu
- ODO se postavlja na trasu
- imena idu po formatu `FTTH 1-1`, `FTTH 1-2`, `FTTH 2-1`
- grupa nikad nema vise od 12 kuca
- splitter_count se ispravno racuna
- preview ne snima podatke
- confirm snima plan u transakciji
- rollback radi na neispravnom planu
- ODO se ne povezuje na ODF iz drugog projekta
- kuca se ne povezuje na ODO drugog kraka
- fallback radi bez trasa i vraca warning
- score kaznjava nedodijeljene kuce

## Zavrsne provjere

Pokrenuto i proslo:

- `node --check public/js/ftth-map.js`
- `php artisan test`
- `npm run build`
- `php artisan view:cache`
- `php artisan route:list`
- `git diff --check`
