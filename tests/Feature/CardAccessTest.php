<?php

namespace Tests\Feature;

use App\Models\BoardList;
use App\Models\Card;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CardAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private User $viewer;

    private User $outsider;

    private Card $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Card::query()->forceDelete();
        User::query()->update(['must_change_password' => false]);
        [$this->admin, $this->member, $this->viewer, $this->outsider] = User::orderBy('id')->get()->all();

        $this->card = BoardList::firstWhere('name', 'Yapılacaklar')->cards()->create(['title' => 'Gizli', 'position' => 0]);
        $this->card->people()->attach([$this->member->id => ['role' => 'member'], $this->viewer->id => ['role' => 'viewer']]);
        $this->card->checklists()->create(['name' => 'K'])->items()->create(['name' => 'x', 'checked' => false]);
    }

    public function actingAs($user, $guard = null)
    {
        $this->flushSession();

        return parent::actingAs($user, $guard);
    }

    /** Kartı değiştiren bütün istekler. */
    private function writes(): array
    {
        $id = $this->card->id;
        $checklist = $this->card->checklists()->first();
        $item = $checklist->items()->first();

        return [
            ['PUT', "/cards/$id", ['title' => 'x']],
            ['DELETE', "/cards/$id", []],
            ['POST', "/cards/$id/restore", []],
            ['POST', "/cards/$id/move", ['list_id' => 2, 'index' => 0]],
            ['POST', "/cards/$id/complete", ['completed' => true, 'list_id' => $this->card->board_list_id]],
            ['PUT', "/cards/$id/people/{$this->outsider->id}", ['role' => 'member']],
            ['POST', "/cards/$id/comments", ['body' => 'x']],
            ['POST', "/cards/$id/checklists", ['name' => 'x']],
            ['DELETE', "/checklists/{$checklist->id}", []],
            ['POST', "/checklists/{$checklist->id}/items", ['name' => 'x']],
            ['PUT', "/items/{$item->id}", ['checked' => true]],
            ['DELETE', "/items/{$item->id}", []],
        ];
    }

    private function cardsSeenBy(User $user): array
    {
        return array_merge(...array_column($this->actingAs($user)->getJson('/board')->assertOk()->json('lists'), 'cards'));
    }

    public function test_viewer_sees_but_cannot_change(): void
    {
        $seen = $this->cardsSeenBy($this->viewer);
        $this->assertSame(['Gizli', false], [$seen[0]['title'], $seen[0]['can_edit']]);
        $this->getJson("/cards/{$this->card->id}/history")->assertOk();
        foreach ($this->writes() as [$method, $url, $data]) {
            $this->json($method, $url, $data)->assertForbidden();
        }
    }

    public function test_outsider_sees_nothing(): void
    {
        $this->actingAs($this->member)->postJson("/cards/{$this->card->id}/complete", ['completed' => true, 'list_id' => $this->card->board_list_id]);
        $this->card->refresh();

        $this->assertSame([], $this->cardsSeenBy($this->outsider));
        $board = $this->getJson('/board');
        $this->assertStringNotContainsString('Gizli', json_encode($board->json()));
        $this->getJson("/cards/{$this->card->id}/history")->assertNotFound();
        $this->getJson('/history')->assertOk()->assertJsonCount(0);
        foreach ($this->writes() as [$method, $url, $data]) {
            $this->json($method, $url, $data)->assertNotFound();
        }

        $this->card->delete();
        $this->getJson('/board')->assertJsonCount(0, 'archived');
        $this->actingAs($this->viewer)->getJson('/board')->assertJsonPath('archived.0.can_restore', false);
        $this->actingAs($this->member)->getJson('/board')->assertJsonPath('archived.0.can_restore', true);
    }

    public function test_member_and_admin_can_edit_and_assign(): void
    {
        $url = "/cards/{$this->card->id}/people/{$this->outsider->id}";
        $this->assertTrue($this->cardsSeenBy($this->member)[0]['can_edit']);
        $this->putJson($url, ['role' => 'viewer'])->assertOk();
        $this->assertCount(1, $this->cardsSeenBy($this->outsider));
        $this->actingAs($this->admin)->putJson($url, ['role' => 'member'])->assertOk();
        $this->assertTrue($this->cardsSeenBy($this->outsider)[0]['can_edit']);
        $this->actingAs($this->admin)->putJson($url, ['role' => null])->assertOk();
        $this->assertSame([], $this->cardsSeenBy($this->outsider));
        $this->actingAs($this->admin)->putJson($url, ['role' => 'patron'])->assertStatus(422);
        $this->assertCount(1, $this->cardsSeenBy($this->admin));
    }

    public function test_creator_becomes_member_and_list_delete_is_admin_only(): void
    {
        $list = BoardList::create(['name' => 'Özel', 'position' => 9]);
        $this->actingAs($this->outsider)->postJson("/lists/{$list->id}/cards", ['title' => 'Benim'])->assertOk();
        $seen = $this->cardsSeenBy($this->outsider);
        $this->assertSame([['Benim', true]], array_map(fn ($c) => [$c['title'], $c['can_edit']], $seen));
        $this->deleteJson("/lists/{$list->id}")->assertForbidden();
    }

    public function test_move_index_counts_only_visible_cards(): void
    {
        $list = BoardList::firstWhere('name', 'Yapılıyor');
        $make = function (string $title, int $position, bool $visible) use ($list) {
            $card = $list->cards()->create(compact('title', 'position'));
            if ($visible) {
                $card->members()->attach($this->member);
            }
        };
        $make('gizli1', 0, false);
        $make('A', 1, true);
        $make('gizli2', 2, false);
        $make('B', 3, true);

        $this->actingAs($this->member)
            ->postJson("/cards/{$this->card->id}/move", ['list_id' => $list->id, 'index' => 1])->assertOk();
        $this->assertSame(['gizli1', 'A', 'gizli2', 'Gizli', 'B'], $list->cards()->pluck('title')->all());
    }
}
