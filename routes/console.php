<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Telescope: تنظيف تلقائي للبيانات القديمة ────────────────────────────────
// يحتفظ بآخر 48 ساعة فقط لتجنب تضخم جدول telescope_entries
Schedule::command('telescope:prune --hours=48')->daily();

// ─── EasyPost: تحديث حالة الشحنات تلقائياً كل 4 ساعات ─────────────────────────
Schedule::command('shipping:track')->everyFourHours();
