<?php

/*
 * rules.md içindeki 9 kural (aynı sırayla).
 * Fark: 8. kural da yalnızca yöneticide çalışır; Tamamlandı listesini sadece yönetici onaylar.
 * Hepsi kart "tamamlandı" olarak işaretlenince tetiklenir.
 *
 * list       : kartın bulunduğu liste (null = herhangi bir liste)
 * checklist  : complete | none | incomplete
 * by         : anyone | me (me = yönetici kullanıcı)
 * move_to    : hedef liste, kart en üste taşınır (liste yoksa oluşturulur)
 * reset      : kartı tekrar "tamamlanmadı" yap
 * comment    : karta yazılacak yorum
 */

$date = '{date} - {weekdayname}';

return [
    ['id' => 1, 'list' => 'Yapılacaklar', 'checklist' => 'complete', 'by' => 'anyone', 'move_to' => 'Yapılıyor', 'reset' => true, 'comment' => null],
    ['id' => 2, 'list' => 'Yapılıyor', 'checklist' => 'complete', 'by' => 'anyone', 'move_to' => 'Yapıldı', 'reset' => true, 'comment' => null],
    ['id' => 3, 'list' => 'Yapıldı', 'checklist' => 'complete', 'by' => 'anyone', 'move_to' => 'Tamamlandı', 'reset' => true, 'comment' => null],
    ['id' => 4, 'list' => 'Tamamlandı', 'checklist' => 'complete', 'by' => 'me', 'move_to' => $date, 'reset' => false, 'comment' => null],
    ['id' => 5, 'list' => 'Yapılacaklar', 'checklist' => 'none', 'by' => 'anyone', 'move_to' => 'Yapılıyor', 'reset' => true, 'comment' => null],
    ['id' => 6, 'list' => 'Yapılıyor', 'checklist' => 'none', 'by' => 'anyone', 'move_to' => 'Yapıldı', 'reset' => true, 'comment' => null],
    ['id' => 7, 'list' => 'Yapıldı', 'checklist' => 'none', 'by' => 'anyone', 'move_to' => 'Tamamlandı', 'reset' => true, 'comment' => null],
    ['id' => 8, 'list' => 'Tamamlandı', 'checklist' => 'none', 'by' => 'me', 'move_to' => $date, 'reset' => false, 'comment' => null],
    ['id' => 9, 'list' => null, 'checklist' => 'incomplete', 'by' => 'anyone', 'move_to' => null, 'reset' => true, 'comment' => 'Kontrol listesinde tamamlanmamış maddeler var'],
];
