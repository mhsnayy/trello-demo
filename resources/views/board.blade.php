<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    <link rel="stylesheet" href="/board.css?v={{ filemtime(public_path('board.css')) }}">
</head>
<body>
    <header>
        <h1>{{ config('app.name') }}</h1>
        <button id="automations-button" class="ghost">⚡ Otomasyonlar</button>
        <label id="user-switch">Kullanıcı: <select id="user-select"></select></label>
    </header>
    <main id="board"></main>
    <dialog id="card-dialog"></dialog>
    <dialog id="automations-dialog"></dialog>
    <div id="toast" role="status"></div>

    <script src="/vendor/Sortable.min.js"></script>
    <script src="/board.js?v={{ filemtime(public_path('board.js')) }}"></script>
</body>
</html>
