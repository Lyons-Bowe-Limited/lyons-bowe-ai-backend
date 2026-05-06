<?php

namespace App\Mail;

use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

protected function doSend(SentMessage $message): void
{
    $original = $message->getOriginalMessage();

    if (!$original instanceof Email) {
        return;
    }

    $to = collect($original->getTo())->map(fn ($a) => $a->getAddress())->toArray();

    $subject = $original->getSubject();

    $body = $original->getHtmlBody() ?? $original->getTextBody();

    \Log::info('GRAPH TRANSPORT HIT', [
        'to' => $to,
        'subject' => $subject,
    ]);

    $this->graph->sendMail($to, $subject, $body);
}