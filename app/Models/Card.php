<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Card extends Model
{
    protected $guarded = [];

    protected $casts = ['completed' => 'boolean', 'due_date' => 'date:Y-m-d'];

    public function list(): BelongsTo
    {
        return $this->belongsTo(BoardList::class, 'board_list_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class)->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->latest('id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /** 'none': checklist yok, 'incomplete': işaretlenmemiş madde var, 'complete': hepsi tamam. */
    public function checklistState(): string
    {
        if (! $this->checklists()->exists()) {
            return 'none';
        }

        return $this->checklists()->whereHas('items', fn ($q) => $q->where('checked', false))->exists()
            ? 'incomplete'
            : 'complete';
    }

    /** Kartı listeye, verilen sıraya (0 = en üst) yerleştirir. */
    public function moveTo(BoardList $list, int $index = 0): void
    {
        $ids = $list->cards()->whereKeyNot($this->id)->pluck('id')->all();
        array_splice($ids, $index, 0, [$this->id]);
        $this->update(['board_list_id' => $list->id]);

        foreach ($ids as $position => $id) {
            static::whereKey($id)->update(['position' => $position]);
        }
        $this->refresh();
    }
}
