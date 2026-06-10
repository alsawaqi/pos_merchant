{{-- Phase D6 — PDF export layout. Rendered by ReportPdfExporter via dompdf.
     Keep it dompdf-safe: no images (container lacks gd), no remote assets,
     DejaVu Sans (bundled, UTF-8). {{ }} only — cell data is user-originated. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 9px; color: #1e293b; margin: 24px; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        .window { font-size: 10px; color: #64748b; margin: 0 0 14px; }
        h2 { font-size: 11px; margin: 16px 0 4px; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        th, td { border: 0.5px solid #cbd5e1; padding: 3px 6px; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-weight: bold; }
        td.num { text-align: right; }
        .kv th { width: 35%; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="window">{{ $dateFrom }} &mdash; {{ $dateTo }}</p>

    @foreach ($sections as $section)
        <h2>{{ str_replace('_', ' ', $section->name) }}</h2>

        @if ($section->kind === \App\Support\ReportSection::KIND_TABLE)
            <table>
                <thead>
                    <tr>
                        @foreach ($section->columns as $column)
                            <th>{{ str_replace('_', ' ', $column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section->rows as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td class="{{ preg_match('/^-?\d+(\.\d+)?$/', $cell) === 1 ? 'num' : '' }}">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <table class="kv">
                <tbody>
                    @foreach ($section->rows as $row)
                        <tr>
                            <th>{{ str_replace('_', ' ', $row[0] ?? '') }}</th>
                            <td class="{{ preg_match('/^-?\d+(\.\d+)?$/', $row[1] ?? '') === 1 ? 'num' : '' }}">{{ $row[1] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
