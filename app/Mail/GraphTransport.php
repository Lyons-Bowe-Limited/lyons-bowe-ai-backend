<?php

namespace App\Mail;

use App\Services\GraphMailService;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

class GraphTransport extends AbstractTransport
{
    protected GraphMailService $graph;

    public function __construct(GraphMailService $graph)
    {
        parent::__construct();

        $this->graph = $graph;
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (!$original instanceof Email) {
            return;
        }

        $to = collect($original->getTo())
            ->map(fn ($address) => $address->getAddress())
            ->toArray();

        $subject = $original->getSubject();

        $body = $original->getHtmlBody() ?? $original->getTextBody();

        \Log::info('GRAPH TRANSPORT HIT', [
            'to' => $to,
            'subject' => $subject,
        ]);

        $this->graph->sendMail($to, $subject, $body);
    }

    public function __toString(): string
    {
        return 'graph';
    }
}