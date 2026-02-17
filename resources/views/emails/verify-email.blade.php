@component('mail::message')
# Welcome to Lyons Bowe

Hello {{ $user->name }},

Thank you for registering with **Lyons Bowe Solicitors**. We're delighted to welcome you to our community.

To complete your registration and activate your account, please verify your email address by clicking the button below.

@component('mail::button', ['url' => $verificationUrl, 'color' => 'primary'])
Verify Email Address
@endcomponent

**Important:** This verification link will expire in 60 minutes for security purposes.

If you did not create an account with Lyons Bowe, please ignore this email. No further action is required.

We look forward to serving you.

Best regards,<br>
**The Lyons Bowe Team**

@component('mail::subcopy')
If you're having trouble clicking the "Verify Email Address" button, copy and paste the URL below into your web browser:

{{ $verificationUrl }}
@endcomponent
@endcomponent
