<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\AutomationLog;
use App\Models\BoardList;
use App\Models\Card;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\User;
use App\Services\AutomationEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Her değişiklik, panonun güncel halini JSON olarak döndürür. */
class BoardController extends Controller
{
    private const MESSAGES = [
        'name.unique' => 'Bu isimde bir liste zaten var.',
        'name.not_regex' => 'Tarihli listeler otomatik oluşturulur.',
    ];

    private const ADMIN_ONLY = 'Bu işlemi sadece yönetici yapabilir.';

    public function index()
    {
        return view('board');
    }

    public function data(): JsonResponse
    {
        return $this->board();
    }

    public function switchUser(User $user)
    {
        Auth::login($user);

        return response()->noContent();
    }

    // --- Listeler ---

    public function storeList(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255', 'not_regex:'.BoardList::DATE_PATTERN, 'unique:board_lists']], self::MESSAGES);
        $list = BoardList::create($data + ['position' => BoardList::max('position') + 1]);
        Activity::record($list, 'listeyi oluşturdu');

        return $this->board();
    }

    public function updateList(Request $request, BoardList $list)
    {
        abort_if($list->isProtected(), 422, 'Bu listeyi otomasyon kuralları kullanıyor, adı değiştirilemez.');
        abort_if($list->isDate(), 422, 'Tarihli listelerin adı değiştirilemez.');
        $old = $list->name;
        $list->update($request->validate([
            'name' => ['required', 'string', 'max:255', 'not_regex:'.BoardList::DATE_PATTERN, Rule::unique('board_lists')->ignore($list)],
        ], self::MESSAGES));
        if ($list->wasChanged('name')) {
            Activity::record($list, "listenin adını değiştirdi (eski: “{$old}”)");
        }

        return $this->board();
    }

    public function destroyList(BoardList $list)
    {
        abort_if($list->isProtected(), 422, 'Bu listeyi otomasyon kuralları kullanıyor, silinemez.');
        abort_if($list->isDate() && ! Auth::user()->is_admin, 403, self::ADMIN_ONLY);

        DB::transaction(function () use ($list) {
            // Kartlar kaybolmasın: arşivlenir ve geri alındığında gideceği listeye bağlanır.
            $fallback = BoardList::where('name', $list->isDate() ? 'Tamamlandı' : 'Yapılacaklar')->firstOrFail();
            foreach ($list->cards()->withTrashed()->get() as $card) {
                $card->update(['board_list_id' => $fallback->id, 'completed' => false]);
                if (! $card->trashed()) {
                    $card->delete();
                    Activity::record($card, "“{$list->name}” listesi silinirken arşivlendi");
                }
            }
            Activity::record($list, 'listeyi sildi, kartlarını arşivledi');
            $list->delete();
        });

        return $this->board();
    }

    public function moveList(Request $request, BoardList $list)
    {
        $index = $request->validate(['index' => 'required|integer|min:0'])['index'];

        DB::transaction(function () use ($list, $index) {
            // Tarihli listeler ayrı sekmede olduğu için sıralamaya girmez.
            $ids = BoardList::whereKeyNot($list->id)->orderBy('position')->orderBy('id')->get()
                ->reject->isDate()->pluck('id')->all();
            array_splice($ids, $index, 0, [$list->id]);
            foreach ($ids as $position => $id) {
                BoardList::whereKey($id)->update(['position' => $position]);
            }
        });

        return $this->board();
    }

    // --- Kartlar ---

    public function storeCard(Request $request, BoardList $list)
    {
        abort_if($list->isDate(), 422, 'Tarihli listelere elle kart eklenemez.');
        $data = $request->validate(['title' => 'required|string|max:255']);
        $card = $list->cards()->create($data + ['position' => ($list->cards()->max('position') ?? -1) + 1]);
        Activity::record($card, "kartı “{$list->name}” listesine ekledi");

        return $this->board();
    }

    public function updateCard(Request $request, Card $card)
    {
        $this->ensureEditable($card);
        $old = $card->title;
        $card->update($request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:25500',
            'due_date' => 'sometimes|nullable|date_format:Y-m-d',
        ]));
        if ($card->wasChanged('title')) {
            Activity::record($card, "başlığı değiştirdi (eski: “{$old}”)");
        }
        if ($card->wasChanged('description')) {
            Activity::record($card, 'açıklamayı değiştirdi');
        }
        if ($card->wasChanged('due_date')) {
            Activity::record($card, 'bitiş tarihini '.($card->due_date?->format('d.m.Y') ?? 'boş').' yaptı');
        }

        return $this->board();
    }

    /** Kart silinmez, arşive gider. */
    public function destroyCard(Card $card)
    {
        abort_if($card->list->isAdminOnly() && ! Auth::user()->is_admin, 403, self::ADMIN_ONLY);
        $card->delete();
        Activity::record($card, 'kartı arşivledi');

        return $this->board();
    }

    public function restoreCard(Card $card)
    {
        abort_if($card->list->isAdminOnly() && ! Auth::user()->is_admin, 403, self::ADMIN_ONLY);

        DB::transaction(function () use ($card) {
            if (! $card->trashed()) {
                return;
            }
            $card->restore();
            $card->moveTo($card->list, $card->list->cards()->count());
            Activity::record($card, "kartı arşivden “{$card->list->name}” listesine geri aldı");
        });

        return $this->board();
    }

    public function history(?Card $card = null)
    {
        return Activity::with('user')
            ->when($card, fn ($q) => $q->where('card_id', $card->id))
            ->latest('id')->limit(100)->get()
            ->map(fn ($activity) => [
                'user' => $activity->user?->name ?? 'Silinmiş kullanıcı',
                'subject' => $activity->subject,
                'message' => $activity->message,
                'at' => $activity->created_at->format('d.m.Y H:i'),
            ]);
    }

    /** Sürükle-bırak. Kuralları tetiklemez. */
    public function moveCard(Request $request, Card $card)
    {
        $data = $request->validate([
            'list_id' => 'required|exists:board_lists,id',
            'index' => 'required|integer|min:0',
        ]);
        $target = BoardList::find($data['list_id']);

        DB::transaction(function () use ($card, $target, $data) {
            $card->refresh();
            abort_if(
                ($card->list->isAdminOnly() || $target->isDate()) && ! Auth::user()->is_admin,
                403,
                'Bu kartı sadece yönetici taşıyabilir.',
            );
            // Tamamlananlardan geri alınan kart yeniden onay bekler.
            if ($card->list->isDate() && ! $target->isDate()) {
                $card->update(['completed' => false]);
            }
            $from = $card->list;
            $card->moveTo($target, $data['index']);
            if ($from->id !== $target->id) {
                Activity::record($card, "“{$from->name}” listesinden “{$target->name}” listesine taşıdı");
            }
        });

        return $this->board();
    }

    public function completeCard(Request $request, Card $card, AutomationEngine $automations)
    {
        $data = $request->validate(['completed' => 'required|boolean', 'list_id' => 'required|integer']);

        $notice = DB::transaction(function () use ($data, $card, $automations) {
            $card->refresh();
            // Kart bu sırada başka biri tarafından taşındıysa iki kez ilerletme.
            if ($card->board_list_id !== (int) $data['list_id']) {
                return 'Kart bu sırada başka bir listeye taşınmış.';
            }
            if ($card->completed === (bool) $data['completed']) {
                return null;
            }
            abort_if(
                $card->list->isAdminOnly() && ! Auth::user()->is_admin,
                403,
                'Bu kartı sadece yönetici onaylayabilir.',
            );
            $card->update(['completed' => $data['completed']]);
            Activity::record($card, $card->completed ? 'tamamlandı olarak işaretledi' : 'tamamlandı işaretini kaldırdı');

            return $card->completed ? $automations->cardCompleted($card, Auth::user()) : null;
        });

        return $this->board($notice);
    }

    public function toggleMember(Card $card, User $user)
    {
        $this->ensureEditable($card);
        $added = $card->members()->toggle($user)['attached'] !== [];
        Activity::record($card, $added ? "{$user->name} adlı üyeyi ekledi" : "{$user->name} adlı üyeyi çıkardı");

        return $this->board();
    }

    public function storeComment(Request $request, Card $card)
    {
        $data = $request->validate(['body' => 'required|string|max:5000']);
        $card->comments()->create($data + ['user_id' => Auth::id()]);

        return $this->board();
    }

    // --- Kontrol listeleri ---

    public function storeChecklist(Request $request, Card $card)
    {
        $this->ensureEditable($card);
        $checklist = $card->checklists()->create($request->validate(['name' => 'required|string|max:255']));
        Activity::record($card, "“{$checklist->name}” kontrol listesini ekledi");

        return $this->board();
    }

    public function destroyChecklist(Checklist $checklist)
    {
        $this->ensureEditable($checklist->card);
        $items = $checklist->items->map(fn ($item) => ($item->checked ? '☑ ' : '☐ ').$item->name)->implode(', ');
        $checklist->delete();
        Activity::record($checklist->card, "“{$checklist->name}” kontrol listesini sildi".($items ? " ({$items})" : ''));

        return $this->board();
    }

    public function storeItem(Request $request, Checklist $checklist)
    {
        $this->ensureEditable($checklist->card);
        $item = $checklist->items()->create($request->validate(['name' => 'required|string|max:255']));
        Activity::record($checklist->card, "“{$item->name}” maddesini ekledi");

        return $this->board();
    }

    public function updateItem(Request $request, ChecklistItem $item)
    {
        $card = $item->checklist->card;
        $this->ensureEditable($card);
        $item->update($request->validate(['checked' => 'required|boolean']));
        if ($item->wasChanged('checked')) {
            Activity::record($card, "“{$item->name}” maddesini ".($item->checked ? 'işaretledi' : 'işaretsiz yaptı'));
        }

        return $this->board();
    }

    public function destroyItem(ChecklistItem $item)
    {
        $card = $item->checklist->card;
        $this->ensureEditable($card);
        $item->delete();
        Activity::record($card, "“{$item->name}” maddesini sildi");

        return $this->board();
    }

    /** Onaylanmış (tarihli listedeki) kartı yalnızca yönetici düzenler; arşivdeki kart düzenlenmez. */
    private function ensureEditable(?Card $card): void
    {
        abort_if($card === null, 404);
        abort_if($card->list->isDate() && ! Auth::user()->is_admin, 403, 'Onaylanmış kartı sadece yönetici düzenleyebilir.');
    }

    private function board(?string $notice = null): JsonResponse
    {
        $lists = BoardList::with(['cards.members:id', 'cards.checklists.items', 'cards.comments'])
            ->orderBy('position')->orderBy('id')->get();

        return response()->json([
            'notice' => $notice,
            'me' => Auth::id(),
            'users' => User::orderBy('id')->get(['id', 'name', 'color', 'is_admin']),
            'rules' => config('automations'),
            'logs' => AutomationLog::with('user')->latest('id')->limit(20)->get()->map(fn ($log) => [
                'rule_id' => $log->rule_id,
                'message' => $log->message,
                'user' => $log->user?->name,
                'at' => $log->created_at->format('d.m.Y H:i'),
            ]),
            'archived' => Card::onlyTrashed()->with('list')->latest('deleted_at')->latest('id')->get()->map(fn ($card) => [
                'id' => $card->id,
                'title' => $card->title,
                'list' => $card->list->name,
                'admin_only' => $card->list->isAdminOnly(),
                'at' => $card->deleted_at->format('d.m.Y H:i'),
            ]),
            'lists' => $lists->map(fn ($list) => [
                'id' => $list->id,
                'name' => $list->name,
                'protected' => $list->isProtected(),
                'is_date' => $list->isDate(),
                'admin_only' => $list->isAdminOnly(),
                'cards' => $list->cards->map(fn ($card) => [
                    'id' => $card->id,
                    'list_id' => $list->id,
                    'title' => $card->title,
                    'description' => $card->description,
                    'due_date' => $card->due_date?->format('Y-m-d'),
                    'completed' => $card->completed,
                    'members' => $card->members->pluck('id'),
                    'checklists' => $card->checklists->map(fn ($checklist) => [
                        'id' => $checklist->id,
                        'name' => $checklist->name,
                        'items' => $checklist->items->map->only(['id', 'name', 'checked']),
                    ]),
                    'comments' => $card->comments->map(fn ($comment) => [
                        'user_id' => $comment->user_id,
                        'body' => $comment->body,
                        'at' => $comment->created_at->format('d.m.Y H:i'),
                    ]),
                ]),
            ]),
        ]);
    }
}
