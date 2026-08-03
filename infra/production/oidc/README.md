# OIDC/MFA productivo

Crear un cliente confidencial para el BFF con:

- Authorization Code + PKCE S256; implicit, password/direct grants y wildcard
  redirects desactivados.
- Redirect exacto `https://<portal>/auth/callback` y post-logout exacto.
- Issuer HTTPS estable, discovery/JWKS cacheados con TTL, rotación de signing
  keys y algoritmos permitidos (`RS256` como mínimo).
- MFA obligatorio con evidencia en token (`acr` fuerte y `amr` que contenga
  `fido`, `hwk`, `passkey` o `webauthn`). Para datos clínicos y firma,
  exigir WebAuthn/FIDO2; SMS y OTP no son suficientes como único MFA.
- Anti-brute-force, lockout gradual, recuperación de cuenta con verificación
  reforzada, sesiones revocables y cuentas de emergencia auditadas.
- Sin autorregistro; grupos/claims mínimos y mapeo explícito a permisos del
  tenant. Nunca aceptar roles administrativos arbitrarios desde el navegador.

Validar en staging login, logout, callback inválido, replay de state/nonce,
rotación de JWKS, expiración, `acr/amr`, revocación de sesión y cambio de tenant.
