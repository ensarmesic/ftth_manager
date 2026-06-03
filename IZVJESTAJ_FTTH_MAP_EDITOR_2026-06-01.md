# FTTH Manager - izvjestaj rada (2026-06-01)

## Zavrseno

- Ociscena baza podataka (`php artisan migrate:fresh --force`): uklonjeni su testni i demo podaci.
- Uklonjeni hardkodirani demo redovi iz dashboard tabela.
- Dashboard prikazuje samo stvarne podatke iz baze i nule kada podataka nema.
- Satelitska Esri podloga je podrazumijevana; OSM je dostupan kao opcija.
- Mapa je organizovana u slojeve: ODF, ODO, kuće, trase, outline trase, cvorovi, crtanje, mjerenje i snap.
- Dodan `MapEditor` objekat u `public/js/ftth-map.js`.
- Aktivni alat mijenja stil, cursor i statusnu poruku.
- Dodani alati: select, dodaj ODF, dodaj ODO, dodaj kuću, nacrtaj trasu, mjerenje, uredi trasu, obriši tačku i obriši element.
- Dodan status bar sa aktivnim alatom, brojem tacaka, duzinom i snap stanjem.
- Crtanje trase radi tačku po tačku, uz privremeni isprekidani segment do kursora.
- `Double click` završava crtanje; `ESC` prekida operaciju; `Backspace` uklanja zadnju tačku.
- Backend racuna stvarnu duzinu trase iz koordinata pri prvom snimanju.
- Nova trasa se odmah prikazuje na mapi, u tabeli i statistikama bez reload-a.
- Mjerenje radi nezavisno od baze i ne snima podatke.
- Edit geometrije trase prikazuje draggable cvorove.
- Klik na segment trase u edit modu dodaje novu lomnu tačku.
- Brisanje tačke ne dozvoljava da trasa ostane sa manje od dvije tačke.
- Izmjene geometrije se snimaju tek klikom na `Sačuvaj izmjene`.
- Dodan PATCH endpoint `routes.geometry.update`; backend ponovo racuna duzinu nakon editovanja.
- Brisanje trase trazi potvrdu i podrzava JSON odgovor.
- Dodan osnovni snap radijusa 12 px na ODF, ODO, kuće i postojece cvorove trase.
- Dodani toast prikazi za uspjeh i server greske.
- Dodano upozorenje prije napustanja stranice kada postoje nespremljene izmjene.
- Popravljen nestanak Leaflet mape: promjena alata više ne briše obaveznu `leaflet-container` klasu.
- Dodan modal za kreiranje trase sa nazivom, tipom, ODF/ODO vezom, mikrocijevi, kablom, brojem tacaka i automatskom duzinom.
- Isti modal radi u edit rezimu za podatke postojece trase.
- Dodan property panel za odabranu trasu: `Uredi podatke`, `Uredi geometriju`, `Obriši`.
- Dodan property panel za ODF/ODO/kuću: `Pomjeri`.
- Dodano sigurno pomjeranje markera: marker je draggable tek nakon akcije, a nova pozicija ide serveru tek klikom na `Sačuvaj poziciju`; `Poništi` vraća staru poziciju.
- `ESC` zatvara modal i prekida crtanje, mjerenje, editovanje ili pomjeranje.

## Dodana ruta

```text
PATCH /trase/{id}/geometrija
PATCH /trase/{id}
```

Naziv rute: `routes.geometry.update`.

## Provjere

- `node --check public/js/ftth-map.js` - proslo
- `php artisan view:cache` - proslo
- `npm run build` - proslo
- `php artisan test` - proslo: 9 testova, 27 assertions
- `php artisan route:list --path=trase` - PATCH endpoint je registrovan

## Preostalo nakon 2026-06-01

- Rucno proci sve CAD alate u browseru i ispeglati UX detalje.
- Prosiriti browser testiranje za snap na svim tipovima elemenata.
- Rucno provjeriti sve alate u browseru nakon `Ctrl+F5`; terminalske provjere ne mogu zamijeniti interakciju misem.

## Naknadno zavrseno

- Dodano brisanje ODF/ODO/kuće iz property panela uz potvrdu.
- Dodana napomena trase u bazu, API i modal.
- Doradjen select/highlight stil odabranih markera i trase.
- Osvjezavanje tabele i statistike nakon brisanja ili editovanja trase radi bez reload-a.

Detalji naknadnog pregleda i sigurnosnih popravki nalaze se u `IZVJESTAJ_FTTH_MANAGER_2026-06-02.md`.
