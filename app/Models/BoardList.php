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

    /** "24.11.2026 - Salı" biçimindeki listeler "Tamamlananlar" sekmesinde gösterilir. */
    public const DATE_PATTERN = '/^[0-9]{2}[.][0-9]{2}[.][0-9]{4} - /';

    public function isDate(): bool
    {
        return preg_match(self::DATE_PATTERN, $this->name) === 1;
    }

    /** Bu listelerdeki kartları yalnızca yönetici işaretleyebilir ve taşıyabilir. */
    public function isAdminOnly(): bool
    {
        return $this->name === 'Tamamlandı' || $this->isDate();
    }

    /** Kuralların kullandığı listeler yeniden adlandırılamaz ve silinemez. */
    public function isProtected(): bool
    {
        return in_array($this->name, array_column(config('automations'), 'list'), true);
    }
}
