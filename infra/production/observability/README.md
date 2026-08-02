# Observabilidad de producción

## Señales mínimas

- Logs JSON centralizados desde `LOG_CHANNEL=stderr`, con `X-Trace-Id` como
  correlación; nunca enviar tokens, contraseñas, cuerpos de petición ni PHI.
- RED en el balanceador/API: tasa, errores por clase HTTP y p50/p95/p99 por
  ruta normalizada (`/api/v1/patients/{patient}`), no por URL o usuario.
- USE del worker/DB/Redis: saturación, edad de cola, jobs fallidos, conexiones,
  latencia y espacio.
- Auditoría de accesos clínicos, documentos, permisos, sesiones revocadas y
  cambios administrativos, con retención y acceso separado.

## Alertas bloqueantes

1. Error rate > 1% durante 5 minutos: página al on-call.
2. p99 API > 2 s durante 10 minutos: ticket urgente.
3. `/ready` no disponible durante 2 minutos: página.
4. Job más antiguo > 10 minutos o fallos consecutivos: página.
5. Backup ausente, checksum inválido o restore de prueba fallido: página.
6. Certificado TLS con menos de 14 días o cambio de issuer OIDC: ticket y
   revisión de seguridad.

Cada alerta debe enlazar a un runbook, tener propietario y probarse en staging.
El proveedor puede ser OpenTelemetry + Prometheus/Grafana, Datadog, Sentry o
el servicio equivalente del cloud elegido; la elección y residencia deben
estar documentadas antes de activar datos reales.
