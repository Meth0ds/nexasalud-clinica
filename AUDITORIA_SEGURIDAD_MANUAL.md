# Auditoría manual de seguridad — NexaSalud Clínica

**Fecha de corte:** 3 de agosto de 2026  
**Modalidad:** revisión manual, repository-grounded; no se utilizó Codex Security.  
**Resultado ejecutivo:** **no apto todavía para exponer a Internet con datos sanitarios reales**.

Este documento es el informe independiente solicitado. No es una certificación, un pentest ni una declaración de conformidad legal. Describe lo que se puede demostrar leyendo el código, las migraciones, los tests y la infraestructura disponible en esta carpeta.

## 1. Alcance y método

Se revisaron manualmente:

- `apps/api`: rutas, middleware, autenticación OIDC, políticas, controladores, requests, modelos, eventos, listeners, colas, generación/descarga de PDF, migraciones y tests.
- `apps/web`: sesión BFF, interceptores, XSRF, guards, manejo de errores/eventos, descargas y sinks de DOM.
- `infra`: Compose local/producción, Nginx, PostgreSQL/Redis, scripts de backup/restore, secretos y validación de entorno.
- `.github/workflows`, `security/pentest` y documentación de amenazas/producción.
- Manifiestos y lockfiles; se excluyeron del análisis línea a línea los árboles generados `vendor`, `node_modules`, `dist` y artefactos temporales.

La revisión siguió los límites de confianza del sistema: navegador → BFF Laravel, BFF → OIDC, BFF → PostgreSQL/Redis/S3/colas y CI → imágenes de producción. Se buscaron BOLA/IDOR, aislamiento tenant/clinic, escalada de privilegios, CSRF/XSS/SSRF/inyección, asociaciones inconsistentes entre recursos sanitarios, fugas en respuestas/logs/PDF, agotamiento de recursos, integridad de auditoría, backups y cadena de entrega.

### Criterio de clasificación

- **Directo:** la ruta de código permite reproducir el problema con una sesión válida y datos de prueba.
- **Gap de control:** falta una barrera de defensa en profundidad o una propiedad documentada, aunque el fallo no sea por sí mismo una inyección explotable.
- **Condicional:** requiere una configuración o capacidad operativa concreta (por ejemplo, escritura en el almacén de backups o un staging expuesto).

La severidad se refiere al impacto potencial en confidencialidad, integridad o disponibilidad de PHI, datos identificativos, facturación y sesiones.

## 2. Resumen de hallazgos

| ID | Hallazgo | Severidad | Confianza | Estado |
|---|---|---:|---:|---|
| SEC-001 | El contexto de clínica activa no se aplica a la mayoría de recursos staff | **Crítica*** | Alta | Abierto |
| SEC-002 | PostgreSQL RLS no existe; el rol de aplicación es propietario de la base | Alta | Alta | Abierto |
| SEC-003 | `patients.read` concede descarga de cualquier documento clínico del tenant | Alta | Alta | Abierto |
| SEC-004 | Analytics devuelve cohortes pequeñas pese a declarar mínimo de 5 | Media | Alta | Abierto |
| SEC-005 | Actualización de receta puede reasociar una cita de otro paciente/clínica | Media-alta | Alta | Abierto |
| SEC-006 | Creación de factura permite asociar una cita de otro paciente/clínica | Media | Alta | Abierto |
| SEC-007 | Portal puede consumir un hold de una clínica al reprogramar una cita de otra | Media-alta | Alta | Abierto |
| SEC-008 | Reprogramación staff acepta sala/profesional de otra clínica | Media | Alta | Abierto |
| SEC-009 | Duración de citas sin límite permite envenenar agendas y disponibilidad | Media | Alta | Abierto |
| SEC-010 | Replay de idempotencia evita autorización actual y no respeta expiración | Media | Alta | Abierto |
| SEC-011 | Backup usa AES-CBC sin autenticación criptográfica | Media | Media/condicional | Abierto |
| SEC-012 | Registro de auditoría de handoff tiene carrera `exists` + `create` | Baja-media | Media/condicional | Abierto |
| SEC-013 | Generación síncrona de PDF permite agotamiento de CPU/memoria a escala | Media | Media/condicional | Abierto |
| SEC-014 | Un staging con `APP_ENV` distinto de `production` puede heredar cookies/CORS inseguros | Media | Media/condicional | Abierto |
| SEC-015 | CI no impone firma/provenance de imágenes ni DAST como gate de release | Media | Alta | Abierto |
| SEC-016 | Una sesión solo guarda una transacción OIDC y dos pestañas se pisan | Baja | Alta | Abierto |

\* **SEC-001 es crítica si, como afirma el producto y `PLAN_MAESTRO.md`, una clínica no puede inferir ni acceder a datos de otra.** Si el negocio decide que pacientes, agenda y documentos son deliberadamente compartidos por todo el tenant, debe retirar ese requisito, revisar el modelo de amenazas y reducir el hallazgo a una carencia de mínimo privilegio. El código actual no permite demostrar esa decisión de forma segura.

### Estado posterior a la remediación local (3 de agosto de 2026)

La tabla anterior conserva el estado de apertura de cada hallazgo para mantener la trazabilidad del informe original. Tras la implementación y las pruebas locales, el estado técnico actualizado es el siguiente:

| ID | Estado actual | Evidencia local |
|---|---|---|
| SEC-001 | **Corregido en código** | `membership_clinic`, alcance por clínica en middleware/policies/controladores y pruebas negativas A/B. |
| SEC-002 | **Mitigado en PostgreSQL local** | Migración `2026_08_03_000007_enable_tenant_rls_for_clinical_data.php`, `ENABLE/FORCE ROW LEVEL SECURITY`, políticas fail-closed y contexto `app.tenant_id`. Debe verificarse con un rol runtime gestionado en el entorno objetivo. |
| SEC-003 | **Corregido en código** | `documents.read` separado de `patients.read`; descarga/generación y prueba de PDF actualizadas. |
| SEC-004 | **Corregido en código** | Analytics suprime cohortes inferiores a 5 y devuelve metadatos de privacidad. |
| SEC-005–SEC-009 | **Corregidos en código** | Validación de asociaciones dentro de clínica, controles de portal y límite de duración con `CHECK` PostgreSQL. |
| SEC-010 | **Corregido en código** | TTL, autorización al reproducir, normalización de operación y soporte de respuestas `Responsable`. |
| SEC-011 | **Mitigación local** | Backup con HMAC y hash lateral; en producción falta KMS/envelope encryption y almacenamiento inmutable gestionado. |
| SEC-012 | **Corregido en código** | Índice parcial único y manejo de carrera en el listener de auditoría. |
| SEC-013 | **Residual** | La generación de PDF sigue siendo síncrona; antes de PHI real debe aislarse en worker/cola con cuotas, timeout y alertas. |
| SEC-014 | **Corregido en código** | Validación estricta de entorno de producción y cookies/CORS seguros. No usar datos reales en staging. |
| SEC-015 | **Residual operativo** | CI ejecuta CodeQL, Trivy, SBOM, auditorías y tests; faltan firma/provenance obligatorios y DAST/pentest como gates del despliegue. |
| SEC-016 | **Corregido en código** | Transacciones OIDC indexadas por `state`, TTL/límite por sesión y prueba de pestañas paralelas. |

Este estado no sustituye un pentest, una revisión legal sanitaria ni la validación de un proveedor cloud. El alcance de la columna "corregido" es el código y el Compose local reproducible; los controles externos siguen siendo bloqueantes para datos sanitarios reales.

## 3. Hallazgos detallados

### SEC-001 — Aislamiento por clínica ausente en recursos staff

**Clasificación:** BOLA/IDOR y control de acceso por atributos incompleto.  
**Severidad:** Crítica bajo el requisito de aislamiento por centro; Alta en cualquier otro caso.  
**Confianza:** Alta.  
**Estado:** Abierto.

**Evidencia:**

- `apps/api/app/Http/Middleware/ResolveTenantContext.php:108-140` resuelve una clínica activa comprobando solo `tenant_id` e `is_active`.
- `apps/api/database/migrations/2026_07_18_222904_create_memberships_table.php:14-24` no contiene relación de membership con clínica.
- `apps/api/database/migrations/2026_07_18_222913_create_patients_table.php:14-34` no contiene `clinic_id` para pacientes.
- `apps/api/app/Policies/AppointmentPolicy.php:31-34,47-50,67-70` comprueba únicamente `belongsToCurrentTenant`.
- `apps/api/app/Http/Controllers/Api/V1/AppointmentController.php:42-70` acepta un `clinicId` arbitrario del tenant en el listado y no aplica `currentClinic` si no se envía filtro.
- `apps/api/app/Http/Controllers/Api/V1/AppointmentController.php:78-104` crea la cita con cualquier clínica tenant-válida; `StoreAppointmentRequest.php:32-42` valida tenant, no clínica activa/contextual.
- `apps/api/app/Policies/PrescriptionPolicy.php:30-33,46-49`, `InvoicePolicy.php:30-33,46-49` y `ClinicalDocumentPolicy.php:31-47` tampoco comprueban la clínica.
- Prescripciones (`PrescriptionController.php:49-55`), facturas (`InvoiceController.php:47-52`), lista de espera (`WaitlistController.php:47-51`), comunicaciones (`PatientCommunicationController.php:53-59`) y pacientes (`PatientController.php:39-57`) son tenant-wide.
- `apps/api/app/Http/Controllers/Auth/SelectTenantContextController.php:38-52` permite seleccionar cualquier clínica activa del tenant de la membership; no existe una membership clínica.
- El requisito está documentado en `PLAN_MAESTRO.md:15,265,396` y en la matriz de amenazas `docs/threat-model.md:157-174`.

**Escenario reproducible:** una cuenta staff con `appointments.read` inicia sesión en clínica A y obtiene el identificador de una cita o clínica B por una respuesta legítima, una pantalla o un registro de trabajo. Puede solicitar `GET /api/v1/appointments?clinicId=<clinica-B>` o `GET /api/v1/appointments/<cita-B>`; la autorización pasa porque el tenant coincide. Con permisos de escritura puede editar, cancelar, reprogramar o crear recursos en B. El mismo patrón se aplica a recetas, facturas, documentos, comunicaciones, lista de espera y directorio de pacientes.

**Impacto:** lectura y modificación cruzada de PHI, agenda y datos económicos entre centros. La ausencia de `clinic_id` en pacientes hace imposible expresar una separación de pacientes a nivel de datos sin rediseño.

**Corrección requerida:** introducir una relación explícita de alcance (`membership_clinics` o ABAC equivalente), una única función central `belongsToCurrentClinic`, y filtros obligatorios en repositorios, policies, FormRequests y rutas. Validar que clínica, sala, profesional, tipo, paciente, cita, documento, receta e invoice pertenecen al mismo contexto. Añadir pruebas negativas A/B para cada endpoint y registrar denegaciones. Si pacientes son compartidos intencionadamente, separar el permiso de directorio compartido del permiso de historial clínico por clínica.

**Prueba de cierre:** dos clínicas del mismo tenant, dos usuarios con idéntico rol y contextos distintos; cada lectura, escritura, descarga, búsqueda y filtro debe devolver 403/404 o conjunto vacío para el centro no autorizado. La prueba debe correr sobre PostgreSQL, no solo SQLite.

### SEC-002 — PostgreSQL RLS prometido pero no implementado

**Clasificación:** ausencia de segunda barrera de aislamiento.  
**Severidad:** Alta.  
**Confianza:** Alta.  
**Estado:** Abierto.

**Evidencia:** no existen `CREATE POLICY`, `ALTER TABLE ... ENABLE/FORCE ROW LEVEL SECURITY`, `current_setting` ni `SET LOCAL` para tenant/actor en `apps/api/database`, `apps/api/app` o `infra` (búsqueda exhaustiva de esos tokens).

- `apps/api/app/Models/Concerns/BelongsToTenant.php:20-30` solo agrega un global scope Eloquent; `:60-65` ofrece `withoutGlobalScope` mediante `forTenant`.
- `infra/postgres/init/00-provision-databases.sh:41-50` crea el usuario como `NOBYPASSRLS`, pero también lo hace propietario de la base (`ALTER DATABASE ... OWNER TO`) y `:76-80` lo hace propietario del esquema y le concede todo.
- `docs/threat-model.md:83,174,209-215` y `PLAN_MAESTRO.md:265,330,464` tratan RLS/`SET LOCAL` como control esperado, no como control verificado.

**Impacto:** cualquier consulta raw, `withoutGlobalScope`, job, migración o regresión que olvide `tenant_id` puede leer o mutar otro tenant. Un propietario de tabla puede saltarse RLS salvo que se use `FORCE ROW LEVEL SECURITY`; `NOBYPASSRLS` por sí solo no compensa la propiedad. Los tests con SQLite no ejercitan políticas PostgreSQL ni exclusiones de rango.

**Corrección requerida:** separar propietario de migraciones/DDL y rol runtime sin ownership; crear políticas RLS para todas las tablas de negocio, `FORCE ROW LEVEL SECURITY`, contexto de tenant/actor con `SET LOCAL` dentro de cada transacción, y prohibir consultas de negocio sin contexto. Mantener la autorización funcional además de RLS.

**Prueba de cierre:** integración con PostgreSQL real que ejecute consultas directas, Eloquent, jobs y transacciones con dos tenants; comprobar que el rol runtime no puede desactivar políticas ni ver filas ajenas, y que un contexto ausente falla cerrado.

### SEC-003 — `patients.read` abre todos los documentos clínicos del tenant

**Clasificación:** sobreotorgamiento de privilegios en descarga de PHI.  
**Severidad:** Alta.  
**Confianza:** Alta.  
**Estado:** Abierto.

**Evidencia:**

- `apps/api/app/Policies/ClinicalDocumentPolicy.php:23-26` permite `viewAny` con `documents.read`, `patients.read` o `portal.self`.
- `ClinicalDocumentPolicy.php:31-47` permite `view` de cualquier documento del tenant si el usuario tiene `documents.read` **o `patients.read`**; no comprueba clínica, paciente, tipo documental ni relación asistencial.
- `apps/api/routes/api.php:139-141` expone `/documents/{document}/download` y `DocumentController.php:103-146` solo invoca esa policy antes de devolver el PDF.
- `apps/api/database/seeders/ClinicalDemoSeeder.php:197-225` asigna a recepción `patients.read` pero no `documents.read`.

**Escenario reproducible:** una cuenta de recepción con acceso al directorio de pacientes obtiene un `documentId` a través de una pantalla, enlace o respuesta legítima y descarga una receta, documento clínico o factura de cualquier paciente del tenant. No necesita permiso documental específico.

**Impacto:** exposición de medicación, indicación clínica, identificadores y datos de facturación. Los ULID reducen enumeración, pero no corrigen una policy que concede acceso una vez conocido el identificador.

**Corrección requerida:** separar siempre `patients.read` de `documents.read`; autorizar cada documento por clínica, paciente, tipo y propósito; limitar recepción a documentos administrativos expresamente permitidos. Aplicar la misma policy a generación, listado y descarga y añadir tests negativos por rol y tipo documental.

**Prueba de cierre:** recepción con `patients.read` debe recibir 403/404 para receta, plan clínico y factura; auditoría debe registrar cada descarga autorizada; portal solo debe ver documentos del paciente vinculado y dentro de la vigencia del vínculo.

### SEC-004 — Analytics no aplica el mínimo de grupo declarado

**Clasificación:** inferencia de actividad sanitaria por cohortes pequeñas.  
**Severidad:** Media.  
**Confianza:** Alta.  
**Estado:** Abierto.

**Evidencia:** `apps/api/app/Http/Controllers/Api/V1/AnalyticsController.php:53-60` calcula conteos exactos por clínica, fecha y estado; `:68-78` devuelve métricas, `byStatus` y solo añade el metadato `minimumGroupSize: 5`. No hay supresión, redondeo, bucket temporal, ruido ni límite de consultas diferenciales.

**Escenario reproducible:** un usuario autorizado consulta un día, un estado o una ventana de una sola cita; después compara ventanas adyacentes para reconstruir entradas/salidas y estados de personas.

**Impacto:** fuga de información operacional y potencialmente sanitaria (presencia, cancelación o no-show de una persona) sin que la respuesta contenga nombres.

**Corrección requerida:** suprimir todas las métricas y desglose cuando el tamaño sea menor que 5, agrupar estados/fechas, limitar consultas diferenciales por actor y registrar el motivo de acceso. No declarar un mínimo de privacidad si el servidor no lo aplica.

**Prueba de cierre:** fixtures de 0–4, 5 y 6 citas; comprobar que no se filtra ningún conteo exacto ni diferencia reconstruible en los casos pequeños.

### SEC-005 — La actualización de una receta puede reasociar una cita de otro paciente

**Clasificación:** integridad de asociación PHI.  
**Severidad:** Media-alta.  
**Confianza:** Alta.  
**Estado:** Abierto.

**Evidencia:** `UpdatePrescriptionRequest.php:27-33` hereda la regla de `appointmentId` tenant-scoped y elimina `clinicId`/`patientId`; `apps/api/app/Http/Controllers/Api/V1/PrescriptionController.php:108-125` escribe `appointment_id` sin llamar a `validateAppointmentContext()` (que sí se usa en `:63-68` al crear). `2026_07_18_223154_create_prescriptions_table.php:34-36` solo impone FKs compuestas por tenant.

**Escenario reproducible:** un usuario con `prescriptions.write` actualiza un borrador de paciente A enviando el `appointmentId` de una cita B del mismo tenant. La versión, estado y tenant son válidos; no se valida paciente ni clínica.

**Impacto:** una receta puede aparecer vinculada a otra atención/paciente, alimentar un PDF o timeline equivocado y contaminar trazabilidad clínica.

**Corrección requerida:** hacer inmutable la cita una vez creada o validar dentro de la transacción que cita, paciente y clínica coinciden con la receta bloqueada. Añadir restricciones/índices compuestos y pruebas de asociación cruzada.

### SEC-006 — La creación de una factura acepta una cita ajena del mismo tenant

**Clasificación:** integridad financiera y de PHI.  
**Severidad:** Media.  
**Confianza:** Alta.  
**Estado:** Abierto.

**Evidencia:** `StoreInvoiceRequest.php:32-46` valida `clinicId`, `patientId`, `coverageId` y `appointmentId` de forma independiente por tenant. `InvoiceController.php:60-81` solo llama a `validateCoverage()` para verificar la cobertura respecto del paciente; no valida la cita. `InvoiceController.php:277-285` tampoco comprueba clínica/cita. `2026_07_18_223824_create_invoices_table.php:35-38` solo tiene FKs compuestas por tenant.

**Escenario reproducible:** facturación crea una factura para paciente A y clínica A con `appointmentId` de paciente B o clínica B del mismo tenant. La petición pasa todas las reglas actuales.

**Impacto:** facturación, cobertura y documentación inconsistentes; posible exposición o reclamación económica incorrecta.

**Corrección requerida:** reutilizar un validador de asociación común (`appointment.patient_id === invoice.patient_id`, `appointment.clinic_id === invoice.clinic_id`, tenant igual) dentro de una transacción y reforzar con constraints compuestas.

### SEC-007 — El portal puede usar un hold de una clínica al reprogramar otra

**Clasificación:** autorización contextual e integridad de agenda.  
**Severidad:** Media-alta.  
**Confianza:** Alta.  
**Estado:** Abierto.

**Evidencia:** `ReschedulePortalAppointmentRequest.php:31-39` solo valida que `holdId` pertenezca al tenant. `PortalController.php:472-500` comprueba paciente/versión, consume el hold y copia sus horas, sala y profesional a la cita; `assertOwnedActiveHold()` (`:648-655`) solo verifica paciente, creador, estado y expiración. No se comparan `hold.clinic_id`/`appointment_type_id` con la cita ni se exige `currentClinic` en esta ruta.

**Escenario reproducible:** un usuario portal con acceso a más de una clínica crea un hold válido en B, selecciona una cita propia de A y llama a la reprogramación con ese hold. La cita conserva `clinic_id` A pero recibe sala/profesional/horario de B.

**Impacto:** agenda inconsistente, datos de clínica incorrectos y posible notificación al profesional o centro equivocado.

**Corrección requerida:** enlazar el hold de forma exclusiva con la cita objetivo o comprobar en la transacción que clínica, tipo, sala, profesional, canal y paciente coinciden; exigir el contexto clínico permitido y consumir el hold solo después de todas las comprobaciones.

### SEC-008 — Reprogramación staff permite sala y profesional de otra clínica

**Clasificación:** integridad de agenda y control de alcance.  
**Severidad:** Media.  
**Confianza:** Alta.  
**Estado:** Abierto.

**Evidencia:** `RescheduleAppointmentRequest.php:34-40` valida `roomId` y `practitionerId` solo por tenant. `AppointmentController.php:159-175` hace `findOrFail()` de ambos y los pasa a `Appointment::reschedule()`. `apps/api/app/Models/Appointment.php:190-209` cambia esos campos sin comprobar la clínica.

**Escenario reproducible:** un usuario con `appointments.write` reprograma una cita de A con `roomId` y/o `practitionerId` de B. Las exclusiones temporales evitan solapamientos, pero no la pertenencia al centro.

**Corrección requerida:** validar `room.clinic_id === appointment.clinic_id`, profesional asignado a esa clínica y tipo/canal compatibles, antes de bloquear y guardar; cubrirlo con pruebas de clínica A/B.

### SEC-009 — Duración de citas sin límite: envenenamiento de agenda/DoS lógico

**Clasificación:** disponibilidad y abuso de recursos.  
**Severidad:** Media.  
**Confianza:** Alta.  
**Estado:** Abierto.

**Evidencia:** `StoreAppointmentRequest.php:38-40` y `RescheduleAppointmentRequest.php:34-37` solo exigen `endsAt` posterior a `startsAt`; no hay máximo, horizonte futuro ni duración derivada de `AppointmentType`. `AppointmentController.php:90-104` persiste el intervalo recibido. Las constraints de `2026_07_18_222914_create_appointments_table.php:48-53` impiden solapamientos, pero no intervalos excesivamente largos.

**Escenario reproducible:** una cuenta con permiso de escritura crea una cita de semanas o meses; esa fila bloquea disponibilidad de paciente/profesional/sala y distorsiona analytics. Varias cuentas pueden repetirlo dentro de los límites de rate limiting.

**Corrección requerida:** derivar duración y buffers del tipo de cita, imponer máximo y horizonte (por ejemplo, 24 h y 12 meses, ajustados al negocio), limitar volumen por clínica y exigir un flujo break-glass auditado para excepciones.

**Prueba de cierre:** intentos con 24 h + 1 s, pasado, horizonte lejano y múltiples recursos; comprobar rechazo y que la disponibilidad no queda envenenada.

### SEC-010 — Replay de idempotencia evita autorización actual y la expiración no se aplica

**Clasificación:** replay de respuesta sensible y retención incorrecta.  
**Severidad:** Media.  
**Confianza:** Alta.  
**Estado:** Abierto.

**Evidencia:**

- `apps/api/app/Http/Middleware/EnforceIdempotency.php:51-73` busca la clave por actor/operación/hash y la crea con `expires_at` a un día (`:71`), pero la consulta no filtra `expires_at`.
- `EnforceIdempotency.php:75-85` devuelve `response_body` directamente si existe, antes de ejecutar `$next($request)`. Por tanto, no se vuelve a ejecutar el FormRequest ni la policy de la ruta.
- `2026_07_18_222921_create_idempotency_keys_table.php:25-29` define expiración, pero no se encontró job/comando que la aplique ni un `where expires_at > now()` en el middleware.
- La clave de lock (`:46`) no incluye clínica, versión de permisos ni contexto de assurance.

**Escenario reproducible:** un actor crea una cita/documento con una clave y conserva esa clave; después pierde `appointments.write`/`documents.generate` o cambia de clínica, pero su membership sigue activa. Repite la misma petición y recibe la respuesta almacenada sin pasar por autorización contextual. El resultado no vuelve a mutar el recurso, pero puede revelar el cuerpo de una operación que ya no está autorizado a consultar y permanece más de lo previsto.

**Corrección requerida:** evaluar autenticación, membership, clínica y policy antes de devolver un replay; ligar el registro a un hash de permiso/contexto o invalidarlo cuando cambia el contexto; aplicar TTL real y poda; reducir cuerpos almacenados a identificadores mínimos y nunca almacenar PHI innecesaria.

**Prueba de cierre:** crear una respuesta, revocar permiso/cambiar clínica, repetir la clave y comprobar 403/404; superar TTL y comprobar que la clave se puede reutilizar de forma segura o se elimina según contrato.

### SEC-011 — Backup cifrado con AES-CBC no autenticado

**Clasificación:** confidencialidad/integridad de backups.  
**Severidad:** Media si el almacén es inmutable y el digest está protegido; Alta para un almacén donde un atacante pueda sustituir objetos.  
**Confianza:** Media, condicional.

**Evidencia:** `infra/scripts/backup-postgres.sh:49-54` usa `openssl enc -aes-256-cbc -pbkdf2 ...` y escribe un SHA-256 separado. `restore-postgres.sh:32-57` comprueba el digest si existe y descifra con el mismo CBC. CBC no incluye autenticación; un digest almacenado junto al objeto solo detecta corrupción si el digest permanece confiable.

**Impacto:** un operador o atacante con escritura en el repositorio de backups puede sustituir/alterar el backup sin una prueba criptográfica de origen e integridad. La alteración puede producir restauraciones corruptas o, según el formato manipulado, contenido no confiable para `pg_restore`.

**Corrección requerida:** usar envelope encryption del KMS con AEAD (AES-GCM/ChaCha20-Poly1305) y AAD de tenant/fecha, o MAC/firma independiente almacenada en un canal inmutable; proteger el catálogo de hashes, activar Object Lock/versionado y probar restauraciones aisladas.

### SEC-012 — Carrera en la idempotencia del audit handoff

**Clasificación:** integridad de trazabilidad.  
**Severidad:** Baja-media.  
**Confianza:** Media, condicional.

**Evidencia:** `apps/api/app/Listeners/RecordQueuedCommunicationDispatch.php:64-92` ejecuta `exists()` y después `AuditEvent::create()`. `2026_07_18_222920_create_audit_events_table.php:29-33` no tiene una unicidad natural para `(tenant_id, event_type, auditable_type, auditable_id)`. `WithoutOverlapping` (`:51-58`) reduce la carrera, pero depende de que el lock compartido esté sano y expira a 120 s.

**Impacto:** dos workers, una expiración de lock o una recuperación de cola pueden dejar dos eventos de handoff para una sola comunicación, debilitando auditoría y métricas de entrega.

**Corrección requerida:** índice único (o clave de deduplicación) y `insert ... on conflict do nothing`/upsert dentro de una transacción; mantener el lock como optimización, no como garantía.

### SEC-013 — Generación síncrona de PDF sin cuota global

**Clasificación:** agotamiento de CPU/memoria y degradación de disponibilidad.  
**Severidad:** Media, condicional a volumen y número de cuentas comprometidas.  
**Confianza:** Media.

**Evidencia:** `AppServiceProvider.php:42-47` limita a 30 PDFs por minuto por usuario/IP, pero las rutas de generación (`routes/api.php:130-138`) ejecutan el flujo en la petición. `ClinicalPdfGenerator.php:285-300` renderiza con Dompdf y solo limita el resultado a 10 MB; no hay cuota global por tenant, concurrencia de workers ni presupuesto de CPU/memoria.

**Impacto:** varias cuentas válidas o un tenant grande pueden consumir workers PHP y memoria mientras se generan documentos, afectando login y API clínica.

**Corrección requerida:** mover renderizado a una cola aislada con concurrencia limitada, cuota por tenant/IP, tamaño de entrada, timeout, circuit breaker y métricas de coste; mantener respuesta asíncrona y descarga posterior.

### SEC-014 — Staging no fuerza el contrato de seguridad de producción

**Clasificación:** configuración peligrosa en despliegues no marcados como producción.  
**Severidad:** Media, condicional a exponer staging con datos reales o Internet.  
**Confianza:** Media.

**Evidencia:** `AppServiceProvider.php:57-61` solo ejecuta las comprobaciones estrictas cuando `isProduction()`. `config/session.php:132-176` deriva el nombre `__Host-` y `secure` de `APP_ENV`; `config/cors.php:3-15` usa `http://localhost:4200` como fallback. `infra/scripts/validate-production-env.sh:21` exige `APP_ENV=production`, pero no existe un contrato equivalente de staging seguro.

**Impacto:** un staging con `APP_ENV=staging` puede aceptar cookie no `Secure`/no `__Host-`, CORS de desarrollo o endpoints OIDC menos estrictos. Si se cargan datos reales, una visita HTTP o un origen permitido por error aumenta el riesgo de toma de sesión/CSRF.

**Corrección requerida:** prohibir PHI en staging, validar siempre HTTPS, cookie segura, CORS exacto, OIDC estricto y hosts/proxies confiables en cualquier entorno conectado; separar flags de demo de la identidad del entorno y fallar cerrado.

### SEC-015 — La cadena CI no demuestra procedencia ni bloquea DAST/pentest

**Clasificación:** riesgo de supply chain y gobierno de release.  
**Severidad:** Media.  
**Confianza:** Alta.

**Evidencia:** `.github/workflows/quality.yml:29-37,59-85,116-123,159-185` ejecuta CodeQL, Trivy, SBOM, auditorías de dependencias, tests y builds. `.github/workflows/security-dast.yml:3-9` es únicamente `workflow_dispatch`; el ZAP baseline (`:20-31`) no es un gate automático. No existe workflow de deploy con firma/provenance/attestation (cosign/SLSA) ni comprobación obligatoria de firma en `infra/production`.

**Impacto:** una imagen construida puede llegar a un registro/despliegue sin prueba verificable de origen o sin que DAST haya pasado. La documentación (`infra/production/README.md`) recomienda firmar, pero una recomendación no impide un release manual.

**Corrección requerida:** firmar imágenes y SBOM con identidad OIDC de CI, publicar attestation de provenance, desplegar solo digests firmados, convertir DAST/autorización de pentest en checks requeridos y conservar evidencias por release.

### SEC-016 — Transacción OIDC única por sesión

**Clasificación:** disponibilidad/UX de autenticación.  
**Severidad:** Baja.  
**Confianza:** Alta.

**Evidencia:** `apps/api/app/Services/Identity/SecureKeycloakProvider.php:24,74,112` guarda y consume una única entrada `auth.oidc.transaction` en la sesión. Una segunda pestaña sustituye `state`, nonce y PKCE de la primera.

**Impacto:** el primer callback falla y obliga a reintentar. No se observa bypass de autenticación porque el state/nonce/PKCE se siguen verificando, pero el patrón puede producir soporte y reintentos repetidos.

**Corrección requerida:** guardar transacciones indexadas por state (con TTL corto y límite por sesión), consumir de forma atómica y revocar todas al cerrar sesión.

## 4. Controles que sí se verificaron

No se encontraron evidencias de un bypass directo de autenticación OIDC, XSS DOM obvio, `eval`, ejecución de comandos desde entrada HTTP o SQL concatenado. En concreto:

- OIDC usa state/nonce/PKCE, sesiones opacas, validación de issuer/audience/azp/exp/iat/nonce/ACR/AMR y JWKS con algoritmos asimétricos.
- El BFF usa cookies HttpOnly/Secure/SameSite, XSRF de Angular/Laravel, rotación de sesión y no almacena tokens en `localStorage`.
- El interceptor Angular no registra cuerpos, tokens ni PHI; los errores de API se normalizan a mensajes genéricos con trace ID.
- No se encontraron sinks `innerHTML`, `bypassSecurityTrust`, `eval`, `window.open` ni `document.write` en la aplicación revisada. Las plantillas Blade y Angular usan interpolación escapada.
- Las descargas PDF usan almacenamiento privado, cifrado Laravel, digest SHA-256 del contenido, validación de `%PDF-`, headers `no-store` y nombres generados por el servidor (`ClinicalPdfGenerator.php:202-248`).
- El modelo de concurrencia de citas tiene exclusiones PostgreSQL para paciente/profesional/sala (`2026_07_18_222914_create_appointments_table.php:48-53`); no se reporta aquí como doble reserva confirmada. Falta probarlo en PostgreSQL real porque SQLite no reproduce esas constraints.
- El pipeline ya incluye CodeQL, Trivy, SBOM, `composer audit`, `pnpm audit` y tests. Eso reduce riesgo conocido de dependencias, pero no detecta los defectos de autorización de este informe.

Estos controles son positivos, pero no compensan SEC-001/SEC-002 ni convierten el sistema en producción sanitaria.

## 5. Observaciones de hardening no contabilizadas como exploit independiente

1. Varias reglas aceptan registros tenant-válidos aunque estén inactivos (clínica, sala, tipo, cobertura o membresía en algunos flujos). Debe existir una política coherente de ciclo de vida.
2. `clinical_notes` (`2026_07_26_200000_create_clinical_notes_table.php:13-16`) y alguna tabla de simulación usan FKs simples a `id` en lugar de FKs compuestas por tenant; los controladores hoy validan relaciones, pero la base no ofrece la misma defensa que el resto del esquema.
3. No se encontró un job de retención/purga para claves de idempotencia, objetos huérfanos de PDF, auditoría o datos soft-deleted. Debe definirse retención sanitaria, legal hold, borrado seguro y restauración.
4. El plan de pentest (`security/pentest/PLAN.md:6-27`) está bien delimitado, pero no es evidencia de ejecución. Debe realizarlo un tercero sobre staging sintético y retestear todos los hallazgos altos.
5. La asignación de roles permite que un administrador con `admin.users.write` otorgue el rol `tenant-admin`/`*`. Está protegida por MFA reciente y bloquea auto-modificación, pero producción sanitaria debería añadir doble control, separación de funciones y alertas de escalada.

## 6. Plan de remediación priorizado

### P0 — Bloqueo antes de datos reales o exposición pública

1. Decidir y documentar el límite clínica/tenant. Si el aislamiento por centro es requisito, implementar membership-clinic/ABAC y corregir SEC-001, SEC-005, SEC-006, SEC-007 y SEC-008 como una única iniciativa.
2. Implementar RLS real, `FORCE ROW LEVEL SECURITY`, rol runtime separado del owner y pruebas PostgreSQL (SEC-002).
3. Separar `patients.read` de documentos y aplicar autorización por documento/paciente/clínica (SEC-003).
4. Ejecutar pruebas negativas multi-tenant/multi-clínica de cada ruta, incluido portal, descarga PDF, jobs y consultas raw.
5. No introducir PHI real hasta completar pentest independiente, restauración de backup aislada, KMS/secrets manager, WAF/TLS y revisión legal sanitaria.

### P1 — Antes de un release candidato

1. Aplicar supresión de cohortes y protección diferencial de analytics (SEC-004).
2. Corregir asociaciones de receta/factura, duración/horizonte de citas y replay/TTL de idempotencia (SEC-005, SEC-006, SEC-009, SEC-010).
3. Migrar backups a AEAD/KMS con firma y almacenamiento inmutable; hacer un restore documentado y medido (SEC-011).
4. Hacer idempotente el audit handoff en la base, con alerta si falla la deduplicación (SEC-012).
5. Aislar la generación de PDF en cola con cuotas y observabilidad (SEC-013).
6. Firmar imágenes/SBOM, exigir provenance y convertir DAST/pentest en gates de release (SEC-015).

### P2 — Operación continua

1. Contrato seguro para todos los entornos, incluido staging; datos sintéticos y flags de simulación siempre apagados fuera de demo (SEC-014).
2. Transacciones OIDC por `state` y límites por sesión (SEC-016).
3. Retención, borrado, legal hold, rotación de claves, alertas de acceso anómalo, revisión trimestral de permisos y ejercicios de recuperación.

## 7. Criterios de aceptación del cierre

El proyecto puede pasar a una fase de certificación cuando, como mínimo:

- la matriz A/B de clínica y tenant tenga 0 lecturas/escrituras/descargas cruzadas en tests automatizados;
- PostgreSQL real confirme RLS forzado con el rol runtime, sin depender únicamente de Eloquent;
- los casos de asociación paciente–cita–clínica–receta–factura–documento tengan constraints y pruebas negativas;
- analytics no revele cohortes menores que el umbral aprobado;
- se haya demostrado restore desde un backup reciente, con integridad autenticada, RPO/RTO medidos y datos aislados;
- imágenes desplegadas estén firmadas, fijadas por digest y acompañadas de SBOM/provenance;
- ZAP/DAST, pentest independiente y retest estén archivados junto al release;
- exista aprobación de responsable de seguridad, responsable clínico y asesoría legal/privacidad para el tratamiento de datos sanitarios.

## 8. Limitaciones y conclusión

No se ejecutó una explotación contra producción, un IdP/KMS/WAF gestionado ni un pentest externo. Las pruebas locales disponibles (PHPUnit, build Angular, auditorías de dependencias y Compose) son útiles para regresión, pero no prueban RLS, aislamiento por clínica ni controles operativos de cloud. No se deben interpretar como autorización para cargar datos reales.

La prioridad técnica inequívoca es **cerrar el límite de clínica y añadir RLS real antes de seguir ampliando funcionalidades**. Hasta entonces, el sistema puede continuar como demo con datos sintéticos y como base de trabajo, pero no debe presentarse como plataforma sanitaria lista para producción.
