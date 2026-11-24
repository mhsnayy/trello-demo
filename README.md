# Mini Pano

Laravel + SQLite ile basit bir pano demosu.

- İlk kurulum (repo klonlandıysa): `composer install`, `.env.example` dosyasını `.env` olarak kopyala, `php artisan key:generate`, `php artisan migrate --seed`
- Başlat: `baslat.bat` (ya da `php artisan serve`) → http://127.0.0.1:8000
- Demo verisine sıfırla (her şeyi siler): `php artisan migrate:fresh --seed`
- Testler: `php artisan test`
- Kurallar: `config/automations.php`, çalıştıran kod: `app/Services/AutomationEngine.php`

Giriş ekranı yoktur, kullanıcı sağ üstten seçilir. Bu haliyle internete açmayın.

## Docker

```sh
docker compose up -d --build      # http://localhost:8080
docker compose logs -f            # günlükler
docker compose down               # durdur (veri kalır; `down -v` veriyi SİLER)
```

Veri SQLite olarak `data` hacminde (`/data/database.sqlite`) durur. İlk açılışta örnek veri eklenir.
Varsayılan olarak yalnızca bu bilgisayardan erişilir; port için `APP_PORT`, adres için `BIND_ADDRESS` ortam değişkeni.

Yedek alma:

```sh
docker compose exec app php -r '@unlink("/data/yedek.sqlite"); (new PDO("sqlite:/data/database.sqlite"))->exec("VACUUM INTO \"/data/yedek.sqlite\"");'
docker compose cp app:/data/yedek.sqlite ./yedek.sqlite
```
