# Backup i oporavak

## Lokalna kopija

```bash
php artisan ftth:backup-database --keep=14
php artisan ftth:verify-backup
```

Prva komanda pravi konzistentan MySQL dump (`mysqldump --single-transaction`, bez zaključavanja tabela), provjerava da je dump potpun i zapisuje SHA-256 checksum. Druga komanda ponovo provjerava checksum i potpunost najnovijeg dumpa. Ako `mysqldump` nije u PATH-u, putanju postavi u `.env` kao `FTTH_MYSQLDUMP_PATH`.

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
php artisan ftth:verify-backup /sigurna/putanja/database-backup.sql.enc
```

## Potpuni oporavak

1. Zaustavi aplikaciju ili uključi maintenance režim: `php artisan down`.
2. Napravi svjež backup trenutnog stanja: `php artisan ftth:backup-database`.
3. Provjeri kopiju komandom `ftth:verify-backup`. Enkriptovanu kopiju provjeri i izdvoji sa `php artisan ftth:verify-backup backup.sql.enc --output=/sigurna/putanja/restored.sql`.
4. Vrati provjereni dump u bazu iz `DB_DATABASE`: `mysql -u ftth -p ftth_manager < restored.sql`.
5. Pokreni `php artisan migrate --force` i `php artisan up`.
6. Otvori `/sistem/health` i provjeri projekat, mapu i zadnji audit zapis.

Enkriptovana kopija se tokom obične provjere dekriptuje samo u privremenu datoteku koja se automatski briše. Opcija `--output` čuva provjereni SQL dump za stvarni restore. Nikada ne prepisuj aktivnu bazu bez prethodne kopije.
