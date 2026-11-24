<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardList extends Model
{
    protected $guarded = [];

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class)->orderBy('position')->orderBy('id');
    }

    /** Bu listedeki kartların tamamlandı işaretini yalnızca yönetici değiştirebilir. */
    public function isAdminOnly(): bool
    {
        return $this->name === 'Tamamlandı';
    }

    /** Kuralların kullandığı listeler yeniden adlandırılamaz ve silinemez. */
    public function isProtected(): bool
    {
        return in_array($this->name, array_column(config('automations'), 'list'), true);
    }
}
