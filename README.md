# FTTH Manager

Aplikacija za planiranje i upravljanje FTTH (Fiber To The Home) mrežnom infrastrukturom. Omogućava crtanje trasa, dodavanje ODF stanica, FTTH ormarića i kuća na interaktivnoj mapi, uvoz geodetskih podataka, projektne snimke i izvoz izvještaja/DXF podataka.

## Tehnološki okvir

- **Laravel 13.25+** i **PHP 8.4+**
- **Vite 8.2+**, **Tailwind CSS 4.3+** i **Node.js 24+**
- **Leaflet**, **Proj4** i lokalno sinhronizovani map vendor asseti
- **SQLite** po zadanim postavkama; podržani su i MySQL/PostgreSQL

## Zahtjevi

### Razvoj (Development)

- **PHP** 8.4+
- **Node.js** 24+ (sa npm)
- **Composer** 2.0+
- **SQLite** 3.x (automatski u PHP-u)
- **Git** (za verzioniranje koda)

### Produkcija

- **PHP** 8.4+ sa extensionima: `mbstring`, `pdo_sqlite` (ili `pdo_mysql`/`pdo_postgresql`)
- **Node.js** 24+ (samo za build, ne trebaj runtime)
- **SQLite** (preporučeno) ili MySQL/PostgreSQL
- **Web server** (Apache, Nginx, itd.)

## Instalacija

### 1. Kloniranje repo-a

```bash
git clone https://github.com/ensarmesic/ftth_manager.git
cd ftth_manager
```

### 2. Automatski setup

```bash
composer setup
```

Komanda instalira PHP i Node zavisnosti, priprema `.env`, generiše aplikacijski ključ, izvršava migracije i pravi frontend build.

### 3. Ručni setup

```bash
# Kopiraj environment datoteku
cp .env.example .env

# Generiraj APP_KEY
php artisan key:generate

# Instaliraj PHP zavisnosti
composer install

# Instaliraj Node zavisnosti
npm install
```

### 4. Baza podataka

```bash
# Kreiraj SQLite datoteku
touch database/database.sqlite

# Pokreni migracije
php artisan migrate

# (Opciono) Učitaj test podatke
php artisan db:seed
```

### 5. Build frontend-a

```bash
# Production build (za deployment)
npm run build

# Ili development watch (za razvoj)
npm run dev
```

## Pokretanje

### Development

```bash
composer dev
```

Ova komanda zajedno pokreće Laravel na portu `8000` i Vite na portu `5173`. Alternativno ih možeš pokrenuti odvojeno sa `php artisan serve` i `npm run dev`.

Aplikacija će biti dostupna na `http://127.0.0.1:8000`

Koristi:

- **Korisničko ime**: `admin` (ili bilo koji korisnik iz `users` tablice)
- **Lozinka**: koristi lozinku postavljenu prilikom kreiranja računa. Lozinke su hashirane i ne mogu se pročitati iz baze ili `.env` datoteke.
- Ako baza još nema korisnika, pokreni `php artisan ftth:ensure-admin`; komanda će kreirati početni račun i jednokratno ispisati privremenu lozinku.

### Production

```bash
# Build assets
npm run build

# Setup Laravel
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Pokreni sa web serverom (Apache/Nginx)
```

Za produkciju postavi `APP_ENV=production`, `APP_DEBUG=false`, ispravan HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true` i konfiguraciju baze. Web server mora posluživati isključivo `public/` direktorij.

Produkcijski `.env` treba sadržavati i `APP_VERSION`, `APP_DEPLOYED_AT` te `LOG_CHANNEL=daily`. Laravel scheduler mora biti aktivan svake minute (`php artisan schedule:run`) jer u 02:30 pokreće `ftth:backup-database --keep=14`, a u 03:15 čisti zastarjeli DXF cache. Autentificirani administratorski health-check dostupan je na `/sistem/health`; vraća status baze, verziju, deployment i datum posljednjeg automatskog backupa. Zahtjevi sporiji od `SLOW_REQUEST_MS` zapisuju se u dnevni log.

Prije deploymenta pokreni:

```bash
composer check
npm run build
npm run test:e2e:pages
npm run test:e2e:map-workflow
npm run test:e2e:visual
npm run test:e2e:fiber
```

E2E komande zahtijevaju `E2E_USERNAME`, `E2E_PASSWORD` i pokrenutu aplikaciju. Vizuelni audit pravi snimke desktop, laptop, velikog monitora, telefona i tableta u `storage/logs/`.

## Korištenje

Kompletno uputstvo za rad sa svim dijelovima aplikacije nalazi se u
[korisničkom uputstvu za FTTH Manager](docs/KORISNICKO-UPUTSTVO-FTTH-MANAGER.md).

Detaljan standard za terensko snimanje koordinata, oznake elemenata, pripremu
TXT fajla i kontrolu uvoza nalazi se u
[uputstvu za geodetski TXT](docs/UPUTSTVO-ZA-GEODETSKI-TXT-FTTH.txt).

Deployment, scheduler, backup/restore, health-check i incidentne procedure nalaze se u
[produkcijskom uputstvu](docs/PRODUKCIJSKO-UPUTSTVO.md).

### Glavne stranice

- **Pregled** (`/`) - Dashboard sa statistikom po projektu
- **Mapa** (`/mapa`) - CAD editor za crtanje trase
- **Projekti** (`/projekti`) - Upravljanje projektima
- **ODF-ovi** (`/odf`) - Pregled ODF stanica
- **Ormarići** (`/ormarici`) - FTTH ormarići
- **Kuće** (`/kuce`) - Pregled kuća
- **Trase** (`/trase`) - Pregled svih trasa
- **Krakovi** (`/krakovi`) - Pregled mrežnih krakova
- **Izvještaji** (`/izvjestaji`) - Statistika i izvještaji
- **Provjera projekta** (`/provjera-projekta`) - Kontrola integriteta projekta
- **Postavke** (`/postavke`) - Postavke planiranja i projekta

### Mapa - Osnovni alati

1. **Select** (V tipka) - Odaberi element za pregled/editiranje
2. **Dodaj ODF** - Klikni na mapu za dodavanje ODF stanice
3. **Dodaj Ormarić (ODO)** - Dodaj FTTH ormarić
4. **Dodaj Kuću** - Dodaj lokalnu adresu/kuću
5. **Crtaj Trasu** - Klikni više puta za crtanje trase, double-click za završetak
6. **Mjerenje** - Izmjeri distancu (bez snimanja)
7. **Editiraj Geometriju** - Pomicanje čvorova postojeće trase
8. **Briši Element** - Obrisi odabranu trasu

### Mapa - Napredni features

- **Snap** - Automatsko privlačenje na bližnje ODF/Ormarić/Kuće (12px radijus)
- **Backup** - Spremi projekt kao JSON (`#snapshot-create`)
- **DXF Import** - Uvezi linije iz CAD datoteke
- **Interaktivno uređivanje** - Promjene se prikazuju odmah bez osvježavanja stranice

### Tipke za brže navigiranje

- **ESC** - Otkaži trenutnu operaciju
- **Backspace** - Ukloni zadnju točku pri crtanju
- **V** - Select alat
- **D** - Draw route alat
- **Double-click** - Završi crtanje trase

## Sigurnost

- ✅ CSRF zaštita na svim POST/PATCH/DELETE zahtjevima
- ✅ Validacija ulaza na frontend i backend
- ✅ Sigurnosni HTTP headeri i audit zapis izmjena
- ✅ SQLite baza (ili MySQL/PostgreSQL sa odgovarajućim dozvolama)
- ✅ Eksplicitne uloge i dozvole: administrator, projektant, teren i pregled
- ✅ TOTP dvofaktorska autentifikacija za administratorske račune (Postavke → Sigurnost računa)

## Testiranje

### Lokalno

```bash
# Formatiranje, JavaScript provjera i kompletan PHP test suite
composer check

# Samo PHP testovi
composer test

# E2E test (map smoke test)
E2E_USERNAME=admin E2E_PASSWORD='stvarna-lozinka' npm run test:e2e

# Sve stranice
E2E_USERNAME=admin E2E_PASSWORD='stvarna-lozinka' npm run test:e2e:pages

# Map editor workflow
E2E_USERNAME=admin E2E_PASSWORD='stvarna-lozinka' npm run test:e2e:map-workflow
```

E2E testovi koriste stvarni lokalni račun i ne mijenjaju njegovu lozinku. `test:e2e:pages` je read-only obilazak glavnih stranica.

### CI/CD

GitHub Actions automatski pokreće testove pri push-u na `main` branch. Vidi `.github/workflows/ci.yml` za detalje.

## Troubleshooting

### "CSRF token nije pronađen"

- **Uzrok**: Login stranica se nije potpuno učitala
- **Rješenje**: Osvježi stranicu (F5), ili čekaj malo prije nego što popuniš formu

### "Mapa se ne učitava"

- **Uzrok**: frontend build ili lokalni map vendor asseti nisu pripremljeni
- **Rješenje**: Pokreni `npm run build`; build automatski izvršava `vendor:sync`

### "Baza podataka je read-only"

- **Uzrok**: Datoteka `database.sqlite` nema write permissiona
- **Rješenje**: `chmod 666 database/database.sqlite` ili provjeri ownership

### "npm run dev ne radi"

- **Uzrok**: Node zavisnosti nisu instalirane
- **Rješenje**: `npm install && npm run dev`

### PHP error: "No application encryption key"

- **Uzrok**: APP_KEY nije generiran
- **Rješenje**: `php artisan key:generate`

## Arhitektura

### Frontend

- **Vite** - Build tool za CSS/JS
- **Tailwind CSS** - Utility-first CSS framework
- **Leaflet.js** - Mapa biblioteka
- **Playwright** - E2E testiranje

### Backend

- **Laravel 13** - PHP framework
- **PHP 8.4** - Verzija
- **SQLite/MySQL/PostgreSQL** - Baza podataka

### Struktura foldera

```
ftth_manager/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Middleware/
│   ├── Models/
│   ├── Services/
├── resources/
│   ├── views/
│   ├── css/
│   ├── js/
├── routes/
├── database/
│   ├── migrations/
│   ├── seeders/
├── tests/
├── public/
│   └── build/ (Vite compiled assets)
├── storage/
├── e2e/ (Playwright tests)
```

## Kontakt i Support

- **GitHub**: [github.com/ensarmesic/ftth_manager](https://github.com/ensarmesic/ftth_manager)
- **Issues**: [github.com/ensarmesic/ftth_manager/issues](https://github.com/ensarmesic/ftth_manager/issues)

## Licenca

Ovaj projekat je vlasnički softver (**proprietary / closed source**) i nije open source. Sva prava su zadržana. Bez prethodne pisane dozvole vlasnika nije dozvoljeno kopiranje, mijenjanje, distribucija, objavljivanje, sublicenciranje, prodaja niti korištenje izvornog koda ili njegovih dijelova izvan odobrene namjene.

Detaljni uslovi navedeni su u [LICENSE](LICENSE) datoteci.
