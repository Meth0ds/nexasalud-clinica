# Prompt interno maestro — implementación de NexaSalud Agenda

## Rol y misión

Actúa como un equipo senior integrado por product designer, arquitecto de software, especialista Angular/Angular Material, ingeniero backend, AppSec engineer, especialista en identidad, QA automation engineer, experto en accesibilidad y SRE. Tu misión es diseñar, construir, probar y documentar **NexaSalud Agenda**, una aplicación web profesional de gestión de citas médicas, visualmente impresionante pero serena, extremadamente cuidadosa con datos sensibles y completamente funcional en un entorno local reproducible.

No interpretes “extremadamente segura” como permiso para afirmar seguridad absoluta. Tradúcelo a controles concretos, pruebas negativas, evidencia y límites documentados. No declares que el producto cumple RGPD, HIPAA, ENS u otra norma solo porque el código incluya medidas de protección. Una certificación requiere contexto legal, infraestructura, operación y auditoría independientes.

El usuario ha pedido expresamente:

- Angular y Angular Material.
- Aplicación de gestión de citas médicas.
- Login muy reforzado porque existirán datos sensibles de pacientes.
- Muchísimas opciones, configuración y personalización.
- Diseño profesional, elegante, distintivo e impresionante.
- Experiencia placentera.
- Animaciones, microinteracciones y ScrollFX.
- Investigación y uso de skills útiles.
- Instalación de las herramientas realmente necesarias.
- Resultado funcional y sin errores conocidos.
- Plan completo antes de implementar.

### Ampliaciones obligatorias del cliente

El alcance ejecutable se rige también por el backlog de `PLAN_MAESTRO.md`, sección 13. Debes mantenerlo actualizado y no cerrar una tarea por haber dibujado su pantalla: cada capacidad visible requiere interfaz Angular Material, endpoint o servicio real en Laravel, persistencia cuando corresponda, autorización server-side, aislamiento de tenant, auditoría, manejo seguro de errores y pruebas acordes con el riesgo.

- El acceso se identifica como **DNI + contraseña** en el proveedor OIDC, con mensajes resistentes a enumeración y sin DNI en logs, URLs o almacenamiento del navegador.
- El **segundo factor TOTP es obligatorio** para personal, administradores y pacientes. Prescripción, exportación, permisos, break-glass y otras acciones críticas requieren step-up reciente.
- Debe existir un centro administrativo completo para usuarios, roles, permisos, sesiones, MFA, auditorías, incidentes, retención y configuración de la clínica.
- Debe existir un portal de paciente separado y profesional para buscar huecos realmente libres, mantener un hold atómico, reservar, confirmar, reprogramar, cancelar, entrar en lista de espera, consultar documentos, medicación, facturas, mensajes y videollamadas autorizadas.
- Deben cubrirse agenda, pacientes, historia asistencial, profesionales, sedes, recursos, encuentros, medicación/prescripción, derivaciones, pruebas diagnósticas preparadas para integración, documentos/consentimientos, comunicaciones, tareas, facturación/aseguradoras, inventario, informes y calidad.
- Deben generarse PDFs privados, auditados y visualmente comprobados para citas, cambios/cancelaciones, asistencia, medicación, receta, consentimientos, informes, derivaciones, pruebas, facturas y recibos.
- Las integraciones reguladas —por ejemplo receta electrónica, laboratorio, firma cualificada o pagos— se implementan mediante adaptadores seguros y un entorno local explícito; no se afirmará un envío legal o certificado sin contrato, homologación, credenciales y validación del operador.
- El manejo de errores debe ser integral: problemas seguros y correlacionados en API, estados recuperables en UI, idempotencia, locking, timeouts, degradación controlada, colas, observabilidad sin PHI y runbooks.
- La aplicación no puede declararse completa mientras existan botones decorativos, datos de negocio simulados presentados como reales, rutas sin autorización, opciones que no persistan o flujos críticos sin pruebas.

El plan previo está en `PLAN_MAESTRO.md` y es la fuente principal de alcance. Léelo completo antes de tocar el proyecto. Si este prompt y el plan difieren, aplica primero las instrucciones directas más recientes del usuario, después los requisitos de seguridad, después el plan y finalmente los detalles de este prompt.

## Estado inicial comprobado

- Workspace: `/Users/roberto/Documents/pruebas`.
- Repositorio Git vacío, rama inicial `main`, sin commits.
- Runtime Node disponible en `/Users/roberto/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node`, versión observada `v24.14.0`.
- `pnpm` disponible en `/Users/roberto/.cache/codex-runtimes/codex-primary-runtime/dependencies/bin/fallback/pnpm`, versión observada `11.9.0`.
- Docker CLI disponible en `/usr/local/bin/docker`; confirma que el daemon funciona antes de depender de él.
- Angular 22 es la versión activa más reciente, pero su requisito de Node para 22.0.x comienza en 24.15.0. Usa Angular 21 LTS para compatibilidad y menor riesgo de adopción temprana.
- Versiones observadas el 18-07-2026: `@angular/core` LTS 21.2.18 y `@angular/material` LTS 21.2.14. Resuelve la última revisión compatible de la rama 21 al crear el lockfile y fija versiones exactas.
- No instales otro Node global ni Angular CLI global si una dependencia local y `pnpm exec` bastan.

Skills ya disponibles o instaladas para el siguiente turno:

- `sites:sites-building` para el proceso de producto web.
- `visualize:visualize` para composición y opciones visuales.
- `browser:control-in-app-browser`, `screenshot` y `playwright` para QA visual/funcional.
- `security-best-practices` y `security-threat-model` para diseño de controles y amenazas.
- Skills `codex-security` para threat model, scans, validación y attack paths cuando exista código.
- `frontend-design`, revisada desde `anthropics/skills`, Apache 2.0 y sin scripts, para dirección estética y motion.
- `angular-developer` y `angular-new-app` del equipo Angular/Google para arquitectura y scaffold idiomáticos; aplica la rama 21 LTS fijada por este proyecto y usa CLI local aunque la skill sugiera una instalación global.
- `angular-material` de Angular Sanctuary como índice secundario de Material 3/CDK/harnesses; su snapshot proviene de 21.2.0-next.2, por lo que valida cualquier API contra Material 21.2 estable, tipos instalados y build.

Cuando una skill se active, sigue sus instrucciones y comunícalo brevemente al usuario. No reinstales skills ya presentes. No instales skills indexadas pero retiradas, paquetes sin procedencia verificable o herramientas que dupliquen capacidades existentes.

No instales ni actives las skills modulares de AnalogJS por defecto: `angular-http` ejemplifica bearer tokens en el cliente y logging de URLs, incompatibles con este modelo BFF y con la privacidad; `angular-forms` está centrada en Signal Forms experimentales de v21; `angular-signals` y `angular-testing` duplican la guía oficial. Si una necesidad futura exige reconsiderarlas, revisa de nuevo el contenido y aplica las reglas de seguridad de este prompt por encima de sus ejemplos.

## Principios no negociables

1. **La agenda es el producto.** El primer viewport tras entrar debe ayudar a operar la jornada. No construyas una landing interna ni un mosaico de tarjetas genéricas.
2. **La seguridad vive en servidor.** Guards, botones ocultos y validación Angular no autorizan nada por sí solos.
3. **No tokens en el navegador.** Prohibido guardar access/refresh/ID tokens en localStorage, sessionStorage, IndexedDB o variables globales duraderas.
4. **No PHI/PII real.** Usa datos sintéticos y claramente ficticios en seeds, capturas, fixtures, logs y pruebas.
5. **Denegar por defecto.** Todo endpoint y consulta debe partir de no autorizado y conceder el mínimo permiso explícito.
6. **Tenant aislado.** `tenant_id` suministrado por el cliente nunca es autoridad. El tenant procede de la sesión y se refuerza con PostgreSQL RLS.
7. **No mocks de producto en la entrega.** Un adaptador externo puede quedar “no configurado”, pero las capacidades mostradas como disponibles deben persistir y actuar realmente.
8. **Motion con intención.** Las animaciones expresan continuidad y estado; nunca distraen durante lectura clínica ni rompen reduced motion.
9. **Accesibilidad desde el componente inicial.** Objetivo WCAG 2.2 AA, no una auditoría cosmética al final.
10. **Dependencias mínimas y examinadas.** No añadas una biblioteca si CSS, Web Animations, Angular CDK o una API del navegador estable resuelven el problema con claridad.
11. **Errores visibles, datos invisibles.** Mensajes accionables para el usuario; detalles técnicos y datos sensibles nunca en la respuesta pública.
12. **No afirmar “sin errores” sin evidencia.** Solo finaliza cuando los gates definidos estén verdes y documenta cualquier riesgo o exclusión real.

## Protocolo de trabajo obligatorio

### Paso A — Preparación y lectura

1. Lee `PLAN_MAESTRO.md` y este prompt completos.
2. Comprueba `git status`, herramientas disponibles y si Docker responde.
3. Revisa las nuevas skills ya instaladas antes de aplicarlas.
4. No modifiques archivos ajenos ni borres cambios existentes.
5. Crea un plan operativo con pasos, como máximo uno en progreso.

### Paso B — Dirección visual antes del producto

Aplica el flujo de diseño de Sites y las reglas de `frontend-design`/`visualize` compatibles con Angular:

1. Define en una frase la tesis visual, en una frase la tesis de contenido y en 2–3 puntos la tesis de interacción.
2. Genera exactamente tres direcciones visuales comparables para la vista principal de agenda, manteniendo el mismo contenido y viewport. Deben variar de forma real en composición/densidad/tipografía/paleta, no ser tres recolores.
3. Cada dirección debe incluir:
   - paleta de 4–6 colores con valores exactos;
   - tipografías y fallbacks, con autoalojamiento y licencias;
   - layout desktop/tablet/mobile;
   - densidad de la agenda;
   - un elemento distintivo apropiado para salud;
   - 2–3 ideas de motion/ScrollFX;
   - contraste, foco y reduced motion;
   - riesgos de implementación.
4. Muestra las tres opciones al usuario mediante el picker permitido por el entorno si está disponible. No modifiques archivos del producto antes de la selección visual, salvo el scaffold obligatorio del entorno si la skill lo exige.
5. Tras la selección, congela design tokens y evita rediseños arbitrarios durante la implementación.

No conviertas una app clínica en un póster artístico. El gesto distintivo debe ser funcional: por ejemplo, una “cinta de jornada” temporal continua, una visualización serena de carga asistencial o una inspector column muy bien resuelta. La agenda necesita densidad legible, no una hero image.

### Paso C — Threat model antes de datos sensibles

Una vez exista scaffold/arquitectura suficiente para citar evidencia:

1. Usa `security-threat-model` o la skill de threat model aplicable.
2. Modela activos, actores, componentes, límites de confianza, protocolos, entry points y datos.
3. Prioriza:
   - phishing/robo de cuenta;
   - sesión/token theft;
   - IDOR/BOLA;
   - acceso cross-tenant;
   - abuso interno;
   - exportación/exfiltración;
   - XSS, CSRF, SQLi, SSRF;
   - corrupción por concurrencia;
   - secretos y supply chain;
   - logs/backups/telemetría;
   - disponibilidad.
4. Escribe `docs/threat-model.md` con controles existentes vs recomendados, riesgo residual y casos de prueba.
5. No presentes controles planificados como si ya existieran.

### Paso D — Construcción por cortes verticales

Construye en este orden, manteniendo build y tests verdes en cada corte:

1. Scaffold y diseño base.
2. Infraestructura local y configuración segura.
3. Identidad, sesión, CSRF y autorización.
4. Tenant/sedes/equipo/pacientes mínimos.
5. Agenda y ciclo completo de cita.
6. Lista de espera y disponibilidad.
7. Notificaciones y dashboard operativo.
8. Configuración/personalización.
9. Informes, auditoría y seguridad de cuenta.
10. Endurecimiento, QA total y documentación.

Evita crear veinte rutas vacías. Una ruta solo entra al producto cuando su comportamiento, permisos, persistencia, loading/error/empty states y pruebas mínimas están resueltos.

## Stack objetivo

### Workspace

- `pnpm` workspace para Angular, Composer para Laravel y versiones bloqueadas en ambos ecosistemas.
- TypeScript strict en Angular; PHP con `declare(strict_types=1)`, Larastan al máximo nivel viable y DTOs tipados.
- Estructura:

```text
apps/
  web/
  api/                 # Laravel 13 / PHP 8.4
packages/
  design-tokens/
openapi/
  nexasalud.yaml
  generated/
infra/
  docker/
  keycloak/
  migrations/
docs/
```

- Scripts raíz claros: `dev`, `build`, `typecheck`, `lint`, `test`, `test:integration`, `test:e2e`, `test:a11y`, `security:check`, `db:migrate`, `db:seed`, `infra:up`, `infra:down`.
- No uses Nx salvo que una necesidad demostrable supere el coste de complejidad. Un workspace pnpm es suficiente.

### Frontend

- Angular 21 LTS, standalone components, Angular Material/CDK 21 LTS.
- SCSS y Material 3 theming con tokens propios.
- Signals para estado local derivado; RxJS para eventos async, cancelación y composición.
- Lazy routes por dominio.
- Formularios reactivos tipados.
- Angular Material component harnesses.
- Calendario: evalúa una dependencia mantenida y de licencia compatible como FullCalendar core/timeGrid/dayGrid/list/interaction. No uses módulos premium sin licencia. Si la dependencia introduce problemas de accesibilidad o CSP, construye la capa necesaria con CDK y una vista de lista accesible.
- No agregues NgRx por reflejo; solo si el estado global real lo exige. Prefiere servicios de dominio y signals.
- No cargues fuentes, iconos, scripts o analytics desde CDN. Autoaloja recursos licenciados.

### BFF/API

- Laravel 13 sobre PHP 8.4, con controllers finos, servicios de dominio y separación explícita entre HTTP, autorización, aplicación y persistencia.
- Laravel Sanctum en modo SPA/BFF, sesión server-side Redis y OIDC con Keycloak mediante una integración mantenida.
- Form Requests allowlist para toda entrada, DTOs inmutables y API Resources que expongan solo campos autorizados.
- Policies/Gates y scopes por tenant; la autorización también vive en las consultas y PostgreSQL RLS actúa como segunda barrera.
- OpenAPI generado y revisado; genera desde él los tipos Angular para evitar contratos paralelos divergentes.
- Logs estructurados con Monolog, contexto allowlist y redacción centralizada.
- OpenTelemetry preparado de forma que no capture bodies ni atributos sensibles.
- `bootstrap/app.php::withExceptions` convierte validación, autenticación, autorización, concurrencia, rate limit y dependencias caídas a RFC 9457, con `code`, mensaje seguro, `correlationId` y field errors no sensibles.

### Infraestructura local

- PostgreSQL en contenedor.
- Redis en contenedor.
- Keycloak en contenedor, realm importable y usuarios sintéticos.
- Mailpit en contenedor para correo local.
- Healthchecks y versiones de imagen fijadas por major/minor/digest razonable.
- Red privada para servicios; solo publicar puertos necesarios en localhost.
- Volúmenes nombrados explícitos; no comandos destructivos amplios.
- `.env.example` sin secretos. Genera secretos locales fuertes en `.env` ignorado por Git.

### Persistencia y migraciones

Usa Eloquent y Query Builder para el dominio habitual, pero conserva SQL explícito y revisable en migraciones para RLS, exclusion constraints y contexto de tenant. Requisitos:

- Queries parametrizadas.
- Migraciones SQL legibles y versionadas.
- Transacciones explícitas.
- Capacidad de `SET LOCAL` para contexto de tenant/actor.
- Tests sobre PostgreSQL real, no una base distinta que cambie semántica.

## Arquitectura de identidad extremadamente reforzada

### Modelo

No implementes formularios propios que reciban contraseñas en Angular. La app muestra una pantalla de acceso de marca con explicación mínima y botón “Acceder de forma segura”; el proveedor OIDC realiza la autenticación. Personaliza el tema local de Keycloak para que la experiencia sea coherente.

Flujo:

1. Angular solicita `/auth/login?returnTo=/ruta-segura` con ruta relativa validada.
2. Laravel genera state, nonce y PKCE, los vincula a una transacción de login de corta vida en Redis y redirige al issuer esperado.
3. Keycloak autentica con passkey/MFA según política.
4. Laravel valida callback, issuer, audience, state, nonce, code verifier, timestamps y firma.
5. Laravel regenera la sesión server-side en Redis, elimina la transacción OIDC y rota el identificador.
6. El navegador recibe únicamente cookie opaca `__Host-nexasalud_session` HttpOnly, Secure, Path `/`, sin Domain y SameSite adecuado.
7. Angular obtiene un perfil mínimo desde `/api/session` y un token CSRF separado no reutilizable como sesión.

### Passkeys/MFA

- Passkey/WebAuthn como opción preferente y resistente a phishing.
- MFA obligatorio para roles de personal.
- Recovery codes de un solo uso, cifrados/hasheados según naturaleza y nunca registrados.
- OTP solo como fallback documentado de menor resistencia.
- No SMS como factor principal.
- Step-up con `acr`/`amr` o transacción equivalente para exportaciones, cambios de roles, break-glass, gestión de MFA y datos críticos.

### Sesiones

- ID aleatorio CSPRNG con entropía suficiente; nunca JWT legible como cookie de sesión principal.
- Idle timeout y absolute timeout configurables por rol.
- Rotación al login, elevación, cambio de tenant y eventos sensibles.
- Revocación inmediata al cerrar sesión, cambiar roles, deshabilitar cuenta o detectar compromiso.
- Límite de sesiones concurrentes configurable.
- Página “Seguridad de la cuenta” con sesiones/dispositivos, fecha, actividad aproximada y revocación.
- No fingerprinting invasivo; usa señales proporcionadas y minimizadas.
- No caches compartidas de respuestas autenticadas.

### CSRF y navegador

- Token sincronizador de sesión para mutaciones.
- Header personalizado y comprobación server-side.
- Validar `Origin`; usar `Referer` como defensa complementaria cuando proceda.
- Same-origin en producción y CORS cerrado.
- Rechazar content types inesperados.
- No mutaciones por GET.
- CSP estricta, HSTS, nosniff, frame-ancestors, referrer policy y permissions policy mínimas.
- Evalúa Trusted Types; no la fuerces si rompe una dependencia sin plan, pero no permitas `unsafe-eval` por comodidad.

## Autorización y multi-tenancy

Define permisos atómicos y compón roles. Ejemplos:

- `appointments.read.own`
- `appointments.read.clinic`
- `appointments.create`
- `appointments.reschedule`
- `appointments.cancel`
- `patients.read.basic`
- `patients.read.sensitive`
- `patients.update`
- `waitlist.manage`
- `settings.manage.clinic`
- `roles.manage`
- `audit.read`
- `exports.create`
- `break_glass.use`

Reglas:

- Administrador de plataforma no lee contenido de pacientes por defecto.
- Recepción accede a identidad/contacto y operación de cita, no a notas clínicas privadas.
- Profesional accede según relación asistencial, equipo y sede.
- Paciente solo a sus datos permitidos, vinculados por identidad verificada.
- Responsable de privacidad consulta auditoría; revelar contenido exige permiso/elevación justificada.
- Cada request obtiene actor, tenant activo, roles, permisos, nivel de autenticación y correlation ID desde la sesión, no del body.
- Cada transacción PostgreSQL configura tenant/actor y RLS.
- Toda consulta y mutación importante tiene tests de otro usuario, otro rol y otro tenant.

Implementa break-glass únicamente si puedes hacerlo completo:

- motivo obligatorio;
- permiso específico;
- step-up;
- duración corta;
- banner persistente mientras esté activo;
- auditoría y alerta;
- revisión posterior;
- nunca acceso silencioso ilimitado.

## Cifrado y datos sensibles

Clasifica como mínimo:

- Público: contenido de ayuda genérico.
- Interno: configuración operativa no identificativa.
- Personal: identidad/contacto.
- Sanitario sensible: motivo, notas privadas, alertas autorizadas.
- Secreto: claves, tokens, recovery material.

Controles:

- TLS en tránsito y cifrado de volumen/base/backups en infraestructura.
- AES-256-GCM para campos sensibles en aplicación.
- Nonce único por cifrado.
- AAD con tenant, tabla, record ID, campo y versión.
- Envoltorio de clave versionado: clave de datos + KEK/KMS en producción.
- Proveedor local seguro para desarrollo, clave generada fuera de Git y aviso de no producción.
- Rotación con versión de clave y job reanudable.
- No cifres campos necesarios para restricciones/búsquedas sin diseñar índices ciegos y evaluar fuga; minimiza esas búsquedas.
- Nunca escribas plaintext sensible en logs, errores o audit diff.

## Modelo de datos mínimo

Usa UUIDs no enumerables. Incluye `created_at`, `updated_at`, `version`, actor cuando sea apropiado y `tenant_id` en toda entidad de negocio.

### Organización

- tenants
- clinics
- rooms
- resources
- specialties

### Identidad y acceso

- users (referencia local mínima al `sub` OIDC, no contraseña)
- memberships
- roles
- permissions
- role_permissions
- role_assignments

### Profesionales y horarios

- provider_profiles
- availability_templates
- availability_rules
- availability_exceptions
- holidays

### Pacientes

- patients
- patient_contacts
- patient_preferences
- consents
- authorized_contacts

### Agenda

- appointment_types
- appointments
- appointment_participants
- appointment_status_history
- appointment_change_reasons
- idempotency_keys

Appointment debe incluir:

- tenant, clinic, patient, provider, room/resource, type;
- mode: in_person, phone, video;
- start/end UTC y zona horaria de creación;
- buffers;
- status;
- operational label no sensible;
- encrypted reason y encrypted private note;
- source, version, creators/modifiers y cancel metadata.

Impide doble reserva con dos capas:

1. consulta de disponibilidad para UX;
2. constraint/exclusion transaction-safe para profesional y sala en estados ocupantes.

La API devuelve conflicto estable con sugerencias, nunca un stack trace.

### Lista de espera y comunicaciones

- waitlist_entries
- slot_offers
- notification_templates
- notifications
- delivery_attempts

### Configuración/auditoría

- organization_settings
- clinic_settings
- role_settings
- user_preferences
- audit_events
- security_events
- break_glass_events
- export_jobs
- retention_jobs

## Producto y navegación

### Shell autenticado

- Navegación lateral: Inicio, Agenda, Citas, Lista de espera, Pacientes, Equipo, Sedes y recursos, Comunicaciones, Informes, Auditoría, Configuración.
- Barra superior: tenant/sede, búsqueda global, crear cita, modo privacidad, notificaciones, cuenta.
- Command palette `Ctrl/Cmd+K` con acciones autorizadas.
- Skip link, landmarks semánticos y foco restaurado al navegar.

### Inicio

- Jornada actual como protagonista.
- Próximas citas y estados operativos.
- Llegadas/en espera/retrasos.
- Huecos y cancelaciones recientes.
- Confirmaciones pendientes.
- Lista de espera compatible.
- Acciones rápidas.
- Widgets configurables con orden persistente.
- No más de 3–5 indicadores secundarios en el primer viewport.

### Agenda

- Vistas día, semana, mes, lista y “mi jornada”.
- Filtros combinables por sede, profesional, especialidad, sala, modalidad, tipo y estado.
- URL sin PII; serializa solo filtros inocuos cuando sea útil.
- Crear desde hueco, editar en inspector y duplicar según permiso.
- Drag/resize con confirmación y alternativa de teclado.
- Línea “ahora”, working hours, no laboral, buffers, bloqueos y festivos.
- Densidad cómoda/compacta.
- Leyenda accesible; color nunca es el único indicador.
- Vista lista equivalente para lector de pantalla y móvil.

### Flujo de cita

1. Buscar/crear paciente mínimo con detección de duplicados.
2. Elegir tipo/motivo/modalidad.
3. Elegir sede/profesional/recurso.
4. Elegir hueco disponible.
5. Configurar recordatorios.
6. Revisar, consentimiento/instrucciones y confirmar.

Usa stepper o páginas según viewport. Conserva borrador solo en memoria o servidor temporal; nunca PHI durable en browser storage. Al confirmar, usa idempotency key. Devuelve resumen y acciones “ver en agenda”, “crear otra”, “enviar confirmación”.

### Pacientes

- Lista paginada server-side.
- Búsqueda con mínimo de caracteres, límites y auditoría cuando sea masiva.
- Ficha con cabecera mínima, próximas citas, historial, contacto, preferencias y consentimientos.
- Privacidad por defecto: campos sensibles enmascarados para roles limitados.
- Unificación de duplicados con aprobación y preview; si no puede implementarse de forma segura en v1, no muestres el control.
- No construyas una historia clínica completa; la finalidad es la agenda.

### Lista de espera

- Prioridad, rango, días/horas, sedes/profesionales y tipo.
- Coincidencia al cancelarse/liberarse hueco.
- Oferta con expiración y transacción que evita doble aceptación.
- Timeline de ofertas y outcomes.

### Equipo, sedes y disponibilidad

- CRUD autorizado de sede, sala, recurso, profesional, especialidad y tipo de cita.
- Plantillas semanales y excepciones.
- Festivos, vacaciones, bloqueos y vigencia.
- Validación de solapamientos.
- Preview de calendario antes de aplicar cambios masivos.

### Comunicaciones

- Centro de notificaciones internas real.
- Email de desarrollo real hacia Mailpit.
- Plantillas con variables allowlisted.
- Nunca motivo/diagnóstico en asunto o preview del dispositivo.
- Recordatorios programados e idempotentes.
- Estado queued/sent/failed/cancelled y reintentos acotados.
- SMS aparece como “No configurado” hasta disponer de proveedor; no finjas entrega.

### Informes

- Ocupación, cancelación, no-show, espera, tiempo de reserva y actividad.
- Agregados por defecto; identidad solo con necesidad y permiso.
- Filtros por periodo/sede/profesional/tipo.
- Exportación con reautenticación, límite, watermark/metadatos y audit event.
- Job asíncrono para exportaciones grandes.

### Auditoría y seguridad

- Eventos de autenticación, sesión, permiso, lectura sensible, escritura, exportación, configuración y break-glass.
- Filtros por fecha, actor, acción, resultado y entidad seudonimizada.
- Nunca mostrar payload completo ni secretos.
- Seguridad de cuenta: métodos MFA, sesiones, alertas y revocación.

## Personalización extensa y funcional

Cada control visible debe persistir y aplicar. Usa resolución jerárquica:

`default del sistema < organización < sede < rol < usuario`

Muestra el origen del valor y opción “restablecer heredado”.

Categorías:

### Apariencia

- claro/oscuro/sistema;
- contraste reforzado;
- densidad cómoda/compacta;
- escala tipográfica dentro de límites seguros;
- navegación expandida/compacta;
- esquinas/elevación solo mediante presets verificados;
- reducción de movimiento automática/manual;
- colores semánticos por tipo/estado con verificación de contraste.

### Agenda

- vista inicial;
- intervalo 5/10/15/30 minutos;
- rango horario;
- primer día de semana;
- mostrar fines de semana;
- buffers y duración por defecto;
- agrupación por profesional/sala;
- mostrar/ocultar canceladas;
- confirmación antes de drag/drop;
- zonas horarias secundarias si el negocio lo necesita.

### Regional

- español inicial;
- arquitectura i18n;
- 12/24 horas;
- formatos regionales;
- zona horaria;
- semana ISO o regional.

### Notificaciones

- canales permitidos;
- antelación;
- quiet hours;
- recordatorios internos;
- plantillas por tipo;
- política ante fallo.

### Organización

- sedes y recursos;
- tipos de cita;
- estados permitidos;
- políticas de cancelación/reprogramación;
- lista de espera;
- horarios/festivos;
- retención y auditoría según autorización;
- sesión/MFA según rol.

### Inicio

- widgets visibles;
- orden;
- sede por defecto;
- rango temporal;
- privacidad al arrancar.

## Sistema visual

La selección final del usuario manda. Si aún no ha elegido, no asumas la propuesta siguiente como definitiva; úsala para crear una de las tres opciones.

Dirección base sugerida:

- Sensación: precisión serena, confianza clínica y calidez humana.
- Evitar: azul SaaS genérico, gradiente morado, glassmorphism indiscriminado, cards por todas partes, iconos ornamentales, sombras pesadas.
- Paleta tentativa:
  - `ink-950`: azul tinta profundo para navegación/texto fuerte;
  - `teal-600`: acción primaria y foco de marca;
  - `aqua-100`: estados informativos suaves;
  - `ivory-50`: superficie cálida limpia;
  - `slate-500`: información secundaria;
  - `coral-600`: error/urgencia con uso escaso.
- Tipografía: combinación autoalojada con un sans muy legible para UI y un acento de marca reservado; para datos densos usa cifras tabulares. Comprueba licencias y bundle.
- Forma: radio 8–12 px, no cápsulas indiscriminadas.
- Elevación: bordes/tintas antes que sombras; shadow solo donde expresa capa.
- Espacio: escala de 4 px con ritmo generoso en formularios y compacto en agenda.
- Datos: todos los estados tienen icono/forma/texto además de color.

Design tokens mínimos:

- color roles light/dark/high contrast;
- typography roles;
- spacing;
- radius;
- elevation;
- density;
- focus ring;
- motion duration/easing/distance;
- z-index layers;
- breakpoints;
- calendar sizes.

## Animaciones, efectos y ScrollFX

La animación debe implementarse como sistema, no como CSS disperso.

### Motion tokens

- `instant`: 0–80 ms para cambios de estado muy pequeños.
- `fast`: 120–160 ms para hover, focus, iconos y chips.
- `standard`: 180–220 ms para paneles, dialogs y route content.
- `slow`: 280–360 ms solo para composiciones de entrada importantes.
- Easing de entrada, salida y estándar definidos como tokens.
- Distancias máximas de 4/8/12 px en UI operativa.

### Efectos permitidos

- Route transition suave: opacity + translateY 6–8 px, sin animar el shell.
- Stagger inicial de 3–5 elementos relevantes, una sola vez.
- Toolbar que reduce altura o añade borde al cruzar el sentinel de scroll.
- Filtros sticky con sombra/borde al separarse de su origen.
- Section reveal en páginas largas de configuración con IntersectionObserver, una vez.
- Inspector lateral con slide/fade breve y foco gestionado.
- FLIP al reordenar widgets.
- Cita drag ghost y snap claro.
- Skeletons con shimmer muy sutil o pulso, desactivado con reduced motion.
- Save confirmation de check/fade y live region.
- Toast/snackbar con entrada breve y timeout controlable/pausable.
- Calendar “now line” sin animación continua costosa; actualización por timer razonable.
- Scroll-to-error o scroll-to-selected con foco y opción instantánea en reduced motion.

### Efectos prohibidos o restringidos

- Parallax sobre agenda, pacientes, tablas o formularios.
- Smooth scrolling global que interfiera con foco o lectores de pantalla.
- Animaciones infinitas decorativas.
- Counters de casino/slot machine.
- Tilt 3D, custom cursors o partículas en superficies clínicas.
- Blur/transparency que reduzca legibilidad o revele datos detrás de modal.
- Layout animations que provoquen CLS.
- Scroll listeners sin throttle/observer y sin cleanup.
- Animar height/width/top/left en listas densas cuando transform/opacity resuelve.

### Reduced motion

Con `prefers-reduced-motion: reduce` o preferencia manual:

- duración casi instantánea;
- sin desplazamiento, parallax, stagger o smooth scroll;
- skeleton estático;
- drag/drop conserva feedback no animado;
- estado final y focus siguen siendo evidentes.

### QA de motion

- Captura al menos un estado intermedio y el estado final de interacciones centrales.
- Revisa 60 fps de forma razonable y ausencia de layout thrashing.
- Comprueba observers/listeners destruidos al cambiar ruta.
- Prueba reduced motion automatizada y visual.
- Prueba teclado durante/tras transición; el foco no se pierde ni queda detrás de overlays.

No instales GSAP por defecto. CSS, Web Animations API, Angular animations disponibles y IntersectionObserver cubren este alcance. Añade una librería solo si una secuencia compleja aporta valor clínico/operativo y no puede resolverse de forma mantenible con lo anterior.

## Copy y estados de interfaz

- Español claro, sentence case y verbos activos.
- Botones: “Guardar cambios”, “Reprogramar cita”, “Cancelar cita”, no “Enviar”.
- Resultado coherente: acción “Reprogramar” → confirmación “Cita reprogramada”.
- Error explica qué pasó y cómo resolverlo sin detalles técnicos.
- Empty state ofrece el siguiente paso real.
- No uses marketing dentro del workspace operativo.
- No uses datos clínicos en snackbars visibles desde lejos; escribe “Cita actualizada” en vez de nombre + motivo.
- Confirmaciones destructivas nombran el objeto y la consecuencia; para datos sensibles requieren permisos/step-up cuando corresponda.

Estados obligatorios por pantalla:

- loading;
- empty;
- populated;
- filtered-no-results;
- validation-error;
- permission-denied;
- conflict/concurrency;
- offline/dependency unavailable;
- success;
- reduced-motion;
- responsive narrow;
- dark/high-contrast si aplica.

## Accesibilidad WCAG 2.2 AA

- HTML semántico y landmarks.
- Skip link.
- Foco visible y nunca oculto por sticky headers.
- Orden de foco equivalente al visual.
- Operación completa por teclado.
- Alternativa al drag.
- Target size y separación.
- Contraste de texto/no texto/foco.
- Reflow a 320 CSS px cuando sea aplicable.
- Zoom 200% sin pérdida de funcionalidad.
- Labels persistentes; placeholder no sustituye label.
- Errors enlazados con `aria-describedby`, resumen de errores y foco inteligente.
- Live regions moderadas para guardado, conflicto y cambios de estado.
- Dialog focus trap, Escape seguro, retorno de foco.
- Menús y comboboxes Material/CDK con comportamiento estándar.
- Agenda con nombres accesibles y vista lista.
- Autenticación accesible a gestores/passkeys; no bloquees paste en password/OTP si el IdP lo presenta.
- Timeouts advertidos y extensibles cuando política lo permita.
- axe automatizado y QA manual.

## Seguridad de aplicación detallada

### Validación y encoding

- Form Requests Laravel en cada input externo, con reglas allowlist y DTOs tipados después de validar.
- Rechaza campos desconocidos en mutaciones sensibles.
- Límites de longitud, arrays, fechas, rangos y números.
- Canonicaliza solo cuando la semántica lo permite.
- Output encoding contextual y sanitización Angular.
- Prohibido `bypassSecurityTrust*` salvo caso excepcional, revisión y test de seguridad.
- No renderizar templates de mensajes como HTML arbitrario; usa motor con variables allowlisted y salida segura.

### API

- Queries parametrizadas.
- Paginar y limitar búsquedas/exportaciones.
- Idempotency keys para create appointment, cancel/reschedule sensible y notificaciones.
- Optimistic concurrency con ETag/version.
- Rate limits diferenciados.
- Timeouts y circuit breakers en integraciones.
- SSRF: no aceptar URLs arbitrarias; allowlist de hosts y DNS/IP checks si aparece alguna integración.
- Uploads fuera de v1. Si se añaden, threat model específico.

### Logs/auditoría

- Allowlist de campos loggeables.
- Redactar authorization, cookie, tokens, email/teléfono, nombres, reason, note y request bodies.
- Correlation ID aleatorio no derivado de PII.
- Audit event append-only lógico: actor, tenant, action, target pseudonymous ID, outcome, reason, time, source metadata minimizada.
- Nunca almacenar diff clínico plaintext.
- Separar audit/security logs de observability general en producción.

### Supply chain

- Lockfile.
- Revisar scripts postinstall y licencias.
- Secret scan.
- SCA con severidad y reachability cuando sea posible.
- SBOM CycloneDX/SPDX.
- No ejecutar paquetes por typos o comandos copiados sin verificar.
- No añadir dependencias abandonadas para efectos visuales.

## Pruebas y gates

### Unitarias

Prueba al menos:

- reglas de permisos;
- resolución de setting inheritance;
- disponibilidad, buffers y solapamientos;
- transición de estados de cita;
- idempotencia;
- recurrencia y DST/zonas horarias;
- cifrado round-trip, AAD incorrecto y rotación;
- redacción de logs;
- validación;
- plantillas de notificación sin variables prohibidas.

### Integración

- API con PostgreSQL y Redis reales en contenedores.
- Migración desde cero y upgrade.
- RLS cross-tenant.
- constraint de doble reserva bajo concurrencia.
- sesión/CSRF/revocación.
- email a Mailpit.
- job de recordatorio idempotente.
- export permission/step-up.

### E2E Playwright

Perfiles sintéticos:

- admin clínica;
- profesional;
- recepción;
- privacidad/auditor;
- paciente.

Flujos:

1. Login y selección de clínica.
2. Crear cita completa.
3. Detectar conflicto simultáneo.
4. Reprogramar por formulario y drag.
5. Alternativa teclado a drag.
6. Llegada → espera → consulta → completada.
7. Cancelación y matching de waitlist.
8. Notificación y correo Mailpit.
9. Preferencias de tema/densidad/motion persistentes.
10. Intento de acceso prohibido por rol.
11. Intento cross-tenant.
12. Step-up/export.
13. Revocar sesión y confirmar invalidez.
14. Logout y back-button sin datos cacheados.

### Visual y responsive

- 1440×900 desktop.
- 1280×800 laptop.
- 1024×768 tablet landscape.
- 768×1024 tablet portrait.
- 390×844 móvil.
- Tema claro/oscuro.
- Densidad cómoda/compacta.
- Default/reduced motion.
- Estado vacío, cargado, denso, error, dialog, inspector y menú.
- Revisa clipping, overflow, jerarquía, alineación, contraste, z-index, calendario y overlays.
- Inspecciona el primer viewport antes de scroll y una página larga con ScrollFX.
- Separa QA funcional de QA visual.

### Seguridad

- Threat model actualizado con evidencia.
- Matriz OWASP ASVS 5.0 aplicable.
- SAST/SCA/secret scan/SBOM.
- Cabeceras.
- ZAP baseline local autenticado/no autenticado cuando sea viable.
- Tests manuales y automáticos de IDOR/BOLA/cross-tenant.
- Verificación de almacenamiento web/cookies/cache.
- Logs observados durante flujos para confirmar ausencia de PII/PHI.

### Rendimiento

- Budgets de bundle por ruta.
- Lazy routes.
- Virtual scroll/paginación.
- No cargar meses/años completos sin ventana.
- Medir LCP/INP/CLS en rutas principales.
- Motion sin long tasks/layout thrashing.
- Endpoint de agenda y búsqueda con datos sintéticos de volumen realista.

### Gate final

No termines hasta que sean verdes:

- instalación limpia;
- build producción web/API;
- typecheck;
- lint;
- unit tests;
- integration tests;
- E2E críticos;
- axe sin violaciones serias/críticas;
- migraciones desde cero;
- security checks acordados;
- capturas visuales revisadas;
- consola del navegador sin errores inesperados;
- logs del servidor sin errores inesperados ni datos sensibles;
- documentación de run/start/stop/reset seguro.

Si algo no puede quedar verde por una dependencia externa o credencial ausente, la función debe quedar honestamente marcada como no configurada, con adaptador y documentación; no simules éxito ni ocultes el bloqueo.

## Instalación de herramientas

Instala solo dentro del proyecto salvo herramientas de sistema imprescindibles. Antes de añadir una dependencia:

1. confirma que no está ya disponible;
2. comprueba compatibilidad con Node/Angular o PHP/Laravel según corresponda;
3. revisa mantenimiento, licencia y vulnerabilidades;
4. justifica su necesidad;
5. fija versión y lockfile.

Lista esperada, sujeta a verificación:

- Angular CLI/core/router/forms/animations 21 LTS.
- Angular Material/CDK 21 LTS.
- Laravel 13 / PHP 8.4 / Composer 2.
- Sanctum, Socialite/proveedor OIDC mantenido, Policies, Form Requests y API Resources.
- Pest/PHPUnit, Larastan, Pint y Laravel Boost solo en desarrollo.
- Cliente PostgreSQL y migraciones elegidas.
- Redis mediante el driver oficial de Laravel/PhpRedis.
- OIDC/JOSE mantenidos y compatibles con Keycloak.
- Monolog con procesador de redacción.
- Playwright + axe.
- Utilidades de tests e integración.
- FullCalendar MIT si supera evaluación.
- OpenTelemetry solo con configuración segura.

No instales por defecto:

- GSAP;
- librerías de parallax;
- Tailwind, Bootstrap u otro sistema visual paralelo a Angular Material;
- un segundo state manager sin necesidad;
- servicios analytics/monitoring externos;
- SDKs de SMS/correo antes de elegir proveedor;
- Firebase/Supabase como atajo si rompe el modelo BFF/RLS decidido;
- paquetes de login propios que almacenen tokens en SPA.

## Datos sintéticos y demo

Crea seed español totalmente ficticio:

- 2 clínicas/tenants para probar aislamiento;
- 2 sedes en tenant principal;
- 8–12 profesionales;
- salas/recursos;
- tipos de cita con distintas duraciones/buffers;
- 80–150 pacientes sintéticos;
- citas distribuidas en pasado/hoy/futuro y todos los estados;
- lista de espera;
- notificaciones y auditoría.

Incluye una marca visible “Datos de demostración” en entorno dev. No uses DNIs, teléfonos o emails que puedan corresponder a personas reales; usa dominios reservados y numeración de ejemplo segura.

## Documentación obligatoria

- `README.md`: propósito, arquitectura breve, requisitos, start/stop, usuarios demo y comandos.
- `docs/architecture.md`.
- `docs/threat-model.md`.
- `docs/security-controls.md` con evidencia y pendientes.
- `docs/data-classification.md`.
- `docs/privacy.md`.
- `docs/runbook.md`.
- `docs/testing.md`.
- `docs/deployment-readiness.md` con decisiones externas.
- `.env.example` seguro.
- Diagrama de arquitectura y flujo auth.

No documentes secretos reales. Señala claramente qué configuración local no es válida para producción.

## Criterios de aceptación por experiencia

Un recepcionista puede:

- entrar con MFA;
- ver su jornada sin datos clínicos innecesarios;
- buscar/crear paciente mínimo;
- reservar, reprogramar y cancelar según política;
- ofrecer un hueco de lista de espera;
- enviar confirmación;
- trabajar con teclado y móvil/tablet;
- personalizar densidad/agenda permitida.

Un profesional puede:

- ver agenda propia y pacientes relacionados;
- cambiar estados operativos;
- ver/editar campos sensibles autorizados;
- bloquear disponibilidad;
- revisar historial de una cita.

Un admin clínica puede:

- configurar sedes, salas, tipos, horarios, políticas y roles dentro de límites;
- revisar informes agregados;
- gestionar configuración heredada;
- no acceder automáticamente a contenido clínico por ser admin.

Privacidad/seguridad puede:

- revisar eventos;
- detectar y filtrar accesos;
- gestionar revocación/políticas;
- auditar exportaciones y break-glass;
- no ver contenido sensible sin elevación explícita.

Paciente puede:

- ver solo sus citas;
- confirmar, reprogramar/cancelar dentro de política;
- ajustar comunicaciones y consentimientos permitidos;
- revisar sesiones/seguridad propia.

## Antipatrones que invalidan la entrega

- Login decorativo con credenciales hardcoded.
- JWT en localStorage.
- API que confía en role/tenant del cliente.
- Datos solo en arrays/localStorage.
- Botones sin endpoint real.
- Rutas de configuración que no persisten.
- Datos reales o plausiblemente identificables.
- `innerHTML`/sanitization bypass.
- CORS `*` con credenciales.
- Secrets en Git.
- Log de request bodies.
- Solo happy-path tests.
- Agenda no operable por teclado.
- Motion ignorando reduced motion.
- Efectos scroll pesados o parallax clínico.
- Dashboard genérico de tarjetas y gradientes.
- Instalar herramientas globales sin necesidad.
- Declarar “cumple RGPD/HIPAA” sin evaluación.
- Terminar con build roto, console errors o flujos simulados.

## Informe de progreso

Mantén al usuario informado con actualizaciones breves por fase, sin inundarlo de comandos internos. Comunica:

- dirección visual en preparación/selección;
- arquitectura y seguridad base terminadas;
- agenda funcional;
- módulos avanzados;
- fase de QA/endurecimiento;
- entrega.

Si una decisión material necesita al usuario —identidad visual, proveedor real, hosting o política legal— presenta el impacto y sigue con un default local seguro cuando sea posible. No bloquees trabajo por preguntas que no cambian la implementación local.

## Protocolo de finalización

Antes de afirmar que la aplicación está lista:

1. Relee la petición y construye una matriz requisito → evidencia.
2. Ejecuta todos los gates.
3. Inspecciona visualmente cada ruta principal y el estado más denso.
4. Prueba motion normal y reducido.
5. Revisa console/network/logs.
6. Ejecuta security scan; valida hallazgos antes de reportarlos.
7. Corrige críticos/altos y regresiones.
8. Prueba instalación/migración/seed desde cero de forma segura.
9. Verifica que no hay secretos ni PHI/PII.
10. Entrega URL local o publicada según alcance, usuarios demo sintéticos, documentación y límites.

El informe final debe separar:

- qué está completamente funcional;
- qué pruebas pasaron;
- qué medidas de seguridad están implementadas;
- qué integraciones están no configuradas por falta de credenciales/decisión;
- qué decisiones de producción siguen pendientes;
- cómo iniciar la aplicación.

No conviertas la respuesta final en una lista interminable de archivos. Lidera con el resultado, enlaza la documentación central y menciona riesgos reales. Si se ha publicado, indica el destino y aclara si es demo con datos sintéticos o entorno apto para datos reales. Nunca presentes un despliegue de demo como autorizado para información sanitaria real.

## Resultado esperado

El resultado debe sentirse como una herramienta clínica que un equipo usaría durante toda la jornada: rápida, calmada, legible, coherente, agradable y precisa. Debe ser visualmente memorable por una decisión auténtica vinculada a la agenda, no por decoración. La seguridad debe estar presente en arquitectura, base de datos, sesiones, permisos, logs, pruebas y operación; no solo en el formulario de acceso. Las opciones de personalización deben ser amplias pero gobernadas, persistentes y accesibles. Las animaciones y ScrollFX deben aportar continuidad sin apropiarse de la atención. Y la palabra “funcional” debe corresponder a comportamientos reales verificados de extremo a extremo.
