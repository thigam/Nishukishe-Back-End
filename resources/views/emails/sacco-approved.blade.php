<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Account Approved</title>
</head>
<body>
    <h2>Hello {{ $user->name }},</h2>

    <p>Your account has been <strong>approved</strong>! 🎉</p>
    <p>You can now log in and start using our services.</p>

    @if ($frontendUrl)
        <p>To access your account, please use the following temporary URL: {{ $frontendUrl }}</p>
    @endif

    <p>Thank you,<br>
    The Nishukishe Team</p>
</body>
</html>
