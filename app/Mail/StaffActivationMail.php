<?php

namespace App\Mail;

use App\Models\StaffUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffActivationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public StaffUser $staffUser,
        public string $activationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Activate your Lyons Bowe staff account');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.staff-activation',
            with: [
                'firstName' => $this->staffUser->first_name,
                'activationUrl' => $this->activationUrl,
            ],
        );
    }
}
