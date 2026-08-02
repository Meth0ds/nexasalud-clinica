# Paquete de promoción a producción

Este directorio separa el despliegue de Internet del `infra/compose.yaml`
local. La promoción prevista es:

1. construir y firmar una imagen inmutable del API (`API_IMAGE`),
2. inyectar `infra/.env.production` desde un gestor de secretos,
3. ejecutar `scripts/validate-production-env.sh` dentro de la imagen,
4. desplegar el gateway y el worker en una red privada,
5. publicar únicamente el gateway detrás de un balanceador TLS/WAF,
6. ejecutar smoke tests, DAST autenticado y el plan de rollback.

`compose.yaml` no contiene PostgreSQL, Redis, Keycloak ni Mailpit: esos
servicios deben ser gestionados. La plantilla exige Postgres y Redis privados,
OIDC externo con MFA resistente al phishing, y un bucket privado para
documentos clínicos. No se debe arrancar el stack de producción con los datos,
usuarios o realm sintético del entorno local.

## Identidad y MFA

Configura un cliente OIDC confidencial con Authorization Code + PKCE S256,
redirect y post-logout exactos, `RS256` (o una lista aprobada), y una política
de MFA que emita `acr >= 2` y `amr` con `otp`, `fido` o `webauthn`. El API valida
issuer, firma, audiencia, nonce, estado, TTL, ACR y AMR; la aplicación no
almacena contraseñas ni tokens en el navegador.

El proveedor puede ser Keycloak `start --optimized`, Entra ID, Okta u otro IdP
OIDC que permita las mismas garantías. La selección, contrato, residencia de
datos, cuentas de emergencia y rotación de claves deben quedar aprobados por
el responsable de seguridad.

## Secretos, KMS y documentos

Inyecta `APP_KEY`, credenciales OIDC, DB/Redis, correo y AWS/S3 desde Secrets
Manager/Vault/KMS con identidad de workload; no los guardes en imágenes, CI ni
archivos `.env` persistidos. El disco `clinical` admite `s3` y cifra el PDF
antes de almacenarlo, pero el bucket debe seguir siendo privado, con SSE-KMS,
versionado, retención, bloqueo de acceso público y auditoría de lecturas.

## TLS/WAF

El `nginx.conf` productivo aplica HSTS, CSP, anti-clickjacking y límites de
carga. El balanceador externo debe terminar TLS 1.2/1.3, renovar certificados,
forzar HTTPS, aplicar WAF/rate limiting/bot protection y enviar solo sus IPs en
`X-Forwarded-*`. `TRUSTED_HOSTS` y `TRUSTED_PROXIES` son obligatorios en
`APP_ENV=production`; nunca uses `*` sin una justificación aprobada.

## Datos y recuperación

Usa backups automáticos cifrados y separados de la cuenta de producción. Los
scripts `backup-postgres.sh` y `restore-postgres.sh` proporcionan una ruta
portable con cifrado local, checksum y confirmación explícita; en producción
deben sustituirse o envolverse con KMS, retención inmutable y un job de restore
aislado. Prueba semanalmente un restore completo, valida migraciones, permisos,
documentos y colas, y registra RPO/RTO reales.

## Operación

Los workers son procesos largos: durante cada release ejecuta `php artisan
queue:restart` tras desplegar código compatible y espera a que el supervisor
los reemplace. Publica `/up` como liveness y `/ready` como readiness. Centraliza
los logs JSON de `stderr` con el `X-Trace-Id`, elimina PII/tokens, y configura
alertas de error rate, p95/p99, readiness, cola, DB, Redis, backup y certificados.

La imagen debe identificarse por digest, pasar los jobs de CI, generar SBOM y
tener rollback a la versión anterior. No se autoriza tráfico clínico hasta
completar el DAST/pentest, el restore de prueba y la revisión legal sanitaria.
