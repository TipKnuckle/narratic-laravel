<?php

namespace App\Contracts;

use App\Dto\DigestContent;
use App\Models\User;

interface Mailer
{
    public function send(User $user, DigestContent $content): void;
}
