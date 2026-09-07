<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $copy['title'] }} — {{ $branch->name }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f1f5f9; color: #0f172a; font-family: Arial, sans-serif; }
        .toolbar { max-width: 190mm; margin: 24px auto; padding: 0 12px; }
        .toolbar h1 { font-size: 24px; }
        .toolbar button { padding: 12px 24px; border: 0; border-radius: 8px; background: #0f766e; color: white; font: inherit; cursor: pointer; }
        .notice { padding: 20px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; }
        .sheet { width: 190mm; max-width: 100%; margin: 0 auto 10mm; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); grid-auto-rows: 130mm; gap: 8mm; break-after: page; }
        .sheet:last-child { break-after: auto; }
        .table-card { padding: 6mm; border: 1px solid #cbd5e1; border-radius: 4mm; background: white; text-align: center; page-break-inside: avoid; break-inside: avoid; overflow-wrap: anywhere; }
        .company { font-weight: 700; font-size: 15px; }
        .arabic, .context { margin-top: 2mm; font-size: 12px; line-height: 1.4; }
        .table-label { margin: 4mm 0; font-size: 30px; font-weight: 700; }
        .qr svg { display: block; margin: auto; width: 50mm; height: 50mm; }
        .card-url { margin: 3mm 0; font: 9px monospace; line-height: 1.4; direction: ltr; }
        .scan { margin-top: 3mm; font-size: 15px; font-weight: 700; }
        [hidden] { display: none !important; }
        @media print { body { background: white; } .toolbar { display: none; } .sheet { margin: 0; } }
        @media screen and (max-width: 650px) { .sheet { width: 94%; grid-template-columns: 1fr; grid-auto-rows: auto; } .table-card { min-height: 130mm; } }
    </style>
</head>
<body>
    <header class="toolbar">
        <h1>{{ $copy['title'] }} — {{ $branch->name }}</h1>
        @if ($configured && count($cards) > 0)
            <button type="button" onclick="window.print()">{{ $copy['print'] }}</button>
        @endif
        @if (! $configured)
            <p class="notice" data-testid="qr-base-url-missing">{{ $copy['missing_url'] }}</p>
        @elseif (count($cards) === 0)
            <p class="notice">{{ $copy['empty'] }}</p>
        @endif
    </header>
    @foreach (array_chunk($cards, 4) as $page)
        <section class="sheet">
            @foreach ($page as $card)
                <article class="table-card" id="table-{{ $card['uuid'] }}" data-table-uuid="{{ $card['uuid'] }}">
                    <div class="company">{{ $company->name }}</div>
                    @if ($company->name_ar)<div class="arabic" lang="ar" dir="rtl">{{ $company->name_ar }}</div>@endif
                    <div class="context">{{ $branch->name }} · {{ $card['floor'] }}</div>
                    @if ($branch->name_ar || $card['floor_ar'])
                        <div class="arabic" lang="ar" dir="rtl">{{ $branch->name_ar }} · {{ $card['floor_ar'] }}</div>
                    @endif
                    <div class="table-label">{{ $card['label'] }}</div>
                    <div class="qr">{!! $card['svg'] !!}</div>
                    <div class="card-url">{{ $card['url'] }}</div>
                    <div class="scan">{{ $copy['scan'] }}</div>
                </article>
            @endforeach
        </section>
    @endforeach
    <script>
        // The modal's fragment selects one already-authorised card; no extra endpoint.
        if (window.location.hash.startsWith('#table-')) {
            const card = document.getElementById(window.location.hash.slice(1));
            if (card && card.classList.contains('table-card')) {
                document.querySelectorAll('.table-card').forEach(element => { element.hidden = element !== card; });
                document.querySelectorAll('.sheet').forEach(element => { element.hidden = !element.contains(card); });
            }
        }
    </script>
</body>
</html>
