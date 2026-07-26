@extends('documents.layout')

@section('title', 'Confirmación de cita')

@section('content')
    <section class="hero">
        <table class="hero-table" role="presentation">
            <tr>
                <td class="hero-primary">
                    <div class="eyebrow">Atención programada</div>
                    <h1>Confirmación de cita</h1>
                    <p class="subtitle">Tu cita está registrada con los datos que figuran a continuación.</p>
                </td>
                <td class="hero-status">
                    <span class="status-pill">{{ $appointment['status'] }}</span>
                </td>
            </tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">Cuándo y dónde</h2>
        <table class="detail-table" role="presentation">
            <tr>
                <td>
                    <span class="label">Fecha</span>
                    <span class="prominent-value">{{ $appointment['date'] }}</span>
                </td>
                <td>
                    <span class="label">Hora</span>
                    <span class="prominent-value">{{ $appointment['startTime'] }} - {{ $appointment['endTime'] }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">Centro</span>
                    <span class="value">{{ $appointment['clinic'] }}</span>
                </td>
                <td>
                    <span class="label">Modalidad</span>
                    <span class="value">{{ $appointment['channel'] }}@if($appointment['room']) · {{ $appointment['room'] }}@endif</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">Tipo de consulta</span>
                    <span class="value">{{ $appointment['type'] }}</span>
                </td>
                <td>
                    <span class="label">Profesional</span>
                    <span class="value">{{ $appointment['professional'] }}</span>
                </td>
            </tr>
        </table>
    </section>

    <section class="section">
        <h2 class="section-title">Paciente</h2>
        <table class="detail-table" role="presentation">
            <tr>
                <td>
                    <span class="label">Nombre</span>
                    <span class="value">{{ $patient['name'] }}</span>
                </td>
                <td>
                    <span class="label">Historia clínica</span>
                    <span class="value">{{ $patient['recordNumber'] ?? 'No disponible' }}</span>
                </td>
            </tr>
            <tr>
                <td>
                    <span class="label">Fecha de nacimiento</span>
                    <span class="value">{{ $patient['birthDate'] ?? 'No disponible' }}</span>
                </td>
                <td>
                    <span class="label">Zona horaria</span>
                    <span class="value">{{ $appointment['timezone'] }}</span>
                </td>
            </tr>
        </table>
    </section>

    <section class="panel panel-warning">
        <div class="panel-title">Antes de acudir</div>
        <ul class="clean-list">
            <li>Llega con 10 minutos de antelación para completar la admisión.</li>
            <li>Trae tu documento identificativo, tarjeta sanitaria y los informes relevantes.</li>
            <li>Si no puedes acudir, cambia o cancela la cita con la mayor antelación posible.</li>
            <li>En caso de urgencia médica, utiliza los servicios de emergencia; este documento no acredita atención urgente.</li>
        </ul>
    </section>
@endsection
