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
import { MatChipsModule } from '@angular/material/chips';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { RouterLink } from '@angular/router';
import { finalize } from 'rxjs';

import { AppointmentStatus } from '../../../../core/models/app.models';
import { AppError, toAppError } from '../../../../core/errors/app-error';
import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { AppIcon, AppIconName } from '../../../../shared/components/app-icon/app-icon';
import { SecurityBanner } from '../../../../shared/components/security-banner/security-banner';
import { StatePanel } from '../../../../shared/components/state-panel/state-panel';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import {
  DashboardApiService,
  DashboardAppointment,
  DashboardData,
} from '../../data-access/dashboard-api';

interface Metric {
  readonly label: string;
  readonly value: string;
  readonly note: string;
  readonly icon: AppIconName;
  readonly tone: 'green' | 'coral' | 'blue' | 'sand';
}

@Component({
  selector: 'app-dashboard',
  imports: [
    MatButtonModule,
    MatCardModule,
    MatChipsModule,
    MatProgressBarModule,
    MatProgressSpinnerModule,
    RouterLink,
    AppIcon,
    SecurityBanner,
    StatePanel,
    ScrollReveal,
  ],
  templateUrl: './dashboard.html',
  styleUrls: ['./dashboard.scss', './dashboard-agenda.scss', './dashboard-panels.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Dashboard {
  private readonly dashboardApi = inject(DashboardApiService);
  private readonly notifications = inject(ErrorNotificationService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly loading = signal(true);
  protected readonly errorMessage = signal<AppError | null>(null);
  protected readonly dashboard = signal<DashboardData | null>(null);
  protected readonly selectedAppointment = signal<string | null>(null);
  protected readonly metrics = computed<readonly Metric[]>(() => {
    const data = this.dashboard();
    if (!data) {
      return [];
    }
    return [
      {
        label: 'Citas de hoy',
        value: String(data.metrics.appointments),
        note: `${data.metrics.completed} completadas`,
        icon: 'calendar',
        tone: 'green',
      },
      {
        label: 'En espera',
        value: String(data.metrics.waitingList),
        note: 'Solicitudes activas',
        icon: 'waitlist',
        tone: 'coral',
      },
      {
        label: 'Ocupación',
        value: `${data.metrics.occupancyPercent}%`,
        note: 'Agenda del centro',
        icon: 'chart',
        tone: 'blue',
      },
      {
        label: 'Citas activas',
        value: String(data.metrics.active),
        note: 'Pendientes o en curso',
        icon: 'clock',
        tone: 'sand',
      },
    ];
  });
  protected readonly appointments = computed(() => this.dashboard()?.dayAppointments ?? []);
  protected readonly selected = computed(
    () =>
      this.appointments().find((appointment) => appointment.id === this.selectedAppointment()) ??
      null,
  );

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.errorMessage.set(null);
    this.dashboardApi
      .load()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: (data) => {
          this.dashboard.set(data);
          this.selectedAppointment.set(data.dayAppointments[0]?.id ?? null);
        },
        error: (error: unknown) =>
          this.errorMessage.set(
            toAppError(error, 'No se ha podido cargar el resumen operativo del centro activo.'),
          ),
      });
  }

  protected selectAppointment(id: string): void {
    this.selectedAppointment.set(this.selectedAppointment() === id ? null : id);
  }

  protected statusLabel(status: AppointmentStatus): string {
    return {
      pending: 'Pendiente',
      confirmed: 'Confirmada',
      waiting: 'En espera',
      'in-progress': 'En curso',
      completed: 'Completada',
      cancelled: 'Cancelada',
      'no-show': 'No asistió',
    }[status];
  }

  protected statusTone(status: AppointmentStatus): 'info' | 'success' | 'warning' | 'danger' {
    switch (status) {
      case 'confirmed':
      case 'completed':
        return 'success';
      case 'waiting':
      case 'pending':
        return 'warning';
      case 'cancelled':
      case 'no-show':
        return 'danger';
      default:
        return 'info';
    }
  }

  protected time(value: string | null): string {
    if (!value) {
      return '--:--';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime())
      ? '--:--'
      : new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' }).format(date);
  }

  protected duration(appointment: DashboardAppointment): string {
    if (!appointment.startsAt || !appointment.endsAt) {
      return 'Duración no disponible';
    }
    const minutes = Math.round(
      (new Date(appointment.endsAt).getTime() - new Date(appointment.startsAt).getTime()) / 60_000,
    );
    return Number.isFinite(minutes) && minutes > 0 ? `${minutes} min` : 'Duración no disponible';
  }

  protected dateLabel(value: string): string {
    const date = new Date(`${value}T12:00:00`);
    return Number.isNaN(date.getTime())
      ? 'Resumen del día'
      : new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long' }).format(
          date,
        );
  }

  protected updateTime(value: string, timezone: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
      return 'recientemente';
    }

    try {
      return new Intl.DateTimeFormat('es-ES', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: timezone,
      }).format(date);
    } catch {
      return new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' }).format(date);
    }
  }

  protected notifyDataRefresh(): void {
    this.notifications.success('Resumen operativo actualizado con los datos del centro activo.');
    this.load();
  }
}
