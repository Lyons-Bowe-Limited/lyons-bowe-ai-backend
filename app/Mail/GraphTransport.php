<?php

namespace App\Mail;

use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use App\Services\GraphMailService;

class GraphTransport extends AbstractTransport
{
    protected $graph;

    public function __construct(GraphMailService $graph)
    {
        parent::__construct();
        $this->graph = $graph;
    }

    protected function doSend(\Symfony\Component\Mime\RawMessage $message, $envelope = null): void
    {
        $email = Email::fromString($message->toString());

        $this->graph->sendMail(
            $email->getTo()[0]->getAddress(),
            $email->getSubject(),
            $email->getHtmlBody() ?? $email->getTextBody()
        );
    }

    public function __toString(): string
    {
        return 'graph';
    }
}