<?php

namespace Tests\Feature;

use App\Models\AutomationLog;
use App\Models\BoardList;
use App\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    private const DATE_LIST = '24.11.2026 - Salı';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo('2026-11-24 12:00:00');
    }

    private function card(string $list, array $items = []): Card
    {
        $card = BoardList::firstWhere('name', $list)->cards()->create(['title' => 'Test', 'position' => 99]);
        if ($items) {
            $checklist = $card->checklists()->create(['name' => 'K']);
            foreach ($items as $checked) {
                $checklist->items()->create(['name' => 'x', 'checked' => $checked]);
            }
        }

        return $card;
    }

    private function complete(Card $card, bool $admin): Card
    {
        $this->actingAs(User::where('is_admin', $admin)->first())
            ->postJson("/cards/{$card->id}/complete", ['completed' => true, 'list_id' => $card->board_list_id])
            ->assertOk();

        return $card->refresh();
    }

    public static function moves(): array
    {
        return [
            'kural 1' => [1, 'Yapılacaklar', [true, true], false, 'Yapılıyor', false],
            'kural 2' => [2, 'Yapılıyor', [true], false, 'Yapıldı', false],
            'kural 3' => [3, 'Yapıldı', [true], false, 'Tamamlandı', false],
            'kural 4 yönetici' => [4, 'Tamamlandı', [true], true, self::DATE_LIST, true],
            'kural 5' => [5, 'Yapılacaklar', [], false, 'Yapılıyor', false],
            'kural 6' => [6, 'Yapılıyor', [], false, 'Yapıldı', false],
            'kural 7' => [7, 'Yapıldı', [], false, 'Tamamlandı', false],
            'kural 8' => [8, 'Tamamlandı', [], true, self::DATE_LIST, true],
            'kural 9' => [9, 'Yapılacaklar', [true, false], false, 'Yapılacaklar', false],
            'kural 9 tamamlandı' => [9, 'Tamamlandı', [false], true, 'Tamamlandı', false],
        ];
    }

    #[DataProvider('moves')]
    public function test_rules(?int $rule, string $from, array $items, bool $admin, string $to, bool $completed): void
    {
        $card = $this->complete($this->card($from, $items), $admin);

        $this->assertSame($to, $card->list->name);
        $this->assertSame($completed, $card->completed);
        $this->assertSame($rule ? [$rule] : [], AutomationLog::pluck('rule_id')->all());
        $this->assertSame(
            $rule === 9 ? ['Kontrol listesinde tamamlanmamış maddeler var'] : [],
            $card->comments->pluck('body')->all(),
        );
        if ($from !== $to) {
            $this->assertSame($card->id, $card->list->cards()->first()->id, 'Kart listenin en üstünde olmalı');
        }
    }

    public function test_rule_9_applies_in_custom_lists(): void
    {
        BoardList::create(['name' => 'Özel', 'position' => 9]);
        $this->assertFalse($this->complete($this->card('Özel', [false]), false)->completed);
    }

    public function test_only_admin_can_approve_in_tamamlandi(): void
    {
        $this->actingAs(User::where('is_admin', false)->first());
        foreach ([[], [true], [false]] as $items) {
            $card = $this->card('Tamamlandı', $items);
            $this->postJson("/cards/{$card->id}/complete", ['completed' => true, 'list_id' => $card->board_list_id])
                ->assertForbidden();
            $this->assertFalse($card->refresh()->completed);
        }
        $this->assertSame(0, AutomationLog::count());
    }

    public function test_date_list_is_reused(): void
    {
        $this->complete($this->card('Tamamlandı'), true);
        $this->complete($this->card('Tamamlandı'), true);
        $this->assertSame(2, BoardList::firstWhere('name', self::DATE_LIST)->cards()->count());
    }

    public function test_stale_request_does_not_advance_card_twice(): void
    {
        $card = $this->card('Yapılacaklar');
        $stale = $card->board_list_id;
        $this->complete($card, false);
        $this->postJson("/cards/{$card->id}/complete", ['completed' => true, 'list_id' => $stale])->assertOk();
        $this->assertSame('Yapılıyor', $card->refresh()->list->name);
    }

    public function test_rule_lists_are_protected_and_manual_move_triggers_nothing(): void
    {
        $list = BoardList::firstWhere('name', 'Yapılıyor');
        $this->deleteJson("/lists/{$list->id}")->assertStatus(422);
        $this->putJson("/lists/{$list->id}", ['name' => 'x'])->assertStatus(422);

        $card = $this->card('Yapılacaklar');
        $this->postJson("/cards/{$card->id}/move", ['list_id' => $list->id, 'index' => 1])->assertOk();
        $this->assertSame([$list->id, 1], [$card->refresh()->board_list_id, $card->position]);
        $this->assertSame(0, AutomationLog::count());
    }

    public function test_non_admin_cannot_move_or_delete_approved_cards(): void
    {
        $done = BoardList::firstWhere('name', 'Tamamlandı');
        $todo = BoardList::firstWhere('name', 'Yapılacaklar');
        $date = BoardList::create(['name' => self::DATE_LIST, 'position' => 9]);
        $move = fn (Card $card, BoardList $to) => $this->postJson("/cards/{$card->id}/move", ['list_id' => $to->id, 'index' => 0]);

        $this->actingAs(User::where('is_admin', false)->first());
        $move($this->card('Tamamlandı'), $date)->assertForbidden();
        $move($this->card('Tamamlandı'), $todo)->assertForbidden();
        $move($this->card('Yapılacaklar'), $date)->assertForbidden();
        $move($this->card(self::DATE_LIST), $todo)->assertForbidden();
        $move($this->card('Yapıldı'), $done)->assertOk();
        $this->deleteJson("/cards/{$this->card('Tamamlandı')->id}")->assertForbidden();
        $this->deleteJson("/lists/{$date->id}")->assertForbidden();
        $this->postJson("/lists/{$date->id}/cards", ['title' => 'x'])->assertStatus(422);
        $this->postJson('/lists', ['name' => '25.11.2026 - Çarşamba'])->assertStatus(422);

        $this->actingAs(User::where('is_admin', true)->first());
        $card = $this->complete($this->card('Tamamlandı'), true);
        $move($card, $done)->assertOk();
        $this->assertSame(['Tamamlandı', false], [$card->refresh()->list->name, $card->completed]);
    }

    public function test_list_order_ignores_date_lists(): void
    {
        BoardList::create(['name' => self::DATE_LIST, 'position' => 4]);
        $a = BoardList::create(['name' => 'A', 'position' => 5]);
        $b = BoardList::create(['name' => 'B', 'position' => 6]);
        $this->postJson("/lists/{$b->id}/move", ['index' => 4])->assertOk();
        $this->assertLessThan($a->refresh()->position, $b->refresh()->position);
    }

    public function test_board_page_loads(): void
    {
        $this->get('/')->assertOk();
        $this->getJson('/board')->assertOk()->assertJsonCount(4, 'lists')->assertJsonCount(9, 'rules');
    }
}
