# Secretos y KMS

El despliegue debe usar identidad de workload y referencias a secretos, no
valores escritos en el manifiesto. Mapea `schema.env` a AWS Secrets Manager +
KMS, Azure Key Vault, GCP Secret Manager/Cloud KMS o Vault, según el cloud
aprobado.

## Reglas

- Una clave KMS separada para datos clínicos/backups y otra para secretos de
  infraestructura; rotación automática y acceso por rol mínimo.
- Sin acceso humano permanente a secretos; break-glass con MFA, doble control y
  registro de auditoría.
- Versiona referencias (ARN/URI), no valores; rota OIDC, DB, Redis y correo con
  solapamiento y plan de rollback.
- Prohíbe secretos en logs, SBOM, artefactos CI, imágenes y dumps sin cifrar.
- El bucket clínico debe tener bloqueo de acceso público, TLS obligatorio,
  versionado, retención y política que exija SSE-KMS.

La validación de formato se automatiza con
`scripts/validate-production-env.sh`; la validación de permisos y rotación debe
ejecutarse en el proveedor elegido con una cuenta de staging.
