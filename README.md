# Mini Pano

Laravel + SQLite ile basit bir pano demosu.

- İlk kurulum (repo klonlandıysa): `composer install`, `.env.example` dosyasını `.env` olarak kopyala, `php artisan key:generate`, `php artisan migrate --seed`
- Başlat: `baslat.bat` (ya da `php artisan serve`) → http://127.0.0.1:8000
- Demo verisine sıfırla (her şeyi siler): `php artisan migrate:fresh --seed`
- Testler: `php artisan test`
- Kurallar: `config/automations.php`, çalıştıran kod: `app/Services/AutomationEngine.php`

Giriş ekranı yoktur, kullanıcı sağ üstten seçilir. Bu haliyle internete açmayın.
