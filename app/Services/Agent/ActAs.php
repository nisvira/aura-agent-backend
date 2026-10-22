<?php

namespace App\Services\Agent;

use App\Enums\UserRole;
use App\Models\User;

/**
 * An admin or instructor asking AURA about one student. Built only after the
 * "view-student" gate has allowed it; sent to AURA-AGENT as the X-Act-As-*
 * headers of the support mode proposal.
 */
final readonly class ActAs
{
    public function __construct(
        public string $studentCode,
        public UserRole $role,
        public User $actor,
        public string $reason,
    ) {}

    public function actorId(): string
    {
        return 'backend-user:'.$this->actor->getKey();
    }
}
