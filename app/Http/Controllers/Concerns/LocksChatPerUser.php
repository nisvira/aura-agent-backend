<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

trait LocksChatPerUser
{
    /**
     * One in-flight LLM request per user, so a single account cannot queue up
     * many slow generations on the GPU even within the rate limit.
     */
    protected function acquireUserLock(int $userId): Lock
    {
        $lock = Cache::lock("chat:user:{$userId}", config('services.ollama.timeout') + 30);

        abort_unless($lock->get(), 429, 'กำลังประมวลผลคำถามก่อนหน้า กรุณารอสักครู่');

        return $lock;
    }
}
