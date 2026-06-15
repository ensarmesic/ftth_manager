# FTTH Manager

FTTH Manager je Laravel aplikacija za projektovanje i provjeru opticke FTTH mreze. Aplikacija kombinuje satelitsku mapu, ODF tacke, ODO/FTTH ormarice, kuce/prikljucke, trase, glavni rov, drop trase, materijale i projektnu provjeru u jednom radnom toku.

## Sta aplikacija radi

- Kreira projekte i cuva njihove ODF, ODO, kuce, trase i dodatne stavke.
- Prikazuje elemente na Leaflet mapi sa koordinatama iz baze.
- Cuva glavni rov kao crnu isprekidanu liniju bez oznaka na mapi.
- Cuva trase po tipu: backbone, primarni krak, sekundarni krak i drop.
- Automatski predlaze ODO ormarice za kuce i sekundarne krakove.
- Kreira drop trase od ODO ormarica do kuca.
- Racuna duzine trasa, mikrocijevi, kablove i osnovni materijal.
- Radi provjeru projekta kroz meni "Provjera projekta".
- Omogucava pregled i direktnu provjeru koordinata u bazi.

## Tehnologije

- PHP / Laravel
- SQLite baza za lokalni razvoj
- Blade + Vite frontend
- Tailwind CSS
- Leaflet mapa
- PHPUnit/Pest testovi

## Lokalno pokretanje

Instalacija zavisnosti:

```bash
composer install
npm install
```

Ako `.env` ne postoji:

```bash
copy .env.example .env
php artisan key:generate
```

SQLite baza:

```bash
New-Item database\database.sqlite -ItemType File
php artisan migrate
```

Backend servis:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Frontend/Vite servis:

```bash
npm run dev -- --host 127.0.0.1 --port 5173
```

Aplikacija se otvara na:

```text
http://127.0.0.1:8000
```

## Korisne komande

Pokretanje testova:

```bash
php artisan test
```

Build frontenda:

```bash
npm run build
```

Pokretanje migracija:

```bash
php artisan migrate
```

Reset baze za lokalni razvoj:

```bash
php artisan migrate:fresh
```

Ulazak u Laravel Tinker:

```bash
php artisan tinker
```

## Direktno citanje koordinata iz baze

Ako koristis SQLite bazu:

```bash
sqlite3 database/database.sqlite
```

Primjeri SQL upita:

```sql
select id, name, latitude, longitude from odfs;
select id, name, latitude, longitude from cabinets;
select id, label, latitude, longitude from houses;
select id, name, route_type, path from routes;
```

Ako zelis sve elemente sa koordinatama u jednom pregledu:

```sql
select 'ODF' as type, id, name as label, latitude, longitude from odfs
union all
select 'ODO' as type, id, name as label, latitude, longitude from cabinets
union all
select 'HOUSE' as type, id, label, latitude, longitude from houses;
```

## Pravila naziva i numeracije

Automatski nazivi su uskladjeni da se manje rucno popravlja:

- Krak iz ormarica nastavlja roditeljski krak tackom: ako je roditelj `Sekundarni krak 1.6`, novi krakovi su `Sekundarni krak 1.6.1`, `Sekundarni krak 1.6.2`, ...
- FTTH ormarici koriste oznaku kraka i redni broj: za `Sekundarni krak 1.6.1` naziv je `FTTH 1-6.1-1`, pa `FTTH 1-6.1-2`, ...
- Glavni rov: `Glavni rov 1`, `Glavni rov 2`, ...
- Primarni krak: `Primarni krak 1`, `Primarni krak 2`, ...
- Sekundarni krak: `Sekundarni krak 1`, `Sekundarni krak 2`, ...
- Drop trase: `Drop FTTH 1-2-3-K-001` ili najblizi naziv kuce/prikljucka.

Ako naziv vec postoji u projektu, sistem dodaje sufiks `-2`, `-3` i tako dalje.

## Glavni radni tok

1. Kreiraj ili odaberi projekat.
2. Postavi ODF na mapu.
3. Nacrtaj glavni rov.
4. Nacrtaj primarne/sekundarne krakove ili drop trase.
5. Dodaj kuce/prikljucke.
6. Pokreni automatski raspored ODO ormarica.
7. Sacuvaj plan na mapi.
8. Otvori "Provjera projekta" i popravi upozorenja.
9. Pregledaj materijale i izvjestaje.

## Bitne tabele

- `projects` - projekti
- `odfs` - ODF tacke i koordinate
- `cabinets` - ODO/FTTH ormarici i koordinate
- `houses` - kuce/prikljucci i koordinate
- `routes` - trase, tip trase, duzine i geometrija
- `network_branches` - primarni i sekundarni krakovi
- `project_appendix_items` - dodatne stavke na mapi, npr. saht i FI 130
- `map_drafts` - radna verzija plana prije konacnog snimanja

## Napomene

- Koordinate elemenata se cuvaju direktno u bazi u kolonama `latitude` i `longitude`.
- Geometrija trase se cuva u `routes.path`.
- Glavni rov ne nosi mikrocijev ni kabal; sluzi za trasu rova i obracun rova.
- Drop trase koriste `route_type = drop`, najcesce mikrocijev `10/8` i kabal 4 niti.
