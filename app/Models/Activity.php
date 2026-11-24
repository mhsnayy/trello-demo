<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class Activity extends Model
{
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(Card|BoardList $subject, string $message): void
    {
        static::create([
            'card_id' => $subject instanceof Card ? $subject->id : null,
            'user_id' => Auth::id(),
            'subject' => $subject instanceof Card ? $subject->title : $subject->name,
            'message' => $message,
        ]);
    }
}
