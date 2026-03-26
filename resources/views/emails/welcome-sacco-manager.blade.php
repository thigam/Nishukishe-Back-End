<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Nishukishe</title>
    <style>
        body {
            font-family: 'DM Sans', Arial, sans-serif;
            background-color: #f8fafc;
            margin: 0;
            padding: 0;
            color: #1e293b;
        }

        .container {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);
        }

        .header {
            background-color: #2563eb;
            padding: 32px;
            text-align: center;
        }

        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 24px;
            font-weight: 700;
        }

        .content {
            padding: 40px;
            line-height: 1.6;
        }

        .content h2 {
            color: #0f172a;
            font-size: 20px;
            margin-top: 0;
        }

        .benefits {
            margin: 24px 0;
            padding: 0;
            list-style: none;
        }

        .benefits li {
            margin-bottom: 12px;
            padding-left: 28px;
            position: relative;
        }

        .benefits li::before {
            content: "✓";
            position: absolute;
            left: 0;
            color: #2563eb;
            font-weight: bold;
        }

        .cta-container {
            text-align: center;
            margin-top: 32px;
        }

        .button {
            background-color: #2563eb;
            color: #ffffff !important;
            padding: 14px 28px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            display: inline-block;
            transition: background-color 0.2s;
        }

        .footer {
            background-color: #f1f5f9;
            padding: 24px;
            text-align: center;
            font-size: 14px;
            color: #64748b;
        }

        .footer p {
            margin: 4px 0;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>Nishukishe</h1>
        </div>
        <div class="content">
            <h2>Welcome to the Team, {{ $user->name }}!</h2>
            <p>We're thrilled to have you join us as a Sacco Manager. Nishukishe is dedicated to transforming public
                transport in Nairobi, and your role is crucial to our success.</p>

            <p>With your new manager dashboard, you can now:</p>
            <ul class="benefits">
                <li>Manage and update your Sacco's routes.</li>
                <li>Create new routes.</li>
                <li>Create and update stages.</li>
                <li>Update your contacts for general outreach (e.g. car hire) in the profile page.</li>
            </ul>

            <div class="cta-container">
                <a href="{{ $loginUrl ?? 'https://nishukishe.com/login' }}" class="button">Access Your Dashboard</a>
            </div>

            <p style="margin-top: 32px;">We'll be reaching out again soon with more information to help you get started.
                In the meantime, if you have any questions, feel free to reach out to us at <a
                    href="mailto:saccos@nishukishe.com"
                    style="color: #2563eb; font-weight: 600;">saccos@nishukishe.com</a>.</p>

            <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e2e8f0; text-align: center;">
                <p style="margin-bottom: 12px; font-weight: 600; color: #64748b;">Follow our journey:</p>
                <div style="display: flex; justify-content: center; gap: 16px;">
                    <a href="https://twitter.com/nishukishe" style="text-decoration: none; color: #2563eb;">Twitter</a>
                    <a href="https://facebook.com/nishukishe"
                        style="text-decoration: none; color: #2563eb;">Facebook</a>
                    <a href="https://instagram.com/nishukishe"
                        style="text-decoration: none; color: #2563eb;">Instagram</a>
                    <a href="https://www.linkedin.com/company/nishukishe"
                        style="text-decoration: none; color: #2563eb;">LinkedIn</a>
                </div>
            </div>

            <p style="margin-top: 32px;">Best regards,<br><strong>The Nishukishe Team</strong></p>
        </div>
        <div class="footer">
            <p>&copy; {{ date('Y') }} Nishukishe. All rights reserved.</p>
            <p>Nairobi, Kenya</p>
        </div>
    </div>
</body>

</html>