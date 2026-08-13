# FTTH Manager - Production Deployment Guide

Detaljne instrukcije za deployment FTTH Manager-a na produkcijski server.

## Pre-deployment Checklist

- [ ] Provjera svih testova lokalno (`php artisan test`, `npm run test:e2e`)
- [ ] Build frontend-a (`npm run build`)
- [ ] Environment datoteka (`.env.production` ili `.env`)
- [ ] SSL certifikat (za HTTPS)
- [ ] Database backup plan
- [ ] Backup storage (za projekte)
- [ ] Email konfiguracija (ako trebala)

## Koraci za Deployment

### 1. Server Setup

#### Zahtjevi na serveru

```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install -y php8.4 php8.4-cli php8.4-fpm php8.4-sqlite3 \
    php8.4-mbstring php8.4-curl php8.4-xml php8.4-json composer git nodejs npm

# CentOS/RHEL
sudo yum install -y php84 php84-php-cli php84-php-fpm php84-php-sqlite \
    php84-php-mbstring php84-php-curl php84-php-xml composer git nodejs npm

# macOS (za testing na local Mac)
brew install php@8.4 composer node
```

#### Web Server Setup (Nginx - preporučeno)

```nginx
# /etc/nginx/sites-available/ftth-manager
server {
    listen 80;
    listen [::]:80;
    server_name your-domain.com www.your-domain.com;

    # Redirect HTTP to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name your-domain.com www.your-domain.com;

    # SSL certificates (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    root /var/www/ftth_manager/public;
    index index.php;

    # Logging
    access_log /var/log/nginx/ftth_manager-access.log;
    error_log /var/log/nginx/ftth_manager-error.log;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css text/javascript application/json application/javascript;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Deny access to .env and other sensitive files
    location ~ /\. {
        deny all;
    }

    location ~ /\.(env|git|gitignore|lock|md|yml|yaml|toml)$ {
        deny all;
    }
}
```

Enable Nginx site:

```bash
sudo ln -s /etc/nginx/sites-available/ftth-manager /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

#### SSL Certificate (Let's Encrypt)

```bash
sudo apt-get install certbot python3-certbot-nginx
sudo certbot certonly --nginx -d your-domain.com -d www.your-domain.com
sudo certbot renew --dry-run  # Test auto-renewal
```

### 2. Clone Repository

```bash
# Navigate to web root
cd /var/www
sudo git clone https://github.com/ensarmesic/ftth_manager.git
cd ftth_manager

# Set proper permissions
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
sudo chmod -R 775 storage bootstrap/cache
```

### 3. Environment Setup

```bash
# Copy production environment
sudo cp .env.example .env.production
sudo nano .env.production  # Edit with production values
```

Production `.env.production` example:

```env
APP_NAME="FTTH Manager"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_KEY=<run: php artisan key:generate --show>
APP_TIMEZONE=Europe/Sarajevo

# Database - SQLite (preporučeno za male projekte)
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/ftth_manager/database/production.sqlite

# Ili MySQL/PostgreSQL
# DB_CONNECTION=mysql
# DB_HOST=localhost
# DB_PORT=3306
# DB_DATABASE=ftth_manager_prod
# DB_USERNAME=ftth_user
# DB_PASSWORD=<strong-password>

# Session (koristiš database ili file)
SESSION_DRIVER=database
SESSION_LIFETIME=1440

# Cache
CACHE_STORE=database

# Queue (ako trebaj background jobs)
QUEUE_CONNECTION=database

# Email (ako trebaj notifikacije)
# MAIL_MAILER=smtp
# MAIL_HOST=smtp.example.com
# MAIL_PORT=587
# MAIL_USERNAME=your-email@example.com
# MAIL_PASSWORD=<app-password>
# MAIL_ENCRYPTION=tls

# Logging
LOG_CHANNEL=daily
LOG_LEVEL=error
```

### 4. Dependency Installation

```bash
# PHP dependencies
cd /var/www/ftth_manager
composer install --no-dev --optimize-autoloader

# Node dependencies
npm install --omit=dev

# Build frontend
npm run build
```

### 5. Database Setup

```bash
# For SQLite
touch database/production.sqlite
chmod 666 database/production.sqlite

# Run migrations
php artisan migrate --force --env=production

# (Opciono) Seed test data
php artisan db:seed --force --env=production

# Create first user
php artisan tinker
>>> User::factory()->create(['name' => 'Admin', 'username' => 'admin', 'email' => 'admin@example.com', 'password' => bcrypt('secure-password-here')])
>>> exit
```

### 6. Cache Configuration

```bash
php artisan config:cache --env=production
php artisan route:cache --env=production
php artisan view:cache --env=production
php artisan event:cache --env=production
```

### 7. Queue Worker (ako trebaj background jobs)

```bash
# Create systemd service file
sudo nano /etc/systemd/system/ftth-queue-worker.service
```

```ini
[Unit]
Description=FTTH Manager Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/ftth_manager
ExecStart=/usr/bin/php artisan queue:work --queue=default
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable ftth-queue-worker
sudo systemctl start ftth-queue-worker
```

### 8. Cron Job (za Laravel scheduling)

```bash
# Add to crontab
sudo crontab -e -u www-data

# Add this line:
* * * * * cd /var/www/ftth_manager && php artisan schedule:run >> /dev/null 2>&1
```

### 9. Backups

#### Automated Database Backups

```bash
#!/bin/bash
# /usr/local/bin/backup-ftth.sh

BACKUP_DIR="/var/backups/ftth_manager"
DB_FILE="/var/www/ftth_manager/database/production.sqlite"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)

mkdir -p $BACKUP_DIR
cp $DB_FILE $BACKUP_DIR/database_$TIMESTAMP.sqlite
gzip $BACKUP_DIR/database_$TIMESTAMP.sqlite

# Keep only last 7 days
find $BACKUP_DIR -name "database_*.sqlite.gz" -mtime +7 -delete

# Sync to remote storage (optional)
# aws s3 sync $BACKUP_DIR s3://your-bucket/backups/
```

Add to crontab:

```bash
# Daily backup at 3 AM
0 3 * * * /usr/local/bin/backup-ftth.sh
```

#### Manual Backup

```bash
# Backup database
sqlite3 /var/www/ftth_manager/database/production.sqlite ".backup '/var/backups/production_backup.sqlite'"

# Backup entire app directory
tar -czf /var/backups/ftth_manager_$(date +%Y%m%d).tar.gz /var/www/ftth_manager/
```

### 10. Monitoring

#### Log Rotation

```bash
# /etc/logrotate.d/ftth_manager

/var/log/nginx/ftth_manager-*.log
/var/www/ftth_manager/storage/logs/*.log
{
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
```

#### Health Check

```bash
# Simple cron-based health check
0 * * * * curl -f https://your-domain.com/health || mail -s "FTTH Manager Down" admin@example.com
```

## Post-Deployment Verification

```bash
# Check Laravel logs
tail -f /var/www/ftth_manager/storage/logs/laravel.log

# Test database connection
php artisan db:connection:test

# Check queue status
php artisan queue:failed

# Verify permissions
ls -la /var/www/ftth_manager/storage/

# Test email (if configured)
php artisan tinker
>>> Mail::raw('Test', function($message) { $message->to('test@example.com'); });
```

## Updating Production

```bash
# Navigate to app directory
cd /var/www/ftth_manager

# Pull latest code
sudo git pull origin main

# Install updates
composer install --no-dev --optimize-autoloader
npm install --omit=dev
npm run build

# Run migrations
php artisan migrate --force

# Cache refresh
php artisan config:cache
php artisan route:cache

# (Optional) Restart queue worker
sudo systemctl restart ftth-queue-worker
```

## Security Best Practices

- ✅ HTTPS only (redirect HTTP to HTTPS)
- ✅ Keep `.env` file outside web root
- ✅ Regular database backups (automated daily)
- ✅ Monitor error logs daily
- ✅ Update PHP/Laravel/Node regularly
- ✅ Use strong passwords for database
- ✅ Disable directory listing (`Options -Indexes`)
- ✅ Set `APP_DEBUG=false` in production
- ✅ Regular security updates (`composer update`)

## Troubleshooting

### 500 Internal Server Error

```bash
# Check error logs
tail -50 /var/www/ftth_manager/storage/logs/laravel.log
tail -50 /var/log/nginx/ftth_manager-error.log
```

### Database locked (SQLite)

```bash
# Check for locks
lsof | grep production.sqlite

# Kill process if needed
kill -9 <PID>

# Or restore from backup
cp /var/backups/database_backup.sqlite database/production.sqlite
```

### File permissions issue

```bash
sudo chown -R www-data:www-data /var/www/ftth_manager
sudo chmod -R 755 /var/www/ftth_manager
sudo chmod -R 775 /var/www/ftth_manager/storage
```

### Out of memory

```bash
# Check PHP-FPM memory limit in /etc/php/8.4/fpm/php.ini
memory_limit = 256M  # Increase if needed
```

## Rolling Back

```bash
# If deployment breaks, rollback code
cd /var/www/ftth_manager
git revert HEAD --no-edit
git push origin main

# Restore database from backup
cp /var/backups/database_<timestamp>.sqlite database/production.sqlite
```

## Support

For issues, check:

- `/var/www/ftth_manager/storage/logs/laravel.log`
- `/var/log/nginx/ftth_manager-error.log`
- `/var/log/php8.4-fpm.log` (if using PHP-FPM)

Report bugs: [github.com/ensarmesic/ftth_manager/issues](https://github.com/ensarmesic/ftth_manager/issues)
