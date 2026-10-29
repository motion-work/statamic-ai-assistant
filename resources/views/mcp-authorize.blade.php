<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connect Claude</title>
    <style>
        body { margin: 0; font: 16px/1.45 system-ui, sans-serif; background: #f4f4f5; color: #18181b; }
        main { max-width: 26rem; margin: 8vh auto; padding: 1.5rem; background: #fff; border: 1px solid #e4e4e7; border-radius: 12px; }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
        p { margin: 0 0 1rem; color: #3f3f46; }
        label { display: block; font-size: 0.85rem; margin-bottom: 0.75rem; }
        input { display: block; width: 100%; margin-top: 0.25rem; padding: 0.55rem 0.65rem; border: 1px solid #d4d4d8; border-radius: 8px; box-sizing: border-box; }
        button { width: 100%; padding: 0.65rem; border: 0; border-radius: 8px; background: #18181b; color: #fff; font: inherit; }
        .error { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    <h1>Connect Claude to {{ $site }}</h1>
    <p>Sign in with your Control Panel account. Claude translates the text. This site saves it as a localization of the original entry.</p>
    @if ($error)
        <p class="error">{{ $error }}</p>
    @endif
    <form method="post" action="{{ request()->getRequestUri() }}">
        @csrf
        <label>Email
            <input type="email" name="email" value="{{ $email }}" autocomplete="username" required>
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit">Allow Claude</button>
    </form>
</main>
</body>
</html>
