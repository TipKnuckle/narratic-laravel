<?php

namespace Tests\Fakes;

use App\Contracts\Mailer;
use App\Dto\DigestContent;
use App\Models\User;

class FakeMailer implements Mailer
{
    public int $sendCount = 0;

    public ?DigestContent $lastContent = null;

    public function send(User $user, DigestContent $content): void
    {
        $this->sendCount++;
        $this->lastContent = $content;
    }
}
