# Plan: Concurrencia y no-solapamiento de reservas (paciente, profesional, sala)

Fecha: 2026-08-05 · Repo: nexasalud-clinica · Ámbito: `apps/api` (Laravel 13, PHP 8.4, PostgreSQL)

## 1. Objetivo y contexto real

Garantizar que dos transacciones concurrentes **nunca** creen reservas cuyos intervalos se solapen sobre el mismo recurso: `patient_id`, `clinician_user_id` y `room_id`, dentro del mismo `tenant_id`.

El repositorio **ya implementa** el mecanismo correcto en la base de datos:

- Tabla `appointments` (`2026_07_18_222914_create_appointments_table.php`):
  - `CREATE EXTENSION IF NOT EXISTS btree_gist` y tres constraints de exclusión:
    - `appointments_patient_no_overlap` — `EXCLUDE USING gist (tenant_id WITH =, patient_id WITH =, tstzrange(starts_at, ends_at, '[)') WITH &&) WHERE (deleted_at IS NULL AND status IN (…))`
    - `appointments_clinician_no_overlap` — análoga con `clinician_user_id`.
    - `appointments_room_no_overlap` — análoga con `room_id IS NOT NULL`.
  - `CHECK (ends_at > starts_at)` — invariante de orden temporal.
- Tabla `appointment_slot_holds` (`2026_07_19_012000_create_appointment_slot_holds_table.php`): exclusion constraints espejo filtradas por `status = 'active'` (retenciones provisionales con `expires_at`).
- `AppointmentStatus::blocksAvailability()` define los estados que reservan disponibilidad: `scheduled, confirmed, checked_in, in_progress`.
- `WaitlistMatchEngine` (líneas ~280–360) consulta solapamientos con el predicado `starts_at < :end AND ends_at > :start` (equivalente a `tstzrange &&`). Este SELECT de lectura **no es la barrera de seguridad**: la barrera real es el constraint.

Estado bloqueante vs. no bloqueante (cancelada/no_show/completed) se resuelve con el predicado `WHERE` **parcial** de cada exclusion constraint — es un detalle crítico ya resuelto en el diseño.

## 2. Modelo formal del invariante

Un intervalo es el rango `[starts_at, ends_at)` (semiabierto: cita que termina a las 10:00 no choca con otra que empieza a las 10:00).

Invariante: para el par de recursos `R ∈ {patient, clinician, room}` y tenant `T`, no existen dos filas `a, b` con
`a.tenant_id = b.tenant_id = T` ∧ `a.R = b.R` ∧ `a.[s,e) && b.[s,e)` ∧ ambas en estado que bloquea.

Solapamiento (condición de conflicto): `a.starts_at < b.ends_at ∧ b.starts_at < a.ends_at` ≡ `tstzrange && tstzrange`.

## 3. Las carreras posibles

Con `READ COMMITTED` (default de PostgreSQL) y patrón check-then-insert sin bloqueo:

1. **Carrera de doble comprobación**: T1 y T2 ejecutan `SELECT … WHERE overlap` → ambas ven 0 filas → ambas hacen `INSERT` → doble reserva. Es la carrera clásica; el SELECT nunca es suficiente.
2. **Carrera de reserva por mutación**: T1 hace `UPDATE status` de scheduled→confirmed (mismos recursos) y T2 hace INSERT solapado → sin constraint, ambas pasan.
3. **Carrera de retención (hold)**: create-hold y create-appointment concurrentes sobre el mismo slot — misma anatomía que (1) pero entre dos tablas distintas. El constraint de exclusión es por tabla; el cruce hold↔appointment necesita chequeo aplicativo adicional (ver riesgos).
4. **Carrera de liberación/actualización**: T1 acorta `ends_at` (libera el hueco), T2 inserta solapando el intervalo antiguo — se serializa contra el constraint con el valor final, no intermedio.
5. **Serialización fantasma bajo REPEATABLE READ/SERIALIZABLE**: dos inserciones sin solapamiento visible en el snapshot pueden serializarse mal; el constraint las ordena igualmente, pero `SERIALIZABLE` añade `SQLSTATE 40001` que hay que reintentar.

### Aislamiento transaccional recomendado
- **READ COMMITTED** como nivel base: con exclusion constraints es suficiente y minimiza falsos positivos.
- El código Laravel actual ya envuelve la mutación en `DB::transaction`/`lockForUpdate` sobre la fila del hold (ver `ScheduleWaitlistOffer`); eso evita la carrera (3) internamente pero **no sustituye** al constraint.
- **No usar SERIALIZABLE** salvo prueba específica: multiplica reintentos (40001) sin ganar garantía que el constraint ya da.

## 4. Comparación de mecanismos

### 4.1 Bloqueos pesimistas (SELECT … FOR UPDATE)
Patrón: `BEGIN; SELECT * FROM appointments WHERE overlap FOR UPDATE; INSERT…; COMMIT;`

- Ventajas: portátil; semántica familiar; el bloqueo es visible y controlable.
- Limitaciones:
  - Los bloqueos de fila **no impiden la inserción de nuevas filas** solapadas (no hay fila que bloquear aún). Bloquear la fila del recurso (`SELECT … FROM rooms WHERE id = ? FOR UPDATE`) serializa por recurso completo → contención excesiva; una única sala caliente serializa todas sus reservas.
  - Hay que elegir el "ancla" del bloqueo (fila del paciente, del profesional y de la sala) y ordenarlos siempre (paciente→clinician→room) para evitar deadlocks. Tres recursos ⇒ orden estricto de adquisición obligatorio.
- Veredicto: útil como defensa adicional en flujos de hold (ya se usa `lockForUpdate` sobre `appointment_slot_holds`), pero **insuficiente** como garantía de no-solapamiento global.

### 4.2 Advisory locks (`pg_advisory_xact_lock(hashtextextended(…))`)
Patrón: tomar un lock por clave canónica `tenant:resource:id:slot_bucket` antes del INSERT.

- Ventajas: evita contención de filas; granularidad controlada (por recurso y ventana).
- Limitaciones:
  - La clave debe **canonizar el intervalo** (p. ej. bucket de 15 min) — dos rangos que se solapan pero caen en buckets distintos escapan al lock ⇒ **falsos negativos** si el bucket no cubre todo el rango. Necesita bloquear todos los buckets que toca el intervalo, en fórmula de hashing determinista.
  - No es auto-empujado por la base de datos: si algún camino del código olvida tomar el lock, la garantía se rompe.
  - Más superficie para errores de timeout/deadlock; sin índice que lo respalde.
- Veredicto: buen complemento para serializar secciones críticas cortas (p. ej., conversión hold→cita), pero **no debe ser la barrera principal**.

### 4.3 Exclusion constraints (`EXCLUDE USING gist … tstzrange … &&`) — estructura **elegida**
- Ventajas:
  - **Garantía de base de datos**: válida desde cualquier camino (Eloquent, SQL directo, jobs, batch, migración), sin depender de la disciplina del código.
  - Sin carrera check-then-insert: la comprobación y la inserción son atómicas en el planner.
  - Soporte de predicado parcial (`WHERE status IN …`) — solo bloquea en estados que reservan disponibilidad.
  - Índice GiST sirve también las consultas de "quién está ocupado en este rango" que ya hace `WaitlistMatchEngine::slotIsFree`.
- Costes: requiere extensión `btree_gist` (ULID es texto; btree_gist lo hace usable en GiST); el INSERT paga coste GiST (~O(log n)); los fallos llegan como `SQLSTATE 23P01 exclusion_violation` que Laravel mapea a `QueryException` → hay que traducir a 409 + código de conflicto legible.
- Veredicto: mecanismo estructural correcto, ya presente. Debe permanecer como single source of truth.

### Resumen

| Estrategia | Garantiza solapamiento global | Sin carrera check-then-insert | Granularidad | Riesgos clave |
|---|---|---|---|---|
| Bloqueos FOR UPDATE | No (falta ancla insertable) | Parcialmente | Fila/recurso | Deadlock, serialización excesiva |
| Advisory locks | Solo si la clave cubre todo el rango | Sí si es disciplinado | Clave arbitraria | Falsos negativos, disciplina de código |
| Exclusion constraints | Sí | Sí | Fila, atómica con el índice | Coste GiST, error 23P01 |

## 5. Implementación Laravel (estado y puntos de endurecimiento)

Lo que ya está en el código del repo y se mantiene:
1. Migraciones con `Schema::getConnection()->getDriverName() === 'pgsql'` + `DB::statement` con SQL crudo — el proyecto ya elige el patrón correcto (Blueprint no soporta EXCLUDE).
2. `EXCLUDE USING gist` con predicados parciales por estado bloqueante, y `tstzrange(..., '[)')` semiabierto.
3. `CHECK (ends_at > starts_at)` como invariante de tipo.
4. `WaitlistMatchEngine` serializa claim de entry + creación de la cita provisional bajo `lockForUpdate` del entry; la barrera de solapamiento es el constraint, no el SELECT.

Puntos de endurecimiento (tareas del plan):

T1. **Traducción del error 23P01 a 409**: en `ProblemDetailsFactory` (o handler de excepciones) detectar `QueryException` con `SQLSTATE 23P01` y `constraint_name IN (appointments_patient_no_overlap, appointments_clinician_no_overlap, appointments_room_no_overlap, slot_holds_*)` y devolver `409 Conflict` con detalle del recurso en conflicto. Sin esto, la API responde 500.

T2. **Rechazo explícito antes del INSERT**: el `FormRequest` de creación debe validar fecha futura, duración razonable y que `room_id` sea obligatorio cuando `channel = in_person`. Eso reduce 23P01 esperables, pero no los suprime (el constraint sigue siendo la garantía).

T3. **Test de concurrencia real**: test de feature que lanza dos peticiones HTTP concurrentes (conBench/fork o `parallel` en PHPUnit) creando citas con el mismo `(clinician_user_id, starts_at, ends_at)`:
   - Exactamente 1 debe devolver 201 y la otra 409.
   - La base de datos debe contener exactamente 1 fila bloqueante.
   Cubrir el mismo caso para patient y para room con técnica `Process::run`/two-connection + `pg_sleep` si el runner lo permite; en su defecto, test serializable con dos conexiones PDO nativas dentro del test.

T4. **Test de predicado parcial**: comprobar que `Cancelled`/`NoShow`/`Completed` sí permiten nueva cita en el mismo hueco (el predicado del constraint funciona), y que `deleted_at IS NOT NULL` (soft-delete) también libera.

T5. **Cruce hold↔appointment**: verificar con un test que no se puede consumir un `appointment_slot_hold` cuando ya existe una cita bloqueante creada por otra vía en el mismo hueco. La barrera aquí es aplicativa (orden: lock hold → chequear appointments → INSERT appointment → el 23P01 de la cita revierte la transacción). Documentar la order-of-lock (entry → hold → insert) para evitar deadlock inverso.

T6. **Comprobación de índices**: `EXPLAIN ANALYZE` del SELECT de `slotIsFree` sobre un volumen representativo (seed de 100k citas) para confirmar uso del GiST (los índices B-tree `…(tenant_id, X_id, starts_at)` existentes deberían bastar; no añadir índice nuevo sin medir).

T7. **Higiene de migración**: crear una migración de "verificación" idempotente que haga `CREATE EXTENSION IF NOT EXISTS btree_gist` y valide en `pg_constraint` que las 3+3 constraints existen (guardrail para entornos donde se hayan restaurado dumps parciales).

T8. **Observabilidad**: contador de métricas + log estructurado en el camino de 409 (`resource=patient|clinician|room`, `tenant_id`) para detectar hotspots.

### Fragmento de patrón de servicio (el código final es del agente de implementación)

```php
DB::transaction(function () use ($data) {
    $hold = AppointmentSlotHold::query()
        ->whereKey($data['hold_id'])
        ->lockForUpdate()
        ->firstOrFail();

    throw_if($hold->status !== 'active', DomainException::class, 'Hold no activo');

    try {
        $appointment = Appointment::create([...$data, 'ends_at' => $end]);
    } catch (QueryException $e) {
        if ($e->getCode() === '23P01') {
            throw new OverlapConflictException(/* recurso en conflicto */);
        }
        throw $e;
    }

    $hold->update(['status' => 'consumed', 'appointment_id' => $appointment->id]);
});
```

## 6. Carreras cubiertas / no cubiertas

Cubiertas por el constraint: (1) doble-check-insert, (2) mutación de estado solapada, (4) shorten-update concurrente.

Cubiertas parcialmente por aplicación: (3) cruce hold↔appointment — requiere la disciplina de orden de bloqueo documentada; si se introduce un nuevo camino de creación de citas, hay que pasar por el 23P01 igualmente.

No en el ámbito: replicación multi-región, sharding (el invariante es por base de datos física; el `tenant_id WITH =` restringe por tenant, no entre tenants).

## 7. Plan de validación

1. `php artisan test --compact --filter=AppointmentOverlap` (nuevo test de T3/T4).
2. Suite mínima relacionada: tests de waitlist y de creación de citas.
3. Migración de verificación corre en `migrate:fresh` sin error en pgsql.
4. Manual: dos `curl` concurrentes al endpoint de creación → uno 201, otro 409.
5. `vendor/bin/pint --dirty --format agent` tras los cambios (regla del repo).

## 8. Riesgos y decisiones abiertas

- **Riesgo**: el cruce hold↔appointment no tiene constraint físico compartido; si se añade otra fuente de citas (importación masiva, integración externa) hay que someterla al mismo INSERT en `appointments` para que el 23P01 la cubra. Mitigar con test T5 y con un ADR corto en la raíz del paquete Scheduling.
- **Riesgo**: `EXCLUDE USING gist` coste en INSERTs masivos. Aceptable en volumen clínico; revisar con T6 si aparecen picos.
- **Decisión**: se mantiene `READ COMMITTED`. No se adopta SERIALIZABLE.
- **Fuera de ámbito**: modificar el `tstzrange` a buffers por cita (p. ej., 5 min de limpieza) — requeriría nuevo campo `buffer_minutes` y ampliar el rango; solo si el negocio lo pide después.
