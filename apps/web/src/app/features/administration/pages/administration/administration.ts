import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';

import { AppIcon, AppIconName } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

type SectionId =
  | 'users'
  | 'enrolment'
  | 'roles'
  | 'sessions'
  | 'locations'
  | 'health'
  | 'integrations'
  | 'policies'
  | 'audit';

type PermissionKey = 'clinical' | 'agenda' | 'prescribe' | 'billing' | 'admin';
type Outcome = 'Todos' | 'Permitido' | 'Denegado' | 'Desafío';

interface AdminSection {
  readonly id: SectionId;
  readonly label: string;
  readonly meta: string;
  readonly description: string;
  readonly icon: AppIconName;
}

interface UserAccount {
  readonly id: string;
  readonly initials: string;
  readonly name: string;
  readonly email: string;
  readonly role: string;
  readonly scope: string;
  readonly security: string;
  readonly lastAccess: string;
  readonly state: 'Activa' | 'Suspendida';
}

interface Invitation {
  readonly id: string;
  readonly recipient: string;
  readonly role: string;
  readonly scope: string;
  readonly expires: string;
  readonly state: 'Pendiente' | 'Expira pronto';
}

interface RoleProfile {
  readonly id: string;
  readonly name: string;
  readonly members: number;
  readonly kind: string;
  readonly permissions: Record<PermissionKey, boolean>;
}

interface ActiveSession {
  readonly id: string;
  readonly actor: string;
  readonly device: string;
  readonly location: string;
  readonly activity: string;
  readonly risk: 'Normal' | 'Revisar';
}

interface Resource {
  readonly id: string;
  readonly name: string;
  readonly site: string;
  readonly use: string;
  readonly state: 'Disponible' | 'Mantenimiento';
}

interface Integration {
  readonly id: string;
  readonly initials: string;
  readonly name: string;
  readonly purpose: string;
  readonly scope: string;
  readonly state: 'Conectada' | 'Prueba' | 'Desconectada';
  readonly checked: string;
}

interface AuditEvent {
  readonly id: string;
  readonly time: string;
  readonly actor: string;
  readonly actorDetail: string;
  readonly action: string;
  readonly patient: string;
  readonly resource: string;
  readonly traceId: string;
  readonly outcome: Exclude<Outcome, 'Todos'>;
  readonly reason: string;
}

@Component({
  selector: 'app-administration',
  imports: [MatButtonModule, AppIcon, ScrollReveal],
  templateUrl: './administration.html',
  styleUrls: ['./administration.scss', './administration-content.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Administration {
  protected readonly sections: readonly AdminSection[] = [
    { id: 'users', label: 'Usuarios', meta: '24 cuentas', description: 'Identidad, alcance y estado de las cuentas de trabajo.', icon: 'team' },
    { id: 'enrolment', label: 'Altas y bajas', meta: '3 pendientes', description: 'Ciclo de vida con caducidad, aprobaciones y revocación completa.', icon: 'waitlist' },
    { id: 'roles', label: 'Roles y permisos', meta: '6 perfiles', description: 'Acceso mínimo por función, sede y necesidad asistencial.', icon: 'shield' },
    { id: 'sessions', label: 'Sesiones y MFA', meta: '2 señales', description: 'Factores resistentes a phishing y sesiones bajo supervisión.', icon: 'lock' },
    { id: 'locations', label: 'Sedes y recursos', meta: '7 salas', description: 'Ámbitos físicos, agendas y recursos clínicos reservables.', icon: 'location' },
    { id: 'health', label: 'Salud del sistema', meta: 'Operativo', description: 'Servicios, colas y degradaciones sin exponer contenido clínico.', icon: 'chart' },
    { id: 'integrations', label: 'Integraciones', meta: '5 conectores', description: 'Credenciales, alcance de datos y verificación de conectividad.', icon: 'settings' },
    { id: 'policies', label: 'Políticas', meta: '12 vigentes', description: 'Guardas obligatorias, revisiones y excepciones trazables.', icon: 'check' },
    { id: 'audit', label: 'Auditoría', meta: '128 eventos', description: 'Búsqueda concreta por actor, paciente, recurso, traza y resultado.', icon: 'eye' },
  ];

  protected readonly activeSection = signal<SectionId>('users');
  protected readonly activeSectionDetails = computed(
    () => this.sections.find((section) => section.id === this.activeSection()) ?? this.sections[0],
  );
  protected readonly showContext = signal(false);
  protected readonly liveMessage = signal('Centro de control preparado con datos de demostración.');
  protected readonly userQuery = signal('');

  protected readonly users = signal<UserAccount[]>([
    { id: 'USR-001', initials: 'LV', name: 'Dra. Lara Vega · DEMO', email: 'l••••@demo.nexasalud.test', role: 'Administración clínica', scope: 'Mirador · todas las sedes', security: 'Passkey + MFA', lastAccess: 'Hoy · 09:26', state: 'Activa' },
    { id: 'USR-014', initials: 'NO', name: 'Nicolás Ortega · DEMO', email: 'n••••@demo.nexasalud.test', role: 'Recepción', scope: 'Mirador · Centro', security: 'MFA', lastAccess: 'Hoy · 08:51', state: 'Activa' },
    { id: 'USR-019', initials: 'IA', name: 'Irene Abril · DEMO', email: 'i••••@demo.nexasalud.test', role: 'Facturación', scope: 'Mirador · Centro y Norte', security: 'Passkey + MFA', lastAccess: 'Ayer · 17:42', state: 'Activa' },
    { id: 'USR-022', initials: 'RP', name: 'Raúl Pardo · DEMO', email: 'r••••@demo.nexasalud.test', role: 'Soporte técnico', scope: 'Sin acceso clínico', security: 'MFA', lastAccess: '16 jul · 12:08', state: 'Suspendida' },
  ]);

  protected readonly filteredUsers = computed(() => {
    const query = this.userQuery().trim().toLocaleLowerCase('es');
    if (!query) return this.users();
    return this.users().filter((user) =>
      [user.id, user.name, user.role, user.scope].some((field) => field.toLocaleLowerCase('es').includes(query)),
    );
  });

  protected readonly invitations = signal<Invitation[]>([
    { id: 'INV-8K2', recipient: 'm••••@demo.nexasalud.test', role: 'Profesional médico', scope: 'Mirador · Norte', expires: '48 h', state: 'Pendiente' },
    { id: 'INV-4D7', recipient: 'c••••@demo.nexasalud.test', role: 'Enfermería', scope: 'Mirador · Centro', expires: '6 h', state: 'Expira pronto' },
    { id: 'INV-9M1', recipient: 'a••••@demo.nexasalud.test', role: 'Recepción', scope: 'Mirador · Centro', expires: '72 h', state: 'Pendiente' },
  ]);

  protected readonly permissionColumns: readonly { key: PermissionKey; label: string }[] = [
    { key: 'clinical', label: 'Historia' },
    { key: 'agenda', label: 'Agenda' },
    { key: 'prescribe', label: 'Prescribir' },
    { key: 'billing', label: 'Facturación' },
    { key: 'admin', label: 'Administración' },
  ];

  protected readonly roles = signal<RoleProfile[]>([
    { id: 'ROLE-MED', name: 'Profesional médico', members: 7, kind: 'Clínico', permissions: { clinical: true, agenda: true, prescribe: true, billing: false, admin: false } },
    { id: 'ROLE-NUR', name: 'Enfermería', members: 5, kind: 'Clínico', permissions: { clinical: true, agenda: true, prescribe: false, billing: false, admin: false } },
    { id: 'ROLE-REC', name: 'Recepción', members: 4, kind: 'Gestión', permissions: { clinical: false, agenda: true, prescribe: false, billing: true, admin: false } },
    { id: 'ROLE-ADM', name: 'Administración clínica', members: 2, kind: 'Privilegiado', permissions: { clinical: true, agenda: true, prescribe: false, billing: true, admin: true } },
  ]);

  protected readonly sessions = signal<ActiveSession[]>([
    { id: 'SES-A81', actor: 'Dra. Lara Vega · DEMO', device: 'Safari · macOS', location: 'Madrid · red corporativa', activity: 'Esta sesión · ahora', risk: 'Normal' },
    { id: 'SES-K17', actor: 'Nicolás Ortega · DEMO', device: 'Chrome · Windows', location: 'Madrid · red corporativa', activity: 'Hace 12 min', risk: 'Normal' },
    { id: 'SES-Q42', actor: 'Irene Abril · DEMO', device: 'Safari · iPhone', location: 'Madrid · red móvil', activity: 'Hace 41 min', risk: 'Revisar' },
  ]);

  protected readonly resources = signal<Resource[]>([
    { id: 'RES-C01', name: 'Consulta Roble', site: 'Mirador · Centro', use: 'Consulta general · 30 min', state: 'Disponible' },
    { id: 'RES-C03', name: 'Sala Olivo', site: 'Mirador · Centro', use: 'Procedimientos · 60 min', state: 'Disponible' },
    { id: 'RES-N02', name: 'Consulta Luz', site: 'Mirador · Norte', use: 'Primera valoración · 45 min', state: 'Disponible' },
    { id: 'RES-N04', name: 'Ecógrafo portátil', site: 'Mirador · Norte', use: 'Recurso compartido', state: 'Mantenimiento' },
  ]);

  protected readonly integrations = signal<Integration[]>([
    { id: 'INT-IDP', initials: 'ID', name: 'Proveedor de identidad', purpose: 'Inicio de sesión, MFA y passkeys', scope: 'Identidad mínima', state: 'Conectada', checked: '09:24' },
    { id: 'INT-RX', initials: 'RX', name: 'Receta electrónica', purpose: 'Prescripción homologada', scope: 'Acto y profesional', state: 'Prueba', checked: '08:45' },
    { id: 'INT-LAB', initials: 'LB', name: 'Gateway laboratorio', purpose: 'Resultados estructurados', scope: 'FHIR mínimo', state: 'Conectada', checked: '09:21' },
    { id: 'INT-CAL', initials: 'CA', name: 'Calendario externo', purpose: 'Bloques de disponibilidad', scope: 'Sin datos clínicos', state: 'Desconectada', checked: '—' },
    { id: 'INT-WH', initials: 'WH', name: 'Webhooks firmados', purpose: 'Eventos administrativos', scope: 'Metadatos permitidos', state: 'Conectada', checked: '09:18' },
  ]);

  protected readonly auditActor = signal('');
  protected readonly auditPatient = signal('');
  protected readonly auditResource = signal('');
  protected readonly auditTrace = signal('');
  protected readonly auditOutcome = signal<Outcome>('Todos');

  protected readonly auditEvents: readonly AuditEvent[] = [
    { id: 'EVT-0091', time: '09:26:14', actor: 'lara.vega', actorDetail: 'Administración clínica', action: 'Actualizó el alcance de un rol', patient: 'DEMO-P-0284', resource: 'ROLE-REC', traceId: 'trc-83fa91', outcome: 'Permitido', reason: 'Reautenticación y doble aprobación válidas' },
    { id: 'EVT-0088', time: '09:22:03', actor: 'soporte.demo', actorDetail: 'Soporte técnico', action: 'Intentó abrir una ficha clínica', patient: 'DEMO-P-0412', resource: 'CLINICAL-RECORD', traceId: 'trc-77bc04', outcome: 'Denegado', reason: 'Sin relación asistencial ni permiso clínico' },
    { id: 'EVT-0082', time: '09:18:51', actor: 'irene.abril', actorDetail: 'Facturación', action: 'Consultó estado administrativo', patient: 'DEMO-P-0197', resource: 'INVOICE-2026-0182', traceId: 'trc-61df28', outcome: 'Permitido', reason: 'Campos clínicos excluidos por política' },
    { id: 'EVT-0079', time: '09:14:20', actor: 'nicolas.ortega', actorDetail: 'Recepción', action: 'Solicitó exportación de agenda', patient: 'DEMO-P-0284', resource: 'AGENDA-CENTRO', traceId: 'trc-4ab902', outcome: 'Desafío', reason: 'Reautenticación reforzada requerida' },
    { id: 'EVT-0074', time: '09:08:44', actor: 'sistema.cola', actorDetail: 'Servicio interno', action: 'Entregó recordatorio administrativo', patient: 'DEMO-P-0550', resource: 'QUEUE-NOTIFICATIONS', traceId: 'trc-198ed0', outcome: 'Permitido', reason: 'Consentimiento y canal autorizados' },
  ];

  protected readonly filteredAuditEvents = computed(() => {
    const actor = this.auditActor().trim().toLocaleLowerCase('es');
    const patient = this.auditPatient().trim().toLocaleLowerCase('es');
    const resource = this.auditResource().trim().toLocaleLowerCase('es');
    const trace = this.auditTrace().trim().toLocaleLowerCase('es');
    const outcome = this.auditOutcome();
    return this.auditEvents.filter((event) =>
      (!actor || `${event.actor} ${event.actorDetail}`.toLocaleLowerCase('es').includes(actor)) &&
      (!patient || event.patient.toLocaleLowerCase('es').includes(patient)) &&
      (!resource || event.resource.toLocaleLowerCase('es').includes(resource)) &&
      (!trace || event.traceId.toLocaleLowerCase('es').includes(trace)) &&
      (outcome === 'Todos' || event.outcome === outcome),
    );
  });

  protected selectSection(section: SectionId): void {
    this.activeSection.set(section);
    const label = this.sections.find((item) => item.id === section)?.label ?? 'Administración';
    this.liveMessage.set(`Sección ${label} abierta.`);
  }

  protected togglePrivacyContext(): void {
    this.showContext.update((visible) => !visible);
    this.liveMessage.set(this.showContext() ? 'Contexto sintético visible.' : 'Referencias de paciente enmascaradas.');
  }

  protected toggleUser(userId: string): void {
    this.users.update((users) => users.map((user) => user.id === userId ? { ...user, state: user.state === 'Activa' ? 'Suspendida' : 'Activa' } : user));
    const user = this.users().find((item) => item.id === userId);
    this.liveMessage.set(`Cuenta ${userId}: estado local cambiado a ${user?.state.toLocaleLowerCase('es')}.`);
  }

  protected createDemoInvitation(): void {
    if (this.invitations().some((invitation) => invitation.id === 'INV-DEMO')) {
      this.liveMessage.set('La invitación de demostración ya está en la lista.');
      return;
    }
    this.invitations.update((invitations) => [{ id: 'INV-DEMO', recipient: 'n••••@demo.nexasalud.test', role: 'Rol por confirmar', scope: 'Sin acceso hasta aprobar', expires: '72 h', state: 'Pendiente' }, ...invitations]);
    this.liveMessage.set('Invitación sintética creada localmente; no se ha enviado ningún mensaje.');
  }

  protected removeInvitation(invitationId: string): void {
    this.invitations.update((invitations) => invitations.filter((invitation) => invitation.id !== invitationId));
    this.liveMessage.set(`Invitación ${invitationId} retirada de la vista local.`);
  }

  protected togglePermission(roleId: string, permission: PermissionKey): void {
    this.roles.update((roles) => roles.map((role) => role.id === roleId ? { ...role, permissions: { ...role.permissions, [permission]: !role.permissions[permission] } } : role));
    this.liveMessage.set(`Permiso ${permission} actualizado en borrador para ${roleId}; requiere aprobación antes de aplicarse.`);
  }

  protected revokeSession(sessionId: string): void {
    this.sessions.update((sessions) => sessions.filter((session) => session.id !== sessionId));
    this.liveMessage.set(`Sesión ${sessionId} revocada únicamente en esta simulación.`);
  }

  protected toggleResource(resourceId: string): void {
    this.resources.update((resources) => resources.map((resource) => resource.id === resourceId ? { ...resource, state: resource.state === 'Disponible' ? 'Mantenimiento' : 'Disponible' } : resource));
    this.liveMessage.set(`Disponibilidad local de ${resourceId} actualizada.`);
  }

  protected testIntegration(integrationId: string): void {
    this.integrations.update((integrations) => integrations.map((integration) => integration.id === integrationId ? { ...integration, checked: 'ahora' } : integration));
    this.liveMessage.set(`Prueba local de ${integrationId} completada; no se enviaron datos.`);
  }

  protected announce(message: string): void {
    this.liveMessage.set(message);
  }

  protected maskedPatient(patient: string): string {
    return this.showContext() ? patient : `P-••${patient.slice(-2)}`;
  }

  protected resetAuditFilters(): void {
    this.auditActor.set('');
    this.auditPatient.set('');
    this.auditResource.set('');
    this.auditTrace.set('');
    this.auditOutcome.set('Todos');
    this.liveMessage.set('Filtros de auditoría restablecidos.');
  }
}
