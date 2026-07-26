<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>@yield('title') | {{ $organization['name'] }}</title>
    <style>
        @page {
            margin: 40px 44px 72px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #17343a;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9.5pt;
            line-height: 1.45;
        }

        .page-header {
            position: static;
            height: 68px;
            margin-bottom: 20px;
            border-bottom: 1px solid #d6e5e3;
        }

        .brand-table {
            width: 100%;
            border-collapse: collapse;
        }

        .brand-table td {
            padding: 0;
            vertical-align: middle;
        }

        .brand-mark-cell {
            width: 48px;
        }

        .brand-mark {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            background: #0d776d;
            color: #ffffff;
            font-size: 16pt;
            font-weight: bold;
            line-height: 38px;
            text-align: center;
        }

        .brand-name {
            color: #103f49;
            font-size: 13pt;
            font-weight: bold;
            letter-spacing: .15px;
        }

        .brand-clinic {
            margin-top: 2px;
            color: #638087;
            font-size: 8pt;
        }

        .document-meta {
            color: #638087;
            font-size: 7.6pt;
            line-height: 1.5;
            text-align: right;
        }

        .page-footer {
            position: fixed;
            right: 0;
            bottom: -50px;
            left: 0;
            height: 38px;
            border-top: 1px solid #d6e5e3;
            color: #6c858a;
            font-size: 7pt;
            line-height: 1.35;
            padding-top: 8px;
        }

        .footer-table {
            width: 100%;
            border-collapse: collapse;
        }

        .footer-table td {
            padding: 0;
            vertical-align: top;
        }

        .footer-right {
            text-align: right;
            white-space: nowrap;
        }

        .eyebrow {
            margin-bottom: 7px;
            color: #0d776d;
            font-size: 7.5pt;
            font-weight: bold;
            letter-spacing: 1.2px;
            text-transform: uppercase;
        }

        h1 {
            margin: 0;
            color: #103f49;
            font-size: 24pt;
            font-weight: normal;
            line-height: 1.15;
        }

        .subtitle {
            margin: 8px 0 0;
            color: #5a767c;
            font-size: 10pt;
        }

        .hero {
            margin-bottom: 22px;
            padding: 18px 20px;
            border: 1px solid #d8e7e5;
            border-left: 5px solid #0d8c7e;
            border-radius: 8px;
            background: #f4faf9;
        }

        .hero-table,
        .detail-table,
        .summary-table,
        .signature-table {
            width: 100%;
            border-collapse: collapse;
        }

        .hero-table td,
        .detail-table td,
        .signature-table td {
            vertical-align: top;
        }

        .hero-primary {
            width: 68%;
        }

        .hero-status {
            width: 32%;
            text-align: right;
        }

        .status-pill {
            display: inline-block;
            padding: 5px 9px;
            border: 1px solid #9fcfc9;
            border-radius: 14px;
            background: #e5f5f2;
            color: #09685f;
            font-size: 7.7pt;
            font-weight: bold;
            letter-spacing: .25px;
            text-transform: uppercase;
        }

        .section {
            margin: 0 0 18px;
        }

        .section-title {
            margin: 0 0 9px;
            padding-bottom: 6px;
            border-bottom: 1px solid #dbe8e7;
            color: #264d55;
            font-size: 9pt;
            font-weight: bold;
            letter-spacing: .65px;
            text-transform: uppercase;
        }

        .detail-table {
            table-layout: fixed;
        }

        .detail-table td {
            width: 50%;
            padding: 8px 12px 8px 0;
        }

        .detail-table td:nth-child(even) {
            padding-right: 0;
            padding-left: 12px;
        }

        .label {
            display: block;
            margin-bottom: 2px;
            color: #71898e;
            font-size: 7.3pt;
            font-weight: bold;
            letter-spacing: .35px;
            text-transform: uppercase;
        }

        .value {
            color: #17343a;
            font-size: 9.5pt;
        }

        .prominent-value {
            color: #103f49;
            font-size: 14pt;
            font-weight: bold;
        }

        .panel {
            padding: 13px 15px;
            border-radius: 7px;
            background: #f5f7f4;
            page-break-inside: avoid;
        }

        .panel-warning {
            border: 1px solid #ead9b5;
            background: #fff9ed;
            color: #5f4a22;
        }

        .panel-title {
            margin: 0 0 6px;
            color: #294e54;
            font-size: 9pt;
            font-weight: bold;
        }

        .clean-list {
            margin: 7px 0 0 16px;
            padding: 0;
        }

        .clean-list li {
            margin-bottom: 4px;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.3pt;
        }

        .data-table thead {
            display: table-header-group;
        }

        .data-table th {
            padding: 8px 7px;
            border-bottom: 2px solid #9fc9c4;
            background: #edf6f4;
            color: #31565c;
            font-size: 7pt;
            font-weight: bold;
            letter-spacing: .35px;
            text-align: left;
            text-transform: uppercase;
        }

        .data-table td {
            padding: 9px 7px;
            border-bottom: 1px solid #e1ebea;
            vertical-align: top;
        }

        .data-table .number {
            text-align: right;
            white-space: nowrap;
        }

        .medication-card {
            margin-bottom: 11px;
            padding: 13px 15px;
            border: 1px solid #dbe8e7;
            border-radius: 7px;
            page-break-inside: avoid;
        }

        .medication-index {
            display: inline-block;
            width: 22px;
            height: 22px;
            margin-right: 7px;
            border-radius: 11px;
            background: #0d776d;
            color: #ffffff;
            font-size: 8pt;
            font-weight: bold;
            line-height: 22px;
            text-align: center;
        }

        .medication-name {
            color: #173f47;
            font-size: 11pt;
            font-weight: bold;
        }

        .medication-code {
            margin-left: 31px;
            color: #789094;
            font-size: 7.2pt;
        }

        .medication-details {
            margin-top: 10px;
        }

        .medication-details td {
            width: 33.333%;
            padding: 5px 8px 5px 0;
        }

        .instructions {
            margin-top: 9px;
            padding-top: 8px;
            border-top: 1px solid #e0e9e8;
        }

        .totals {
            width: 44%;
            margin: 13px 0 0 auto;
            border-collapse: collapse;
        }

        .totals td {
            padding: 5px 7px;
            border-bottom: 1px solid #e1ebea;
        }

        .totals .amount {
            text-align: right;
            white-space: nowrap;
        }

        .totals .grand-total td {
            padding-top: 9px;
            border-top: 2px solid #0d776d;
            border-bottom: 0;
            color: #103f49;
            font-size: 12pt;
            font-weight: bold;
        }

        .signature-box {
            margin-top: 18px;
            padding: 14px 15px;
            border: 1px solid #cfe0de;
            border-radius: 7px;
            background: #f8fbfa;
            page-break-inside: avoid;
        }

        .signature-mark {
            width: 34px;
            height: 34px;
            border: 2px solid #0d776d;
            border-radius: 17px;
            color: #0d776d;
            font-size: 14pt;
            font-weight: bold;
            line-height: 30px;
            text-align: center;
        }

        .muted {
            color: #71898e;
        }

        .small {
            font-size: 7.8pt;
        }

        .text-right {
            text-align: right;
        }

        .nowrap {
            white-space: nowrap;
        }

        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
    <header class="page-header">
        <table class="brand-table" role="presentation">
            <tr>
                <td class="brand-mark-cell">
                    <div class="brand-mark">N</div>
                </td>
                <td>
                    <div class="brand-name">{{ $organization['name'] }}</div>
                    <div class="brand-clinic">{{ $organization['clinicName'] }}</div>
                </td>
                <td class="document-meta">
                    Documento {{ $document['shortId'] }}<br>
                    Generado {{ $document['generatedAt'] }}
                </td>
            </tr>
        </table>
    </header>

    <footer class="page-footer">
        <table class="footer-table" role="presentation">
            <tr>
                <td>Documento confidencial. Contiene datos personales de salud sujetos a acceso restringido.</td>
                <td class="footer-right">{{ $document['templateVersion'] }} · {{ $document['shortId'] }}</td>
            </tr>
        </table>
    </footer>

    <main>
        @yield('content')
    </main>
</body>
</html>
