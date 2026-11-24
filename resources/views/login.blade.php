<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giriş · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="/board.css?v={{ filemtime(public_path('board.css')) }}">
</head>
<body class="auth">
    <form method="post" action="/login">
        @csrf
        <h1>{{ config('app.name') }}</h1>
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif
        <label>E-posta
            <input type="email" name="email" value="{{ old('email') }}" placeholder="ad.soyad@ardic.com" autocomplete="username" required autofocus>
        </label>
        <label>Şifre
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="primary">Giriş yap</button>
    </form>
</body>
</html>
