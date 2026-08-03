import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { forkJoin, finalize } from 'rxjs';

import { AppError, toAppError } from '../../../../core/errors/app-error';
import { ErrorNotificationService } from '../../../../core/services/error-notification';
import {
  AdministrationApiService,
  AdministrationOverview,
  AdministrationRole,
  AdministrationUser,
} from '../../data-access/administration-api';
import { AppIcon, AppIconName } from '../../../../shared/components/app-icon/app-icon';
import { StatePanel } from '../../../../shared/components/state-panel/state-panel';
import { StatusChip } from '../../../../shared/components/status-chip/status-chip';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

type SectionId = 'users' | 'roles' | 'security';
type PrivilegedAction = 'membership' | 'sessions' | null;

interface AdminSection {
  readonly id: SectionId;
  readonly label: string;
  readonly description: string;
  readonly icon: AppIconName;
}

@Component({
  selector: 'app-administration',
  imports: [
    MatButtonModule,
    MatCardModule,
    MatCheckboxModule,
    MatProgressSpinnerModule,
    AppIcon,
    StatePanel,
    StatusChip,
    ScrollReveal,
  ],
  templateUrl: './administration.html',
  styleUrls: [
    './administration.scss',
    './administration-content.scss',
    './administration-operations.scss',
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Administration {
  protected readonly sections: readonly AdminSection[] = [
    {
      id: 'users',
      label: 'Usuarios',
      description:
        'Identidades de trabajo, estado y asignación de roles dentro del contexto actual.',
      icon: 'team',
    },
    {
      id: 'roles',
      label: 'Roles y permisos',
      description: 'Matriz de privilegio mínimo efectiva para el centro seleccionado.',
      icon: 'shield',
    },
    {
      id: 'security',
      label: 'Seguridad de acceso',
      description: 'MFA obligatorio y revocación de sesiones sujeta a reautenticación reciente.',
      icon: 'lock',
    },
  ];

  private readonly administrationApi = inject(AdministrationApiService);
  private readonly notifications = inject(ErrorNotificationService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly activeSection = signal<SectionId>('users');
  protected readonly userQuery = signal('');
  protected readonly loading = signal(true);
  protected readonly refreshing = signal(false);
  protected readonly submitting = signal(false);
  protected readonly errorMessage = signal<AppError | null>(null);
  protected readonly actionError = signal<AppError | null>(null);
  protected readonly actionMessage = signal(
    'Datos protegidos: no se muestran secretos ni sesiones activas individuales.',
  );
  protected readonly overview = signal<AdministrationOverview | null>(null);
  protected readonly users = signal<readonly AdministrationUser[]>([]);
  protected readonly roles = signal<readonly AdministrationRole[]>([]);
  protected readonly selectedUserId = signal<string | null>(null);
  protected readonly draftRoleIds = signal<readonly string[]>([]);
  protected readonly requestedActiveState = signal<boolean | null>(null);
  protected readonly reason = signal('');
  protected readonly pendingAction = signal<PrivilegedAction>(null);

  protected readonly activeSectionDetails = computed(
    () => this.sections.find((section) => section.id === this.activeSection()) ?? this.sections[0],
  );
  protected readonly filteredUsers = computed(() => {
    const query = this.userQuery().trim().toLocaleLowerCase('es');
    if (!query) {
      return this.users();
    }

    return this.users().filter((user) =>
      [user.displayName, user.emailHint ?? '', ...user.roles.map((role) => role.name)].some(
        (value) => value.toLocaleLowerCase('es').includes(query),
      ),
    );
  });
  protected readonly selectedUser = computed(
    () => this.users().find((user) => user.userId === this.selectedUserId()) ?? null,
  );
  protected readonly canSubmit = computed(() => {
    const selected = this.selectedUser();
    return Boolean(
      selected && this.pendingAction() && this.reason().trim().length >= 8 && !this.submitting(),
    );
  });
  protected readonly hasMembershipChange = computed(() => {
    const selected = this.selectedUser();
    if (!selected) {
      return false;
    }

    const activeChanged =
      this.requestedActiveState() !== null && this.requestedActiveState() !== selected.active;
    const selectedRoles = new Set(selected.roles.map((role) => role.id));
    const draftedRoles = new Set(this.draftRoleIds());
    const rolesChanged =
      selectedRoles.size !== draftedRoles.size ||
      [...selectedRoles].some((roleId) => !draftedRoles.has(roleId));

    return activeChanged || rolesChanged;
  });

  constructor() {
    this.load();
  }

  protected selectSection(section: SectionId): void {
    this.activeSection.set(section);
  }

  protected refresh(): void {
    if (this.refreshing() || this.submitting()) {
      return;
    }
    this.load(true);
  }

  protected selectUser(user: AdministrationUser): void {
    this.actionError.set(null);
    this.selectedUserId.set(user.userId);
    this.draftRoleIds.set(user.roles.map((role) => role.id));
    this.requestedActiveState.set(null);
    this.reason.set('');
    this.pendingAction.set(null);
    this.actionMessage.set(`Preparado el cambio protegido de ${user.displayName}.`);
  }

  protected prepareStatusChange(user: AdministrationUser, active: boolean): void {
    this.selectUser(user);
    this.requestedActiveState.set(active);
    this.pendingAction.set('membership');
    this.actionMessage.set(
      `${active ? 'Reactivar' : 'Suspender'} requiere un motivo y una MFA completada en los últimos 15 minutos.`,
    );
  }

  protected prepareSessionRevocation(user: AdministrationUser): void {
    this.selectUser(user);
    this.pendingAction.set('sessions');
    this.actionMessage.set(
      'La revocación cierra las sesiones en el siguiente control de servidor. Requiere MFA reciente.',
    );
  }

  protected toggleDraftRole(roleId: string, checked: boolean): void {
    this.draftRoleIds.update((roleIds) => {
      const next = new Set(roleIds);
      if (checked) {
        next.add(roleId);
      } else {
        next.delete(roleId);
      }
      return [...next];
    });
    this.pendingAction.set('membership');
  }

  protected cancelAction(): void {
    this.actionError.set(null);
    const selected = this.selectedUser();
    this.reason.set('');
    this.requestedActiveState.set(null);
    this.pendingAction.set(null);
    if (selected) {
      this.draftRoleIds.set(selected.roles.map((role) => role.id));
    }
    this.actionMessage.set('Cambio protegido cancelado.');
  }

  protected submitAction(): void {
    const selected = this.selectedUser();
    const action = this.pendingAction();
    const reason = this.reason().trim();
    if (!selected || !action || reason.length < 8 || this.submitting()) {
      return;
    }

    this.submitting.set(true);
    this.errorMessage.set(null);
    this.actionError.set(null);

    if (action === 'sessions') {
      this.administrationApi
        .revokeSessions(selected.userId, reason)
        .pipe(
          takeUntilDestroyed(this.destroyRef),
          finalize(() => this.submitting.set(false)),
        )
        .subscribe({
          next: (result) => {
            this.users.update((users) =>
              users.map((user) =>
                user.userId === selected.userId
                  ? { ...user, sessionsRevokedAt: result.effectiveAt }
                  : user,
              ),
            );
            this.completeAction(`Sesiones de ${selected.displayName} revocadas por el servidor.`);
          },
          error: (error: unknown) => this.reportActionFailure(error),
        });
      return;
    }

    this.administrationApi
      .updateMembership(selected.userId, {
        ...(this.requestedActiveState() !== null
          ? { active: this.requestedActiveState() ?? undefined }
          : {}),
        roleIds: this.draftRoleIds(),
        reason,
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.submitting.set(false)),
      )
      .subscribe({
        next: (updatedUser) => {
          this.users.update((users) =>
            users.map((user) => (user.userId === updatedUser.userId ? updatedUser : user)),
          );
          this.completeAction(`Acceso de ${updatedUser.displayName} actualizado y auditado.`);
        },
        error: (error: unknown) => this.reportActionFailure(error),
      });
  }

  protected initials(name: string): string {
    return name
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part.slice(0, 1).toLocaleUpperCase('es'))
      .join('');
  }

  protected formatTimestamp(value: string | null): string {
    if (!value) {
      return 'Sin acceso registrado';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return 'Fecha no disponible';
    }

    return new Intl.DateTimeFormat('es-ES', {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(date);
  }

  protected hasRole(roleId: string): boolean {
    return this.draftRoleIds().includes(roleId);
  }

  protected permissionLabel(permission: string): string {
    const labels: Readonly<Record<string, string>> = {
      'admin.users.read': 'Consultar usuarios',
      'admin.users.write': 'Gestionar usuarios',
      'admin.security.write': 'Revocar sesiones',
      'appointments.read': 'Consultar agenda',
      'appointments.write': 'Gestionar agenda',
      'appointments.reason.read': 'Consultar motivos de cita',
      'patients.read': 'Consultar pacientes',
      'patients.write': 'Gestionar pacientes',
      'clinical_notes.read': 'Consultar notas clínicas',
      'clinical_notes.write': 'Crear notas clínicas',
      'clinical_notes.sign': 'Firmar notas clínicas',
      'prescriptions.read': 'Consultar prescripción',
      'prescriptions.write': 'Emitir prescripción',
      'billing.read': 'Consultar facturación',
      'billing.write': 'Gestionar facturación',
      'audit.read': 'Consultar auditoría',
    };
    return labels[permission] ?? permission;
  }

  private load(isRefresh = false): void {
    if (isRefresh) {
      this.refreshing.set(true);
    } else {
      this.loading.set(true);
    }
    this.errorMessage.set(null);

    forkJoin({
      overview: this.administrationApi.overview(),
      users: this.administrationApi.users(),
      roles: this.administrationApi.roles(),
    })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => {
          this.loading.set(false);
          this.refreshing.set(false);
        }),
      )
      .subscribe({
        next: ({ overview, users, roles }) => {
          this.overview.set(overview);
          this.users.set(users);
          this.roles.set(roles);
          const selected = this.selectedUserId();
          if (selected && !users.some((user) => user.userId === selected)) {
            this.cancelAction();
            this.selectedUserId.set(null);
          }
        },
        error: (error: unknown) => {
          this.errorMessage.set(
            toAppError(
              error,
              'No se pudo cargar la administración. Comprueba la sesión, el contexto y tus permisos.',
            ),
          );
        },
      });
  }

  private completeAction(message: string): void {
    this.actionError.set(null);
    this.reason.set('');
    this.requestedActiveState.set(null);
    this.pendingAction.set(null);
    this.actionMessage.set(message);
  }

  private reportActionFailure(error: unknown): void {
    const appError = toAppError(
      error,
      'El servidor no aplicó el cambio. Revisa la MFA reciente, el permiso y el motivo.',
    );
    this.actionError.set(appError);
    this.actionMessage.set(
      'El cambio protegido no se ha aplicado. Revisa la referencia y vuelve a intentarlo cuando proceda.',
    );
    this.notifications.notify(appError);
  }
}
