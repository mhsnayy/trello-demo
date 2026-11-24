<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AutomationLog;
use App\Models\BoardList;
use App\Models\Card;
use App\Models\User;

class AutomationEngine
{
    /** Kart tamamlandı olarak işaretlendiğinde çağrılır; uyan ilk kuralı çalıştırır. */
    public function cardCompleted(Card $card, User $actor): ?string
    {
        $state = $card->checklistState();
        $listName = $card->list->name;

        foreach (config('automations') as $rule) {
            $matches = ($rule['list'] === null || $rule['list'] === $listName)
                && $rule['checklist'] === $state
                && ($rule['by'] === 'anyone' || $actor->is_admin);

            if ($matches) {
                return $this->run($rule, $card, $actor);
            }
        }

        return null;
    }

    private function run(array $rule, Card $card, User $actor): string
    {
        $done = [];

        if ($rule['move_to']) {
            $list = $this->targetList($rule['move_to']);
            $card->moveTo($list);
            $done[] = "“{$list->name}” listesine taşındı";
        }
        if ($rule['reset']) {
            $card->update(['completed' => false]);
        }
        if ($rule['comment']) {
            $card->comments()->create(['body' => $rule['comment']]);
            $done[] = $rule['comment'];
        }
        $message = "“{$card->title}”: ".implode(', ', $done);

        Activity::record($card, "⚡ Kural {$rule['id']}: ".implode(', ', $done));
        AutomationLog::create(['rule_id' => $rule['id'], 'user_id' => $actor->id, 'message' => $message]);

        return $message;
    }

    private function targetList(string $template): BoardList
    {
        $now = now()->locale('tr');
        $name = strtr($template, [
            '{date}' => $now->isoFormat('DD.MM.YYYY'),
            '{weekdayname}' => $now->isoFormat('dddd'),
        ]);

        return BoardList::firstOrCreate(['name' => $name], ['position' => BoardList::max('position') + 1]);
    }
}
