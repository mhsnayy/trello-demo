<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Şifre değiştir · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="/board.css?v={{ filemtime(public_path('board.css')) }}">
</head>
<body class="auth">
    <form method="post" action="/password">
        @csrf
        <h1>Şifre değiştir</h1>
        <p class="muted">
            {{ auth()->user()->email }}
            @if (auth()->user()->must_change_password)
                <br>Devam etmek için kendinize yeni bir şifre belirleyin.
            @endif
        </p>
        @if ($errors->any())
            <p class="error" role="alert">{{ $errors->first() }}</p>
        @endif
        <label>Mevcut şifre
            <input type="password" name="current_password" autocomplete="current-password" required autofocus>
        </label>
        <label>Yeni şifre (en az 8 karakter)
            <input type="password" name="password" autocomplete="new-password" minlength="8" maxlength="72" required>
        </label>
        <label>Yeni şifre (tekrar)
            <input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" maxlength="72" required>
        </label>
        <button class="primary">Kaydet</button>
        @unless (auth()->user()->must_change_password)
            <a href="/">Vazgeç</a>
        @endunless
    </form>
    <form method="post" action="/logout" class="plain">@csrf<button>Çıkış yap</button></form>
</body>
</html>
