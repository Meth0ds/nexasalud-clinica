@extends('documents.layout')

@section('title', 'Vista previa simulada')

@section('content')
    <section class="hero">
        <table class="hero-table" role="presentation">
            <tr>
                <td class="hero-primary">
                    <div class="eyebrow">DemostraciÃ³n segura</div>
                    <h1>Vista previa de factura</h1>
                    <p class="subtitle">Referencia {{ $invoice['number'] ?? 'sin nÃºmero asignado' }}</p>
                </td>
                <td class="hero-status">
                    <span class="status-pill">Simulado</span>
                </td>
            </tr>
        </table>
    </section>

    <section class="panel panel-warning">
        <div class="panel-title">Sin validez fiscal ni efecto de pago</div>
        <div class="small">Este documento existe exclusivamente para una demostraciÃ³n. No es una factura, no acredita un pago y no debe utilizarse para fines contables, fiscales ni asistenciales.</div>
    </section>

    <section class="section">
        <table class="detail-table" role="presentation">
            <tr>
                <td>
                    <span class="label">Centro</span>
                    <span class="value">{{ $organization['name'] }}<br>{{ $organization['clinicName'] }}</span>
                </td>
                <td>
                    <span class="label">Paciente mostrado</span>
                    <span class="value">{{ $patient['name'] }}</span>
                    <br><span class="small muted">Historia {{ $patient['recordNumber'] ?? 'no disponible' }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">Fecha registrada</span>
                    <span class="value">{{ $invoice['issuedOn'] ?? 'sin fecha' }}</span>
                </td>
                <td>
                    <span class="label">Estado mostrado</span>
                    <span class="value">{{ $invoice['status'] }}</span>
                </td>
            </tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">Resumen de importes mostrados</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 62%;">Concepto</th>
                    <th class="number" style="width: 18%;">Cantidad</th>
                    <th class="number" style="width: 20%;">Importe</th>
                </tr>
            </thead>
            <tbody>
                @forelse($invoice['items'] as $item)
                    <tr>
                        <td>{{ $item['description'] }}</td>
                        <td class="number">{{ $item['quantity'] }}</td>
                        <td class="number">{{ $item['total'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">No hay lÃ­neas disponibles para esta vista de demostraciÃ³n.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <table class="totals" role="presentation">
            <tr class="grand-total">
                <td>Total mostrado</td>
                <td class="amount">{{ $invoice['total'] }}</td>
            </tr>
            <tr>
                <td>Saldo mostrado</td>
                <td class="amount">{{ $invoice['balance'] }}</td>
            </tr>
        </table>
    </section>

    <section class="panel">
        <div class="panel-title">Trazabilidad de demostraciÃ³n</div>
        <div class="small muted">Generado como PDF protegido desde el portal autenticado. Identificador de demostraciÃ³n: {{ $document['shortId'] }}.</div>
    </section>
@endsection
