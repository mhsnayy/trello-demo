<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItem extends Model
{
    protected $guarded = [];

    protected $casts = ['checked' => 'boolean'];

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }
}
