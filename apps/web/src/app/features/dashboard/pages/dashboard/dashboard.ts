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
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { RouterLink } from '@angular/router';
import { finalize, interval } from 'rxjs';

import { AppointmentStatus } from '../../../../core/models/app.models';
import { AppError, toAppError } from '../../../../core/errors/app-error';
import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { AppIcon, AppIconName } from '../../../../shared/components/app-icon/app-icon';
import { SecurityBanner } from '../../../../shared/components/security-banner/security-banner';
import { StatusChip } from '../../../../shared/components/status-chip/status-chip';
import { StatePanel } from '../../../../shared/components/state-panel/state-panel';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import {
  DashboardApiService,
  DashboardAppointment,
  DashboardData,
  DashboardUpcoming,
} from '../../data-access/dashboard-api';

interface Metric {
  readonly id: 'citas' | 'espera' | 'ocupacion' | 'asuntos';
  readonly label: string;
  readonly value: string;
  readonly note: string;
  readonly icon: AppIconName;
  readonly tone: 'green' | 'coral' | 'blue' | 'sand';
  readonly route: string;
}

interface StatusSegment {
  readonly key: 'active' | 'completed' | 'cancelled';
  readonly label: string;
  readonly count: number;
  readonly percent: number;
}

/** Radius of the occupancy gauge in viewBox units. */
const GAUGE_RADIUS = 45;
const GAUGE_CIRCUMFERENCE = 2 * Math.PI * GAUGE_RADIUS;
/** Grace applied when comparing appointment times against the live clock (minutes). */
const TIME_GRACE_MINUTES = 5;

/** Hour (0–23) of an ISO date rendered in the given IANA timezone. */
function hourInZone(value: string, timezone: string): number | null {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return null;
  }
  try {
    const parts = new Intl.DateTimeFormat('en-GB', {
      hour: '2-digit',
      hour12: false,
      timeZone: timezone,
    }).formatToParts(date);
    const hour = parts.find((part) => part.type === 'hour')?.value;
    const parsed = hour === undefined ? NaN : Number.parseInt(hour, 10);
    return Number.isNaN(parsed) ? null : parsed % 24;
  } catch {
    return null;
  }
}

/**
 * Whether an appointment is actually in progress at the given instant.
 *
 * A row is only considered in progress when it carries an explicit `in-progress`
 * status AND its window overlaps the live clock: it must have started (with a small
 * grace for clock skew) and must not have finished long ago. Future `waiting` rows
 * and rows without valid dates are never reported as in progress.
 */
export function isInProgressAppointment(
  appointment: DashboardAppointment,
  now: Date,
): boolean {
  if (appointment.status !== 'in-progress') {
    return false;
  }
  const nowMs = now.getTime();
  if (!Number.isFinite(nowMs)) {
    return false;
  }
  const start = appointment.startsAt ? new Date(appointment.startsAt).getTime() : Number.NaN;
  if (!Number.isFinite(start) || start > nowMs + TIME_GRACE_MINUTES * 60_000) {
    return false;
  }
  const end = appointment.endsAt ? new Date(appointment.endsAt).getTime() : Number.NaN;
  if (Number.isFinite(end) && end + TIME_GRACE_MINUTES * 60_000 < nowMs) {
    return false;
  }
  return true;
}

/** Whether an appointment is in the waiting state (checked in, not yet in consultation). */
export function isWaitingAppointment(appointment: DashboardAppointment): boolean {
  return appointment.status === 'waiting';
}

/** Number of appointments genuinely in progress at the given instant. */
export function countInProgress(appointments: readonly DashboardAppointment[], now: Date): number {
  return appointments.filter((appointment) => isInProgressAppointment(appointment, now)).length;
}

/** Number of appointments waiting (checked in) regardless of their start time. */
export function countWaiting(appointments: readonly DashboardAppointment[]): number {
  return appointments.filter(isWaitingAppointment).length;
}

@Component({
  selector: 'app-dashboard',
  imports: [
    MatButtonModule,
    MatCardModule,
    MatChipsModule,
    MatProgressSpinnerModule,
    RouterLink,
    AppIcon,
    SecurityBanner,
    StatePanel,
    StatusChip,
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
  protected readonly clock = signal(new Date());
  protected readonly metrics = computed<readonly Metric[]>(() => {
    const data = this.dashboard();
    if (!data) {
      return [];
    }
    const concerns = data.metrics.waitingList + data.metrics.cancelledOrNoShow;
    const total = data.metrics.appointments;
    const reserved = total - data.metrics.cancelledOrNoShow;
    return [
      {
        id: 'citas',
        label: 'Citas de hoy',
        value: String(total),
        note:
          total === 0
            ? 'Jornada sin programar'
            : `${total} total · ${data.metrics.completed} completadas`,
        icon: 'calendar',
        tone: 'green',
        route: '/agenda',
      },
      {
        id: 'espera',
        label: 'Lista de espera',
        value: String(data.metrics.waitingList),
        note:
          data.metrics.waitingList === 0 ? 'Sin solicitudes pendientes' : 'Requieren intervención',
        icon: 'waitlist',
        tone: 'coral',
        route: '/lista-espera',
      },
      {
        id: 'ocupacion',
        label: 'Capacidad reservada',
        value: `${data.metrics.occupancyPercent}%`,
        note:
          total === 0
            ? 'Agenda disponible'
            : `${reserved} de ${total} huecos reservados`,
        icon: 'gauge',
        tone: 'blue',
        route: '/agenda',
      },
      {
        id: 'asuntos',
        label: 'Asuntos operativos',
        value: String(concerns),
        note: concerns === 0 ? 'Todo en orden' : 'Seguimiento recomendado',
        icon: 'clock',
        tone: 'sand',
        route: '/lista-espera',
      },
    ];
  });
  protected readonly appointments = computed(() => this.dashboard()?.dayAppointments ?? []);
  protected readonly selected = computed(
    () =>
      this.appointments().find((appointment) => appointment.id === this.selectedAppointment()) ??
      null,
  );
  protected readonly expandedAppointment = computed(() => this.selectedAppointment() !== null);

  /** Next appointment to be attended (first non-finished, chronologically ordered). */
  protected readonly nextAppointment = computed<DashboardAppointment | null>(() => {
    const pending = this.appointments()
      .filter(
        (appointment) =>
          appointment.status !== 'completed' &&
          appointment.status !== 'cancelled' &&
          appointment.status !== 'no-show',
      )
      .sort((a, b) => (a.startsAt ?? '').localeCompare(b.startsAt ?? ''));
    return pending[0] ?? null;
  });

  /**
   * Summary of today's appointments derived only from the real day data:
   * totals, completed, pending and afternoon volume (from 13:00 in centre time).
   */
  protected readonly daySummary = computed(() => {
    const timezone = this.dashboard()?.timezone ?? 'Europe/Madrid';
    const appointments = this.appointments();
    let afternoon = 0;
    for (const appointment of appointments) {
      if (!appointment.startsAt) {
        continue;
      }
      const hour = hourInZone(appointment.startsAt, timezone);
      if (hour !== null && hour >= 13) {
        afternoon += 1;
      }
    }
    return {
      total: appointments.length,
      completed: appointments.filter((appointment) => appointment.status === 'completed').length,
      pending: appointments.filter(
        (appointment) =>
          appointment.status !== 'completed' &&
          appointment.status !== 'cancelled' &&
          appointment.status !== 'no-show',
      ).length,
      afternoon,
    };
  });

  protected readonly statusSplit = computed(() => {
    let active = 0;
    let completed = 0;
    let cancelled = 0;
    for (const appointment of this.appointments()) {
      if (appointment.status === 'completed') {
        completed += 1;
      } else if (appointment.status === 'cancelled' || appointment.status === 'no-show') {
        cancelled += 1;
      } else {
        active += 1;
      }
    }
    return { active, completed, cancelled };
  });

  /** Segments for the stacked distribution bar with percentages of the day total. */
  protected readonly statusSegments = computed<readonly StatusSegment[]>(() => {
    const { active, completed, cancelled } = this.statusSplit();
    const total = active + completed + cancelled;
    const percent = (count: number): number =>
      total === 0 ? 0 : Math.round((count / total) * 100);
    return [
      { key: 'active', label: 'Pendientes o en curso', count: active, percent: percent(active) },
      { key: 'completed', label: 'Completadas', count: completed, percent: percent(completed) },
      {
        key: 'cancelled',
        label: 'Canceladas o ausencias',
        count: cancelled,
        percent: percent(cancelled),
      },
    ];
  });

  /** Appointments genuinely in progress right now (started and within window). */
  protected readonly inProgressCount = computed(() =>
    countInProgress(this.appointments(), this.clock()),
  );

  /** Appointments checked in and waiting to be attended. */
  protected readonly waitingCount = computed(() => countWaiting(this.appointments()));

  /** Exact label for the live attendance stat, never "in progress" for future rows. */
  protected readonly liveLabel = computed(() =>
    this.inProgressCount() > 0 ? 'En curso ahora' : 'En espera',
  );

  /** Value paired with {@link liveLabel}: in-progress when any, otherwise waiting. */
  protected readonly liveValue = computed(() =>
    this.inProgressCount() > 0 ? this.inProgressCount() : this.waitingCount(),
  );

  /** Next appointment exposed by the API (adds the professional name) or the day fallback. */
  protected readonly upcoming = computed<DashboardUpcoming | null>(() => {
    const fromApi = this.dashboard()?.nextAppointments?.[0];
    if (fromApi) {
      return fromApi;
    }
    const fallback = this.nextAppointment();
    if (!fallback) {
      return null;
    }
    return {
      id: fallback.id,
      startsAt: fallback.startsAt,
      patientInitials: fallback.patientInitials,
      practitionerName: null,
      roomName: fallback.roomName,
      typeName: fallback.typeName,
      status: fallback.status,
    };
  });

  /**
   * Display name of the upcoming patient, joined from the day rows when the same
   * appointment is present there; the API upcoming payload only carries initials.
   */
  protected readonly nextPatientName = computed(() => {
    const upcoming = this.upcoming();
    if (!upcoming) {
      return null;
    }
    return this.appointments().find((appointment) => appointment.id === upcoming.id)
      ?.patientDisplayName ?? null;
  });

  /** Human countdown to the next appointment, refreshed by the live clock. */
  protected readonly nextCountdown = computed(() => {
    const next = this.upcoming();
    if (!next?.startsAt) {
      return null;
    }
    const start = new Date(next.startsAt).getTime();
    if (!Number.isFinite(start)) {
      return null;
    }
    const diff = start - this.clock().getTime();
    const minutes = Math.floor(Math.abs(diff) / 60_000);
    if (diff < 0) {
      return minutes < 1 ? 'comenzando ahora' : `comenzó hace ${this.formatMinutes(minutes)}`;
    }
    if (minutes < 1) {
      return 'en menos de 1 min';
    }
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return hours > 0
      ? `en ${hours} h ${String(rest).padStart(2, '0')} min`
      : `en ${minutes} min`;
  });

  /** Share of the day already completed, 0–100. */
  protected readonly dayProgress = computed(() => {
    const data = this.dashboard();
    if (!data || data.metrics.appointments === 0) {
      return 0;
    }
    return Math.round((data.metrics.completed / data.metrics.appointments) * 100);
  });

  /** Remaining appointments to be attended today. */
  protected readonly remainingCount = computed(() => {
    const data = this.dashboard();
    if (!data) {
      return 0;
    }
    return Math.max(data.metrics.appointments - data.metrics.completed, 0);
  });

  /** SVG stroke-dashoffset for the occupancy gauge (0 = full circle). */
  protected readonly gaugeOffset = computed(() => {
    const data = this.dashboard();
    const percent = Math.min(Math.max(data?.metrics.occupancyPercent ?? 0, 0), 100);
    return String(GAUGE_CIRCUMFERENCE * (1 - percent / 100));
  });

  /** Width (%) of the duration bar relative to the longest appointment of the day. */
  protected durationWidth(appointment: DashboardAppointment): number {
    if (!appointment.startsAt || !appointment.endsAt) {
      return 0;
    }
    const start = new Date(appointment.startsAt).getTime();
    const end = new Date(appointment.endsAt).getTime();
    if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) {
      return 0;
    }
    const longest = Math.max(
      1,
      ...this.appointments().map((candidate) => {
        if (!candidate.startsAt || !candidate.endsAt) {
          return 0;
        }
        return new Date(candidate.endsAt).getTime() - new Date(candidate.startsAt).getTime();
      }),
    );
    return Math.max(Math.round(((end - start) / longest) * 100), 4);
  }

  protected readonly liveTime = computed(() => {
    const timezone = this.dashboard()?.timezone ?? 'Europe/Madrid';
    try {
      return new Intl.DateTimeFormat('es-ES', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZone: timezone,
      }).format(this.clock());
    } catch {
      return new Intl.DateTimeFormat('es-ES', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
      }).format(this.clock());
    }
  });

  constructor() {
    this.fetch(false);
    interval(1000)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.clock.set(new Date()));
  }

  /** User-triggered reload; ignores concurrent requests while one is in flight. */
  protected load(announce = false): void {
    if (this.loading()) {
      return;
    }
    this.fetch(announce);
  }

  private fetch(announce: boolean): void {
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
          if (announce) {
            this.notifications.success(
              'Resumen operativo actualizado con los datos del centro activo.',
            );
          }
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

  protected time(value: string | null, timezone: string): string {
    if (!value) {
      return '--:--';
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return '--:--';
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

  /** Formats a minute count as a compact duration (e.g. 1 h 05, 42 min). */
  private formatMinutes(minutes: number): string {
    if (minutes < 1) {
      return '< 1 min';
    }
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return hours > 0
      ? `${hours} h ${String(rest).padStart(2, '0')} min`
      : `${minutes} min`;
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

  protected stackWidth(count: number): number {
    const total =
      this.statusSplit().active + this.statusSplit().completed + this.statusSplit().cancelled;
    if (total === 0 || count === 0) {
      return 0;
    }
    return Math.max((count / total) * 100, 5);
  }
}
