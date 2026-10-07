<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activate your Lyons Bowe staff account</title>
</head>
<body style="font-family: Arial, sans-serif; color: #222; line-height: 1.6;">
    <p>Hello {{ $firstName }},</p>
    <p>An internal Lyons Bowe dashboard account has been created for you.</p>
    <p>Please use the button below to activate your account and set your password.</p>
    <p>
        <a href="{{ $activationUrl }}" style="display: inline-block; padding: 12px 20px; background: #202f3f; color: #fff; text-decoration: none; border-radius: 4px;">
            Activate staff account
        </a>
    </p>
    <p>This link expires in 24 hours and can only be used once.</p>
    <p>If you did not expect this email, do not use the link and contact an administrator.</p>
</body>
</html>
