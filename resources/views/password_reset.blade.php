<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Password Reset</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Tailwind CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.1/dist/tailwind.min.css" rel="stylesheet">

    <style>
        html, body {
            height: 100%;
            margin: 0;
        }
        .center-container {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
        }
    </style>
</head>
<body class="bg-white text-gray-800">
    <div class="center-container">
        <div class="bg-white shadow-lg rounded-lg p-8 max-w-lg w-full border-t-8 border-blue-600 text-center">
            <h1 class="text-3xl font-bold text-blue-600 mb-4">Password Reset Successful</h1>
            <p class="text-gray-700 mb-6">Your password has been reset successfully.</p>

            <div class="bg-blue-50 border border-blue-300 rounded p-4 mb-4">
                <p class="font-semibold text-blue-700 mb-1">New Password:</p>
                <p class="break-words text-blue-900 text-lg font-mono">{{ $password }}</p>
            </div>

            <div class="mt-6">
                <a href="https://nishukishe.com/login" class="inline-block bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700 transition duration-200">Go to Home</a>
            </div>
        </div>
    </div>
</body>
</html>
