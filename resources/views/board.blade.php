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
        <nav>
            <a href="#" data-view="">Pano</a>
            <a href="#tamamlananlar" data-view="#tamamlananlar">Tamamlananlar <span id="done-count"></span></a>
            <a href="#arsiv" data-view="#arsiv">Arşiv</a>
        </nav>
        <button id="automations-button" class="ghost">⚡ Otomasyonlar</button>
        <button id="history-button" class="ghost">🕘 Geçmiş</button>
        <label id="user-switch">Kullanıcı: <select id="user-select"></select></label>
    </header>
    <main id="board"></main>
    <dialog id="card-dialog"></dialog>
    <dialog id="automations-dialog"></dialog>
    <dialog id="history-dialog"></dialog>
    <div id="toast" role="status"></div>

    <script src="/vendor/Sortable.min.js"></script>
    <script src="/board.js?v={{ filemtime(public_path('board.js')) }}"></script>
</body>
</html>
