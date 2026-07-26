@extends('documents.layout')

@section('title', 'Factura sanitaria')

@section('content')
    <section class="hero">
        <table class="hero-table" role="presentation">
            <tr>
                <td class="hero-primary">
                    <div class="eyebrow">Documento de facturación</div>
                    <h1>Factura sanitaria</h1>
                    <p class="subtitle">Número {{ $invoice['number'] }}</p>
                </td>
                <td class="hero-status">
                    <span class="status-pill">{{ $invoice['status'] }}</span>
                </td>
            </tr>
        </table>
    </section>

    <section class="section">
        <table class="detail-table" role="presentation">
            <tr>
                <td>
                    <span class="label">Emitida por</span>
                    <span class="value">{{ $organization['name'] }}<br>{{ $organization['clinicName'] }}</span>
                </td>
                <td>
                    <span class="label">Paciente</span>
                    <span class="value">{{ $patient['name'] }}</span>
                    @if($patient['address'])
                        <br><span class="small muted">{{ $patient['address'] }}</span>
                    @endif
                    @if($patient['email'])
                        <br><span class="small muted">{{ $patient['email'] }}</span>
                    @endif
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">Fecha de emisión</span>
                    <span class="value">{{ $invoice['issuedOn'] }}</span>
                </td>
                <td>
                    <span class="label">Fecha de vencimiento</span>
                    <span class="value">{{ $invoice['dueOn'] ?? 'Pago a la recepción' }}</span>
                </td>
            </tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">Detalle de servicios</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width: 14%;">Código</th>
                    <th style="width: 38%;">Descripción</th>
                    <th class="number" style="width: 10%;">Cant.</th>
                    <th class="number" style="width: 14%;">Precio</th>
                    <th class="number" style="width: 10%;">Imp.</th>
                    <th class="number" style="width: 14%;">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice['items'] as $item)
                    <tr>
                        <td>{{ $item['code'] }}</td>
                        <td>{{ $item['description'] }}</td>
                        <td class="number">{{ $item['quantity'] }}</td>
                        <td class="number">{{ $item['unitPrice'] }}</td>
                        <td class="number">{{ $item['tax'] }}</td>
                        <td class="number"><strong>{{ $item['total'] }}</strong></td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals" role="presentation">
            <tr>
                <td>Base imponible</td>
                <td class="amount">{{ $invoice['subtotal'] }}</td>
            </tr>
            <tr>
                <td>Impuestos</td>
                <td class="amount">{{ $invoice['tax'] }}</td>
            </tr>
            <tr class="grand-total">
                <td>Total</td>
                <td class="amount">{{ $invoice['total'] }}</td>
            </tr>
            <tr>
                <td>Saldo pendiente</td>
                <td class="amount"><strong>{{ $invoice['balance'] }}</strong></td>
            </tr>
        </table>
    </section>

    <section class="panel">
        <div class="panel-title">Información del documento</div>
        <div class="small muted">Importes expresados en {{ $invoice['currency'] }}. Este PDF es una copia generada desde el registro de facturación del centro. Conserva el identificador {{ $document['shortId'] }} para cualquier consulta administrativa.</div>
    </section>
@endsection
