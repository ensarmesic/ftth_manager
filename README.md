# FTTH Manager

Aplikacija za planiranje i upravljanje FTTH (Fiber To The Home) mrežnom infrastrukturom. Omogućava crtanje trase, dodavanje ODF stanica, FTTH ormarića i kuća na interaktivnoj mapi, sa mogućnošću izvoza u DXF i upravljanja projektima.

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

### 2. Setup okruženja

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

### 3. Baza podataka

```bash
# Kreiraj SQLite datoteku
touch database/database.sqlite

# Pokreni migracije
php artisan migrate

# (Opciono) Učitaj test podatke
php artisan db:seed
```

### 4. Build frontend-a

```bash
# Production build (za deployment)
npm run build

# Ili development watch (za razvoj)
npm run dev
```

## Pokretanje

### Development

```bash
# Terminal 1 - Laravel dev server
php artisan serve

# Terminal 2 - Vite build server (za CSS/JS live reload)
npm run dev
```

Aplikacija će biti dostupna na `http://127.0.0.1:8000`

Koristi:

- **Korisničko ime**: `admin` (ili bilo koji korisnik iz `users` tablice)
- **Lozinka**: vidi `.env` ili `php artisan tinker` -> `User::first()->password_changed_at`

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
# Vidi DEPLOYMENT.md za detaljne instrukcije
```

## Korištenje

### Glavne stranice

- **Pregled** (`/`) - Dashboard sa statistikom po projektu
- **Mapa** (`/mapa`) - CAD editor za crtanje trase
- **Projekti** (`/projekti`) - Upravljanje projektima
- **ODF-ovi** (`/odf`) - Pregled ODF stanica
- **Ormarići** (`/ormarici`) - FTTH ormarići
- **Kuće** (`/kuce`) - Pregled kuća
- **Trase** (`/trase`) - Pregled svih trase
- **Izvještaji** (`/izvjestaji`) - Statistika i izvještaji

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
- **Real-time** - Sve promjene se vidljive odmah bez refresh-a

### Tipke za brže navigiranje

- **ESC** - Otkaži trenutnu operaciju
- **Backspace** - Ukloni zadnju točku pri crtanju
- **V** - Select alat
- **D** - Draw route alat
- **Double-click** - Završi crtanje trase

## Sigurnost

- ✅ CSRF zaštita na svim POST/PATCH/DELETE zahtjevima
- ✅ Validacija ulaza na frontend i backend
- ✅ SQLite baza (ili MySQL/PostgreSQL sa proper permissions)
- ⚠️ Bez role/permission sistema (za mali tim 2-3 osobe)
- ⚠️ Bez 2FA/MFA (preporučuje se ako je dostupno na serveru)

## Testiranje

### Lokalno

```bash
# PHP testovi
php artisan test

# E2E test (map smoke test)
E2E_USERNAME=admin E2E_PASSWORD=password npm run test:e2e

# Sve stranice
E2E_USERNAME=admin E2E_PASSWORD=password npm run test:e2e:pages

# Map editor workflow
E2E_USERNAME=admin E2E_PASSWORD=password npm run test:e2e:map-workflow
```

### CI/CD

GitHub Actions automatski pokreće testove pri push-u na `main` branch. Vidi `.github/workflows/ci.yml` za detalje.

## Troubleshooting

### "CSRF token nije pronađen"

- **Uzrok**: Login stranica se nije potpuno učitala
- **Rješenje**: Osvježi stranicu (F5), ili čekaj malo prije nego što popuniš formu

### "Mapa se ne učitava"

- **Uzrok**: Leaflet CDN nije dostupan ili nema interneta
- **Rješenje**: Provjeri internet konekciju, ili build sa lokalnim Leaflet-om

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

## Deployment

Vidi [DEPLOYMENT.md](DEPLOYMENT.md) za detaljne instrukcije za produkciju.

## Kontakt i Support

- **GitHub**: [github.com/ensarmesic/ftth_manager](https://github.com/ensarmesic/ftth_manager)
- **Issues**: [github.com/ensarmesic/ftth_manager/issues](https://github.com/ensarmesic/ftth_manager/issues)

## Licenca

Licensed under the MIT License. Vidi `LICENSE` datoteku.
