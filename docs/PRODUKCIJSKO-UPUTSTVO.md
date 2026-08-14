# FTTH Manager — produkcijsko uputstvo

Revizija: 14. august 2026.

## Deployment checklist

- Napraviti provjerenu kopiju baze i `storage/app/private` podataka.
- Postaviti `APP_ENV=production`, `APP_DEBUG=false`, tačan `APP_URL`, `APP_VERSION`, `APP_DEPLOYED_AT`, `LOG_CHANNEL=daily` i razuman `SLOW_REQUEST_MS`.
- Web server usmjeriti isključivo na `public/`; `.env`, baza, logovi i backup direktorij ne smiju biti javno dostupni.
- Pokrenuti `composer install --no-dev --optimize-autoloader`, `npm run build` i `php artisan migrate --force`.
- Pokrenuti `php artisan config:cache`, `php artisan route:cache` i `php artisan view:cache`.
- Prije puštanja pokrenuti `composer check` te E2E komande iz README dokumenta nad staging okruženjem.
- Provjeriti prijavu i dozvole za administrator, projektant, teren i pregled uloge.

## Scheduler i backup

Operativni sistem mora svake minute izvršavati `php artisan schedule:run`. Aplikacija zakazuje:

- 02:30 — SQLite backup sa checksum provjerom i zadržavanjem 14 kopija;
- 03:00 — audit integriteta projekta;
- 03:15 — uklanjanje DXF cachea starijeg od 30 dana.

Izlaz se zapisuje u `storage/logs/scheduler.log`, a uspješan posao osvježava `storage/app/private/health/scheduler-heartbeat.json`. Neuspjeh se zapisuje u Laravel log. Backup se pravi atomskim SQLite `VACUUM INTO` postupkom, provjerava sa `quick_check` i SHA-256 checksumom. Privatni backup direktorij treba dodatno replicirati izvan aplikacijskog servera.

Najmanje jednom mjesečno izvršiti probni restore na izolovanoj kopiji: otvoriti backup kao novu SQLite bazu, pokrenuti `PRAGMA quick_check`, provjeriti poznati zapis i učitati ključne stranice aplikacije. Nikada ne testirati restore preko aktivne produkcijske baze.

## Monitoring

Administratorski endpoint `/sistem/health` provjerava bazu, scheduler heartbeat, starost i checksum posljednjeg backupa, verziju i deployment datum. Monitoring treba alarmirati ako endpoint nije uspješan, heartbeat kasni, backup je prestar ili disk ostaje bez prostora. Spori HTTP zahtjevi iznad `SLOW_REQUEST_MS` bilježe rutu, trajanje i korisnika u dnevni log.

## Incident i rollback

1. Zaustaviti upise ili staviti aplikaciju u maintenance režim.
2. Sačuvati postojeću bazu i logove prije bilo kakvog vraćanja.
3. Identifikovati zadnji backup čiji checksum i `quick_check` prolaze.
4. Restore prvo potvrditi u izolovanom direktoriju, zatim zamijeniti bazu prema odobrenoj proceduri hostinga.
5. Pokrenuti migracije samo ako verzija aplikacije to zahtijeva, očistiti/cacheirati konfiguraciju i provjeriti health endpoint.
6. Evidentirati vrijeme incidenta, izgubljeni period podataka, korištenu kopiju i rezultat validacije.

## Periodični audit

- Dnevno: health, scheduler log i svježina backupa.
- Sedmično: prostor na disku, aplikacijski error log i spori zahtjevi.
- Mjesečno: izolovani restore test i pregled korisničkih uloga.
- Prije svakog releasea: PHP testovi, Pint, JavaScript provjera, production build, svi E2E workflowi i responsive vizuelni audit.
