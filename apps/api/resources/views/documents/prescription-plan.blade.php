@extends('documents.layout')

@section('title', 'Plan de medicación')

@section('content')
    <section class="hero">
        <table class="hero-table" role="presentation">
            <tr>
                <td class="hero-primary">
                    <div class="eyebrow">Documento clínico</div>
                    <h1>Plan de medicación</h1>
                    <p class="subtitle">Pauta registrada en una prescripción firmada por el profesional responsable.</p>
                </td>
                <td class="hero-status">
                    <span class="status-pill">{{ $prescription['status'] }}</span>
                </td>
            </tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">Datos de la prescripción</h2>
        <table class="detail-table" role="presentation">
            <tr>
                <td>
                    <span class="label">Paciente</span>
                    <span class="value">{{ $patient['name'] }}</span>
                </td>
                <td>
                    <span class="label">Historia clínica</span>
                    <span class="value">{{ $patient['recordNumber'] ?? 'No disponible' }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">Profesional prescriptor</span>
                    <span class="value">{{ $prescription['professional'] }}</span>
                </td>
                <td>
                    <span class="label">Firma registrada</span>
                    <span class="value">{{ $prescription['signedAt'] }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">Referencia externa</span>
                    <span class="value">{{ $prescription['reference'] ?? 'No disponible' }}</span>
                </td>
                <td>
                    <span class="label">Válida hasta</span>
                    <span class="value">{{ $prescription['validUntil'] ?? 'Según indicación clínica' }}</span>
                </td>
            </tr>
        </table>
        @if($prescription['indication'])
            <div class="panel">
                <span class="label">Indicación clínica</span>
                <span class="value">{{ $prescription['indication'] }}</span>
            </div>
        @endif
    </section>

    <section class="section">
        <h2 class="section-title">Medicamentos y pauta</h2>
        @foreach($prescription['medications'] as $index => $medication)
            @if($index === 1 || ($index > 1 && (($index - 1) % 3 === 0)))
                <div class="page-break"></div>
            @endif
            <article class="medication-card">
                <div>
                    <span class="medication-index">{{ $index + 1 }}</span>
                    <span class="medication-name">{{ $medication['name'] }}</span>
                </div>
                @if($medication['code'])
                    <div class="medication-code">Código de medicamento: {{ $medication['code'] }}</div>
                @endif

                <table class="detail-table medication-details" role="presentation">
                    <tr>
                        <td>
                            <span class="label">Dosis</span>
                            <span class="value">{{ $medication['dose'] }}</span>
                        </td>
                        <td>
                            <span class="label">Vía</span>
                            <span class="value">{{ $medication['route'] }}</span>
                        </td>
                        <td>
                            <span class="label">Frecuencia</span>
                            <span class="value">{{ $medication['frequency'] }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <span class="label">Duración</span>
                            <span class="value">{{ $medication['duration'] ?? 'Según evolución' }}</span>
                        </td>
                        <td>
                            <span class="label">Cantidad</span>
                            <span class="value">{{ $medication['quantity'] }}</span>
                        </td>
                        <td></td>
                    </tr>
                </table>

                @if($medication['instructions'])
                    <div class="instructions">
                        <span class="label">Instrucciones</span>
                        <span class="value">{{ $medication['instructions'] }}</span>
                    </div>
                @endif
            </article>
        @endforeach
    </section>

    <section class="signature-box">
        <table class="signature-table" role="presentation">
            <tr>
                <td style="width: 46px;">
                    <div class="signature-mark">✓</div>
                </td>
                <td>
                    <div class="panel-title">Registro de firma clínica</div>
                    <div class="small muted">Prescripción firmada en el sistema por {{ $prescription['professional'] }} el {{ $prescription['signedAt'] }}. Identificador de documento: {{ $document['shortId'] }}.</div>
                </td>
            </tr>
        </table>
    </section>

    <section class="panel panel-warning" style="margin-top: 14px;">
        Sigue exactamente la pauta indicada. No modifiques ni interrumpas el tratamiento sin consultar con un profesional sanitario. La dispensación está sujeta a la prescripción electrónica válida y a las comprobaciones del sistema autorizado.
    </section>
@endsection
