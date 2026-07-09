# Instalacija i pokretanje

## Sistemski zahtjevi

| Komponenta | Minimum | Preporuka |
|---|---|---|
| PHP | 8.3+ | 8.3+ |
| PHP ekstenzije | `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`, `fileinfo` | — |
| Node.js | 18+ | 20+ |
| Disk | 200 MB | 500 MB |
| RAM | 512 MB | 1 GB+ |

Za velike DXF fajlove potrebno je `memory_limit = 1G` u PHP konfiguraciji.

Pozadinski slojevi na mapi podržavaju samo DXF. DWG fajlovi nisu podržani — sačuvaj ih kao DXF (Save As → DXF) iz AutoCAD-a ili FreeCAD-a prije uvoza.

---

## Prva instalacija

```bash
git clone <repo-url> ftth_manager
cd ftth_manager
composer run setup
```

`composer run setup` automatski:
1. Instalira PHP zavisnosti (`composer install`)
2. Kreira `.env` iz `.env.example` i generiše app key
3. Pokreće sve database migracije (kreira `database/database.sqlite`)
4. Instalira JS zavisnosti i builda frontend (`npm run build`)

---

## Pokretanje razvojnog servera

```bash
composer run dev
```

Pokreće paralelno:
- Laravel app na `http://127.0.0.1:8000`
- Vite HMR na `http://127.0.0.1:5173`

---

## Produkcijski build

```bash
npm run build
php artisan optimize
```

Web server treba da pokazuje na `public/` direktorij.

---

## Konfiguracija (.env)

```env
APP_NAME="FTTH Manager"
APP_ENV=local          # production za produkciju
APP_DEBUG=true         # false u produkciji
APP_URL=http://localhost

DB_CONNECTION=sqlite   # SQLite — nema host/port
```

Za povećanje PHP memory limita (potrebno za veće DXF fajlove):
```env
PHP_MEMORY_LIMIT=1G
```

---

## Korisne Artisan komande

```bash
# Migracije
php artisan migrate
php artisan migrate:fresh --force   # resetuje bazu (briše sve podatke!)

# Cache
php artisan config:clear
php artisan view:clear
php artisan optimize

# Testovi
php artisan test
php artisan test --filter FtthIntelligenceTest
php artisan test --filter MediaskyWorkflowTest

# Laravel log
php artisan pail   # real-time log stream
```

---

## Lint

```bash
./vendor/bin/pint          # provjeri i popravi PHP stil
./vendor/bin/pint --test   # samo provjeri, ne mijenjaj
```
