# Mini Pano

Laravel + SQLite ile basit bir pano demosu.

- İlk kurulum (repo klonlandıysa): `composer install`, `.env.example` dosyasını `.env` olarak kopyala, içine `SEED_USER_PASSWORD` yaz, `php artisan key:generate`, `php artisan migrate --seed`
- Başlat: `php artisan migrate`, sonra `php artisan serve` → http://127.0.0.1:8000
- Demo verisine sıfırla (her şeyi siler): `php artisan migrate:fresh --seed`
- Testler: `php artisan test`
- Kurallar: `config/automations.php`, çalıştıran kod: `app/Services/AutomationEngine.php`

## Kullanıcılar

Kullanıcılar `database/seeders/UserSeeder.php` içindedir. Hepsi `.env` içindeki `SEED_USER_PASSWORD` ile girer ve ilk girişte
şifresini değiştirmek zorundadır. Listeye kullanıcı ekledikten sonra (Docker'da önce `docker compose up -d --build`):

```sh
docker compose exec -u www-data app php artisan db:seed --class=UserSeeder --force
```

Şifresini unutan kullanıcıya geçici şifre (açık oturumları da kapatır):

```sh
docker compose exec -u www-data app php artisan user:reset-password ad.soyad@ardic.com
```

HTTPS olmadan internete açmayın.

## Docker

```sh
docker compose up -d --build      # http://localhost:3000
docker compose logs -f            # günlükler
docker compose stop               # durdur, `docker compose start` ile devam et
docker compose down               # container'ı kaldır (veri kalır; `down -v` veriyi SİLER)
```

Veri SQLite olarak `data` hacminde (`/data/database.sqlite`) durur. İlk açılışta kullanıcılar ve ana listeler eklenir.
Varsayılan olarak yerel ağdan erişilir (internete açmayın); port için `APP_PORT`, adres için `BIND_ADDRESS` ortam değişkeni.

Ofisten erişim için bir kez, yönetici PowerShell'de:

```powershell
New-NetFirewallRule -DisplayName "Mini Pano 3000" -Direction Inbound -Protocol TCP -LocalPort 3000 -Action Allow -Profile Private -RemoteAddress LocalSubnet
```

Yedek alma:

```sh
docker compose exec -u www-data app php -r '@unlink("/data/yedek.sqlite"); (new PDO("sqlite:/data/database.sqlite"))->exec("VACUUM INTO \"/data/yedek.sqlite\"");'
docker compose cp app:/data/yedek.sqlite ./yedek.sqlite
```
