<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - Lyons Bowe</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #151515;
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            max-width: 600px;
            width: 100%;
            background-color: #1a1a1a;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
        }

        .logo-container {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo {
            max-width: 200px;
            height: auto;
            margin-bottom: 20px;
        }

        .logo-text {
            font-size: 28px;
            font-weight: bold;
            letter-spacing: -0.5px;
            color: #ffffff;
            margin-bottom: 8px;
        }

        .logo-subtitle {
            font-size: 12px;
            font-weight: 300;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .content {
            text-align: center;
        }

        h1 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 16px;
            color: #ffffff;
        }

        .success-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 24px;
            background-color: #F5D75D;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #151515;
        }

        .error-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 24px;
            background-color: #555555;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: #ffffff;
        }

        p {
            font-size: 16px;
            color: #cccccc;
            margin-bottom: 12px;
        }

        .message {
            background-color: #252525;
            border-left: 3px solid #F5D75D;
            padding: 16px 20px;
            margin: 24px 0;
            border-radius: 4px;
            text-align: left;
        }

        .message.error {
            border-left-color: #e53e3e;
        }

        .btn {
            display: inline-block;
            padding: 12px 32px;
            background-color: #F5D75D;
            color: #151515;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
            margin-top: 24px;
            transition: background-color 0.2s ease;
        }

        .btn:hover {
            background-color: #f0d04a;
        }

        .btn-secondary {
            background-color: #555555;
            color: #ffffff;
        }

        .btn-secondary:hover {
            background-color: #666666;
        }

        .footer {
            margin-top: 40px;
            padding-top: 24px;
            border-top: 1px solid #333;
            text-align: center;
            font-size: 14px;
            color: #888;
        }

        @media (max-width: 640px) {
            .container {
                padding: 24px;
            }

            h1 {
                font-size: 20px;
            }

            .logo-text {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-container">
            <img src="https://cdn.prod.website-files.com/674ed6e8a40784afab6e858a/67d32a350becfce0b9a4cb05_logo%20(3).avif" alt="Lyons Bowe" class="logo">
        </div>

        <div class="content">
            @if ($status === 'success')
                <div class="success-icon">✓</div>
                <h1>Email Verified Successfully</h1>
                <p>Thank you for verifying your email address{{ isset($user) && $user->name ? ', ' . $user->name : '' }}.</p>
                <p>Your account has been successfully activated. You can now access all features of Lyons Bowe.</p>
                <div class="message">
                    <p style="margin: 0; color: #F5D75D; font-weight: 500;">✓ Your email address has been confirmed</p>
                </div>
            @elseif ($status === 'already_verified')
                <div class="success-icon">✓</div>
                <h1>Email Already Verified</h1>
                <p>Your email address has already been verified.</p>
                <p>You can continue using your Lyons Bowe account.</p>
            @else
                <div class="error-icon">✗</div>
                <h1>Verification Failed</h1>
                <p>{{ $message ?? 'The verification link is invalid or has expired.' }}</p>
                <div class="message error">
                    <p style="margin: 0;">Please request a new verification email if you need to verify your account.</p>
                </div>
            @endif

            <div style="margin-top: 32px;">
                @if ($status === 'success' || $status === 'already_verified')
                    <a href="{{ config('app.frontend_url', config('app.url')) }}" class="btn">Continue to Lyons Bowe</a>
                @else
                    <a href="{{ config('app.frontend_url', config('app.url')) }}" class="btn btn-secondary">Return to Home</a>
                @endif
            </div>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} Lyons Bowe. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
