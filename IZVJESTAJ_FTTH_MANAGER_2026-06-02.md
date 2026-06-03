# FTTH Manager - pregled i popravke (2026-06-02)

## Zavrseno

- Pregledani aplikacijski PHP fajlovi, migracije, rute, Blade view fajlovi, frontend skripta i testovi.
- Uklonjeni zastarjeli zadaci iz izvjestaja za `2026-06-01`; zavrsene stavke su prebacene u historiju.
- Spriječeno povezivanje ODF-a, ODO ormarića, kuće, korisnika ili trase sa zapisom iz drugog projekta.
- Dodana zajednicka backend provjera `ensureBelongsToProject`.
- Spremanje kompletnog plana sa mape stavljeno je u DB transakciju.
- Spremanje predloženih ODO ormarića i pripadajućih drop trasa stavljeno je u DB transakciju.
- Popravljen JSON odgovor za AJAX validacijske greske na web rutama: sada se uredno vraća HTTP `422`.
- Dodani regresioni testovi za zabranu veza izmedju projekata i rollback plana kada jedan zapis nije ispravan.

## Uklonjeno kao nepotrebno

- Uklonjeni su samo zastarjeli zadaci iz starog izvjestaja.
- Nisu brisani aplikacijski fajlovi ni funkcionalnosti jer pregled nije pokazao da postoji kod koji je sigurno nepotreban.
- Privremeni dijagnosticki zapis napravljen tokom provjere je uklonjen iz baze.

## Izmijenjeni fajlovi

```text
app/Http/Controllers/FtthController.php
bootstrap/app.php
tests/Feature/MediaskyWorkflowTest.php
IZVJESTAJ_FTTH_MAP_EDITOR_2026-06-01.md
IZVJESTAJ_FTTH_MANAGER_2026-06-02.md
```

## Provjere

- `php artisan test` - proslo: 12 testova, 38 assertions
- `npm run build` - proslo
- PHP lint izmijenjenih PHP fajlova - proslo
- `git diff --check` - proslo bez whitespace gresaka

## Preostalo

- Rucno proci CAD alate u browseru nakon `Ctrl+F5`.
- Prosiriti browser testiranje za snap na svim tipovima elemenata.
