<?php

namespace Database\Seeders;

use App\Models\BoardList;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = collect([
            ['Yönetici', 'yonetici', '#0c66e4', true],
            ['Ayşe Yılmaz', 'ayse', '#ae2e24', false],
            ['Mehmet Kaya', 'mehmet', '#216e4e', false],
            ['Zeynep Demir', 'zeynep', '#5e4db2', false],
        ])->map(fn ($u) => User::create([
            'name' => $u[0], 'email' => $u[1].'@example.com', 'password' => Str::random(40),
            'color' => $u[2], 'is_admin' => $u[3],
        ]));

        $lists = collect(['Yapılacaklar', 'Yapılıyor', 'Yapıldı', 'Tamamlandı'])
            ->map(fn ($name, $i) => BoardList::create(['name' => $name, 'position' => $i]));

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
