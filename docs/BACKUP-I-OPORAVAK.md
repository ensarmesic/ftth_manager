# Backup i oporavak

## Lokalna kopija

```bash
php artisan ftth:backup-database --keep=14
php artisan ftth:verify-backup
```

Prva komanda pravi konzistentnu SQLite kopiju pomoću `VACUUM INTO`, izvršava `PRAGMA quick_check` i zapisuje SHA-256 checksum. Druga komanda ponovo provjerava najnoviju kopiju i probno je otvara kao zasebnu bazu.

## Udaljena enkriptovana kopija

U produkcijskom `.env` postavi:

```dotenv
FTTH_BACKUP_DISK=s3
FTTH_BACKUP_REMOTE_DIRECTORY=ftth-backups
FTTH_BACKUP_ENCRYPTION_KEY=dug-jedinstven-tajni-kljuc-od-najmanje-32-znaka
```

Disk mora postojati u `config/filesystems.php` (S3, MinIO ili lokalno montirani NAS). Udaljena kopija koristi XChaCha20-Poly1305 streaming enkripciju i dobija vlastiti checksum. Ključ čuvaj odvojeno od servera; bez njega se kopija ne može vratiti.

Nakon preuzimanja `.enc` datoteke i pripadajućeg `.sha256` fajla provjeri je naredbom:

```bash
php artisan ftth:verify-backup /sigurna/putanja/database-backup.sqlite.enc
```

## Potpuni oporavak

1. Zaustavi aplikaciju ili uključi maintenance režim: `php artisan down`.
2. Sačuvaj trenutnu bazu pod drugim nazivom.
3. Provjeri kopiju komandom `ftth:verify-backup`. Enkriptovanu kopiju provjeri i izdvoji sa `php artisan ftth:verify-backup backup.sqlite.enc --output=/sigurna/putanja/restored.sqlite`.
4. Lokalnu provjerenu `.sqlite` kopiju postavi na lokaciju iz `DB_DATABASE`.
5. Provjeri vlasništvo i write dozvole web procesa.
6. Pokreni `php artisan migrate --force` i `php artisan up`.
7. Otvori `/sistem/health` i provjeri projekat, mapu i zadnji audit zapis.

Enkriptovana kopija se tokom obične provjere dekriptuje samo u privremenu datoteku koja se automatski briše. Opcija `--output` čuva provjerenu SQLite datoteku za stvarni restore. Nikada ne prepisuj aktivnu bazu bez prethodne kopije.
