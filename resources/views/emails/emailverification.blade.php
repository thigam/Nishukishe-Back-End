<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Nishikishe - Verify Your Email</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
    <header style="text-align: center; padding: 20px;">
        <img src="{{ asset('images/nishikishe-logo.png') }}" alt="Nishikishe Logo" height="60">
    </header>

    <main style="max-width: 600px; margin: auto; padding: 20px;">
        <h1>Email Verification</h1>
        <p>Hello {{ $user->name }},</p>
        <p>Click the button below to verify your email address:</p>

        <a href="{{ $verificationUrl }}" style="
            display: inline-block;
            padding: 12px 20px;
            background-color: #38a169;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        ">
            Verify Email
        </a>

        <p style="margin-top: 20px;">If you didn’t create a Nishikishe account, you can ignore this message.</p>
        <p>Thank you for using Nishikishe!</p>
        <p>Best regards,<br>Nishikishe Team</p>
    </main>

    <script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
