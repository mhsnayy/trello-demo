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
        // Listede, silen kişinin göremediği kartlar olabilir.
        abort_unless(Auth::user()->is_admin, 403, self::ADMIN_ONLY);

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
        // Kartı açan kişi göremez duruma düşmesin.
        if (! Auth::user()->is_admin) {
            $card->members()->attach(Auth::id());
        }
        Activity::record($card, "kartı “{$list->name}” listesine ekledi");

        return $this->board();
    }

    public function updateCard(Request $request, Card $card)
    {
        $this->ensureEditable($card);
        $old = $card->title;
        $card->update($request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|nullable|string|max:10000',
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
        $this->ensureMember($card);
        abort_if($card->list->isAdminOnly() && ! Auth::user()->is_admin, 403, self::ADMIN_ONLY);
        $card->delete();
        Activity::record($card, 'kartı arşivledi');

        return $this->board();
    }

    public function restoreCard(Card $card)
    {
        $this->ensureMember($card);
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
        $user = Auth::user();
        abort_if($card && ! $card->canView($user), 404);

        return Activity::with('user')
            ->when($card, fn ($q) => $q->where('card_id', $card->id))
            ->when(! $card && ! $user->is_admin, fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('card_id')
                ->orWhereIn('card_id', Card::withTrashed()->visibleTo($user)->select('id'))))
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
        $this->ensureMember($card);
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
            // Gelen sıra, kullanıcının görebildiği kartlara göredir; tüm kartlar içindeki yerini bul.
            $others = $target->cards()->whereKeyNot($card->id)->pluck('id');
            $next = $target->cards()->whereKeyNot($card->id)->visibleTo(Auth::user())->pluck('id')->get($data['index']);
            $card->moveTo($target, $next === null ? $others->count() : $others->search($next));
            if ($from->id !== $target->id) {
                Activity::record($card, "“{$from->name}” listesinden “{$target->name}” listesine taşıdı");
            }
        });

        return $this->board();
    }

    public function completeCard(Request $request, Card $card, AutomationEngine $automations)
    {
        $this->ensureMember($card);
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

    /** role: member (üye), viewer (izleyici) ya da null (karttan çıkar). */
    public function setRole(Request $request, Card $card, User $user)
    {
        $this->ensureEditable($card);
        $role = $request->validate(['role' => ['present', 'nullable', Rule::in(['member', 'viewer'])]])['role'];

        if ($role === null) {
            $changed = $card->people()->detach($user) > 0;
        } else {
            $changes = $card->people()->syncWithoutDetaching([$user->id => ['role' => $role]]);
            $changed = $changes['attached'] !== [] || $changes['updated'] !== [];
        }
        if ($changed) {
            Activity::record($card, $user->name.match ($role) {
                'member' => ' adlı kişiyi üye yaptı',
                'viewer' => ' adlı kişiyi izleyici yaptı',
                null => ' adlı kişiyi karttan çıkardı',
            });
        }

        return $this->board();
    }

    public function storeComment(Request $request, Card $card)
    {
        $this->ensureMember($card);
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

    /** Kartı göremeyen için kart yoktur; izleyici görür ama değiştiremez. */
    private function ensureMember(?Card $card): void
    {
        abort_if($card === null || ! $card->canView(Auth::user()), 404);
        abort_unless($card->canEdit(Auth::user()), 403, 'Bu karta sadece atanmış üyeler ve yönetici müdahale edebilir.');
    }

    /** Onaylanmış (tarihli listedeki) kartı yalnızca yönetici düzenler; arşivdeki kart düzenlenmez. */
    private function ensureEditable(?Card $card): void
    {
        $this->ensureMember($card);
        abort_if($card->list->isDate() && ! Auth::user()->is_admin, 403, 'Onaylanmış kartı sadece yönetici düzenleyebilir.');
    }

    /** Pano çizilirken her kart için ayrı sorgu atmamak üzere yüklenmiş ilişkiyi kullanır. */
    private function hasRole(Card $card, User $user, string $role): bool
    {
        return $card->people->contains(fn ($person) => $person->id === $user->id && $person->pivot->role === $role);
    }

    private function board(?string $notice = null): JsonResponse
    {
        $user = Auth::user();
        $lists = BoardList::with([
            'cards' => fn ($q) => $q->visibleTo($user),
            'cards.people', 'cards.checklists.items', 'cards.comments',
        ])->orderBy('position')->orderBy('id')->get();

        return response()->json([
            'notice' => $notice,
            'me' => Auth::id(),
            'users' => User::orderBy('id')->get(['id', 'name', 'color', 'is_admin']),
            'rules' => config('automations'),
            'logs' => AutomationLog::with('user')
                ->when(! $user->is_admin, fn ($q) => $q->whereIn('card_id', Card::withTrashed()->visibleTo($user)->select('id')))
                ->latest('id')->limit(20)->get()->map(fn ($log) => [
                'rule_id' => $log->rule_id,
                'message' => $log->message,
                'user' => $log->user?->name,
                'at' => $log->created_at->format('d.m.Y H:i'),
            ]),
            'archived' => Card::onlyTrashed()->visibleTo($user)->with(['list', 'people'])->latest('deleted_at')->latest('id')->get()->map(fn ($card) => [
                'id' => $card->id,
                'title' => $card->title,
                'list' => $card->list->name,
                'can_restore' => $user->is_admin || (! $card->list->isAdminOnly() && $this->hasRole($card, $user, 'member')),
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
                    'members' => $card->people->where('pivot.role', 'member')->pluck('id')->values(),
                    'viewers' => $card->people->where('pivot.role', 'viewer')->pluck('id')->values(),
                    'can_edit' => $user->is_admin || $this->hasRole($card, $user, 'member'),
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
