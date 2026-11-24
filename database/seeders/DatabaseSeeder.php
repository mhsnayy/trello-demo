<?php

namespace Database\Seeders;

use App\Models\BoardList;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserSeeder::class);
        $users = User::orderBy('id')->get();

        $lists = collect(['Yapılacaklar', 'Yapılıyor', 'Yapıldı', 'Tamamlandı'])
            ->map(fn ($name, $i) => BoardList::create(['name' => $name, 'position' => $i]));

        // Örnek kartlar yalnızca geliştirme ortamında eklenir.
        if (app()->isProduction()) {
            return;
        }

        $cards = [
            [0, 'Müşteri toplantısını planla', [1], []],
            [0, 'Anasayfa tasarımını hazırla', [2], ['Taslak çiz' => true, 'Renkleri seç' => false, 'Mobil görünüm' => false]],
            [0, 'Sosyal medya içerik takvimi', [3], ['Konuları belirle' => true, 'Görselleri hazırla' => true]],
            [1, 'İletişim formunu geliştir', [1, 2], ['Form alanları' => true, 'Doğrulama' => false]],
            [1, 'Logo revizyonu', [3], []],
            [2, 'Fiyat listesini güncelle', [1], ['Yeni fiyatları al' => true, 'Tabloyu güncelle' => true]],
            [3, 'Alan adını yenile', [0], []],
            [3, 'Sunucu yedeğini kontrol et', [0, 2], ['Yedek al' => true, 'Geri yüklemeyi dene' => true]],
        ];

        foreach ($cards as $i => [$list, $title, $members, $items]) {
            $card = $lists[$list]->cards()->create(['title' => $title, 'position' => $i]);
            $card->members()->attach(array_map(fn ($m) => $users[$m]->id, $members));
            if ($items) {
                $checklist = $card->checklists()->create(['name' => 'Kontrol listesi']);
                foreach ($items as $name => $checked) {
                    $checklist->items()->create(compact('name', 'checked'));
                }
            }
        }
    }
}
