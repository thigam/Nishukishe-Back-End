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
        <h1>Password Reset</h1>
        <p>Hello,</p>
        <p>Click the button below to reset your password:</p>

        <a href="{{ $resetUrl }}" style="
            display: inline-block;
            padding: 12px 20px;
            background-color: #a15238ff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        ">
            Reset Password
        </a>

        <p style="margin-top: 20px;">If you do not have a  Nishikishe account, you can ignore this message.</p>
        <p>Thank you for using Nishikishe!</p>
        <p>Best regards,<br>Nishikishe Team</p>
    </main>

    <script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
