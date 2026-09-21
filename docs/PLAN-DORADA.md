# Plan dorada FTTH Managera

Ovaj dokument je radna checklista. Projektne dozvole nisu dio plana: mali tim namjerno ima zajednički pristup svim projektima.

## Faza 1 — korisnici i pristup

- [x] Administratorski pregled korisnika
- [x] Kreiranje korisnika i dodjela uloge
- [x] Aktivacija i deaktivacija korisnika
- [x] Administratorski reset lozinke
- [x] Zaštita vlastitog računa i posljednjeg administratora
- [x] Evidencija posljednje prijave
- [x] Samostalni oporavak zaboravljene lozinke
- [x] Pregled i prekid aktivnih sesija

## Faza 2 — audit i sigurnost

- [x] Filteri i paginacija audit zapisa
- [x] Prikaz objekta i projekta na koji se promjena odnosi
- [x] Evidencija starih i novih vrijednosti za važne promjene
- [x] Izvoz audit zapisa u CSV
- [x] Evidencija prijava, odjava i neuspjelih sigurnosnih događaja
- [x] Nonce zaštita inline skripti i CSP `script-src` bez `unsafe-inline`

## Faza 3 — backup i oporavak

- [x] Konfigurabilna udaljena destinacija (S3/MinIO/NAS)
- [x] Enkripcija udaljenih kopija
- [x] Automatska provjera da se backup može otvoriti i obnoviti
- [x] Obavijest kada backup ili scheduler zakaže
- [x] Dokumentovan i testiran postupak potpunog oporavka

## Faza 4 — performanse i veliki projekti

- [x] Server-side lookup API za velike liste projekata i mrežnih elemenata
- [x] Kratkotrajni cache dashboard agregata sa invalidacijom nakon izmjena
- [x] Mjerenje broja elemenata, veličine payload-a i vremena izgradnje map podataka
- [x] Viewport `bbox` API za ograničeno učitavanje velikih map slojeva
- [x] Queue background poslovi sa statusom i rezultatom za DXF, PDF, velike importe i auto-planiranje
- [~] PostgreSQL/PostGIS profil — odgođeno po dogovoru; trenutna SQLite baza ostaje

## Faza 5 — kvalitet koda i CI

- [x] ESLint i Prettier za JavaScript
- [x] Unit testovi osnovnih map pravila i prikaza stanja
- [x] Razdvojene DXF odgovornosti u concerne, background obrada u job/controller i map pravila u testabilni core modul
- [~] Inline Blade skripte zaštićene nonce CSP-om; potpuno modularizovanje legacy fiber/map renderera odgođeno kao refaktor bez promjene funkcionalnosti
- [x] Uključiti role i fiber E2E testove u obavezni CI
- [x] Uvesti ograničeni CI visual-regression audit za dashboard i mapu
- [x] Napraviti `composer check` prenosivim na Windows i Linux

## Faza 6 — terenski i projektni workflow

- [x] Statusi nacrt → kontrola → odobreno → izvedeno → as-built
- [x] Radni zadaci i odgovorna osoba
- [x] Komentari i prilozi na mrežnim elementima
- [x] Poređenje planiranog i izvedenog stanja
- [x] Mobilni/PWA režim sa offline redom terenskih unosa i automatskom sinhronizacijom
- [x] Univerzalna pretraga po adresi i šifri mrežnog elementa
- [x] Pretraga i centriranje mape po koordinati
- [x] Troškovnik sa cijenama i revizijama
