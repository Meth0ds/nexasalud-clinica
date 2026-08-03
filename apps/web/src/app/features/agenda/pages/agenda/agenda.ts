import { DOCUMENT } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  OnInit,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatCardModule } from '@angular/material/card';
import { MatDialog } from '@angular/material/dialog';
import { MatMenuModule } from '@angular/material/menu';
import { MatTooltipModule } from '@angular/material/tooltip';
import { finalize } from 'rxjs';

import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { AppointmentsService } from '../../../../core/services/appointments';
import { AppError, toAppError } from '../../../../core/errors/app-error';
import { PatientsService } from '../../../../core/services/patients';
import { ReferenceDataService } from '../../../../core/services/reference-data';
import { SessionService } from '../../../../core/services/session';
import { Appointment, Patient, ReferenceData } from '../../../../core/models/app.models';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { StatePanel } from '../../../../shared/components/state-panel/state-panel';
import { StatusChip } from '../../../../shared/components/status-chip/status-chip';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import {
  AppointmentDialog,
  AppointmentDialogData,
  AppointmentDialogResult,
} from '../../components/appointment-dialog/appointment-dialog';
import {
  AppointmentActionDialog,
  AppointmentActionDialogData,
  AppointmentActionDialogResult,
} from '../../components/appointment-action-dialog/appointment-action-dialog';

interface AgendaItem {
  readonly id: string;
  readonly version: number;
  readonly patientId: string;
  readonly practitionerId: string;
  readonly roomId?: string;
  readonly appointmentTypeId?: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly patient: string;
  readonly initials: string;
  readonly practitioner: string;
  readonly practitionerIndex: number;
  readonly start: string;
  readonly duration: number;
  readonly type: string;
  readonly room: string;
  readonly status: Appointment['status'];
  readonly statusLabel: string;
  readonly tone: 'green' | 'blue' | 'coral' | 'sand';
  readonly mode: 'in-person' | 'video' | 'phone';
  readonly alerts: readonly string[];
}

interface TransitionAction {
  readonly status: Exclude<Appointment['status'], 'pending'>;
  readonly label: string;
}

@Component({
  selector: 'app-agenda',
  imports: [
    MatButtonModule,
    MatButtonToggleModule,
    MatCardModule,
    MatMenuModule,
    MatTooltipModule,
    AppIcon,
    StatePanel,
    StatusChip,
    ScrollReveal,
  ],
  templateUrl: './agenda.html',
  styleUrls: [
    './agenda.scss',
    './agenda-calendar.scss',
    './agenda-events.scss',
    './agenda-inspector.scss',
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Agenda implements OnInit {
  private readonly dialog = inject(MatDialog);
  private readonly notifications = inject(ErrorNotificationService);
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);
  private readonly appointmentApi = inject(AppointmentsService);
  private readonly patientApi = inject(PatientsService);
  private readonly referenceApi = inject(ReferenceDataService);
  private readonly session = inject(SessionService);

  protected readonly view = signal<'day' | 'week' | 'month'>('day');
  protected readonly selectedDate = signal(this.startOfDay(new Date()));
  protected readonly selectedId = signal<string | null>(null);
  protected readonly appointments = signal<readonly AgendaItem[]>([]);
  protected readonly loading = signal(false);
  protected readonly agendaError = signal<AppError | null>(null);
  protected readonly timezone = signal('Europe/Madrid');
  protected readonly pdfId = signal<string | null>(null);
  protected readonly practitioners = signal<
    readonly { id: string; name: string; role: string; initials: string; tone: string }[]
  >([]);
  protected readonly rooms = signal<readonly { id: string; name: string }[]>([]);
  private readonly appointmentTypes = signal<ReferenceData['appointmentTypes']>([]);
  private readonly patients = signal<readonly Patient[]>([]);
  protected readonly hours = Array.from({ length: 12 }, (_, index) => index + 8);

  ngOnInit(): void {
    this.loadData();
  }

  protected setView(value: 'day' | 'week' | 'month'): void {
    this.view.set(value);
    this.loadAppointments();
  }

  protected movePeriod(direction: -1 | 1): void {
    const date = new Date(this.selectedDate());
    const amount = this.view() === 'day' ? 1 : this.view() === 'week' ? 7 : this.daysInMonth(date);
    date.setDate(date.getDate() + direction * amount);
    this.selectedDate.set(this.startOfDay(date));
    this.loadAppointments();
  }

  protected goToToday(): void {
    this.selectedDate.set(this.startOfDay(new Date()));
    this.loadAppointments();
  }

  protected retryLoad(): void {
    this.loadAppointments();
  }

  protected headingDate(): string {
    const date = this.selectedDate();
    if (this.view() === 'day') {
      return new Intl.DateTimeFormat('es-ES', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
      }).format(date);
    }

    const days = this.visibleDays();
    const first = days[0]?.date;
    const last = days[days.length - 1]?.date;
    if (!first || !last) {
      return '';
    }
    if (this.view() === 'month') {
      return new Intl.DateTimeFormat('es-ES', { month: 'long', year: 'numeric' }).format(first);
    }
    const format = new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' });
    return `${format.format(first)} – ${format.format(last)}`;
  }

  protected periodSummary(): string {
    const count = this.appointments().length;
    const unit = count === 1 ? 'cita programada' : 'citas programadas';
    return `${count} ${unit} · ${this.timezone()}`;
  }

  protected visibleDays(): readonly {
    readonly key: string;
    readonly date: Date;
    readonly label: string;
    readonly count: number;
  }[] {
    const selected = this.selectedDate();
    const start = this.startOfDay(new Date(selected));
    const days = this.view() === 'day' ? 1 : this.view() === 'week' ? 7 : this.daysInMonth(start);
    if (this.view() === 'week') {
      const weekday = (start.getDay() + 6) % 7;
      start.setDate(start.getDate() - weekday);
    }
    if (this.view() === 'month') {
      start.setDate(1);
    }

    return Array.from({ length: days }, (_, index) => {
      const date = new Date(start);
      date.setDate(start.getDate() + index);
      const key = this.dateKey(date);
      return {
        key,
        date,
        label: new Intl.DateTimeFormat('es-ES', {
          weekday: this.view() === 'month' ? undefined : 'short',
          day: 'numeric',
        }).format(date),
        count: this.appointments().filter(
          (appointment) => this.dateKey(new Date(appointment.startsAt)) === key,
        ).length,
      };
    });
  }

  protected isToday(): boolean {
    return this.dateKey(this.selectedDate()) === this.dateKey(new Date());
  }

  protected todayKey(): string {
    return this.dateKey(new Date());
  }

  protected currentTimeLabel(): string {
    return new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' }).format(
      new Date(),
    );
  }

  protected currentTimeTop(): number {
    const now = new Date();
    return ((now.getHours() - 8) * 60 + now.getMinutes()) * 1.25;
  }

  protected appointmentDate(appointment: AgendaItem): string {
    return new Intl.DateTimeFormat('es-ES', { day: 'numeric', month: 'short' }).format(
      new Date(appointment.startsAt),
    );
  }

  protected openCreate(): void {
    if (!this.canOpenDialog()) {
      return;
    }
    this.openDialog({ mode: 'create', ...this.dialogOptions() });
  }

  protected openEdit(item: AgendaItem, mode: 'edit' | 'reschedule' = 'edit'): void {
    if (mode === 'reschedule' && !this.canReschedule(item)) {
      this.notifications.error('Esta cita ya no admite cambios de horario. Actualiza la agenda.');
      return;
    }

    this.openDialog({
      mode,
      id: item.id,
      patientId: item.patientId,
      practitionerId: item.practitionerId,
      date: item.startsAt.slice(0, 10),
      start: item.start,
      duration: item.duration,
      appointmentTypeId: item.appointmentTypeId,
      roomId: item.roomId,
      appointmentMode: item.mode,
      ...this.dialogOptions(),
    });
  }

  protected openCriticalAction(item: AgendaItem, action: 'cancel' | 'no-show'): void {
    this.dialog
      .open<AppointmentActionDialog, AppointmentActionDialogData, AppointmentActionDialogResult>(
        AppointmentActionDialog,
        {
          data: { action, patientName: item.patient },
          autoFocus: 'dialog',
          restoreFocus: true,
        },
      )
      .afterClosed()
      .subscribe((result) => {
        if (!result) {
          return;
        }

        const request =
          action === 'cancel'
            ? this.appointmentApi.cancel(item.id, item.version, result.reason)
            : this.appointmentApi.transition(item.id, item.version, 'no-show', result.reason);
        request.subscribe({
          next: (appointment) => {
            this.upsert(appointment);
            this.selectedId.set(appointment.id);
            this.notifications.success(
              action === 'cancel'
                ? 'Cita cancelada. El cambio ha quedado registrado.'
                : 'No presentación registrada. El cambio ha quedado auditado.',
            );
          },
          error: (error: unknown) =>
            this.reportMutationError(
              error,
              'No se ha podido actualizar el estado de la cita. La agenda se volverá a cargar.',
            ),
        });
      });
  }

  protected transition(item: AgendaItem, action: TransitionAction): void {
    this.appointmentApi.transition(item.id, item.version, action.status).subscribe({
      next: (appointment) => {
        this.upsert(appointment);
        this.selectedId.set(appointment.id);
        this.notifications.success(`${action.label}. El cambio ha quedado registrado.`);
      },
      error: (error: unknown) =>
        this.reportMutationError(
          error,
          'No se ha podido actualizar la cita. La agenda se volverá a cargar.',
        ),
    });
  }

  protected nextTransition(item: AgendaItem): TransitionAction | null {
    switch (item.status) {
      case 'pending':
        return { status: 'confirmed', label: 'Cita confirmada' };
      case 'confirmed':
        return { status: 'waiting', label: 'Llegada registrada' };
      case 'waiting':
        return { status: 'in-progress', label: 'Consulta iniciada' };
      case 'in-progress':
        return { status: 'completed', label: 'Consulta completada' };
      case 'completed':
      case 'cancelled':
      case 'no-show':
        return null;
    }
  }

  protected canReschedule(item: AgendaItem): boolean {
    return ['pending', 'confirmed', 'waiting', 'in-progress'].includes(item.status);
  }

  protected canCancel(item: AgendaItem): boolean {
    return ['pending', 'confirmed', 'waiting', 'in-progress'].includes(item.status);
  }

  protected canMarkNoShow(item: AgendaItem): boolean {
    return ['pending', 'confirmed'].includes(item.status);
  }

  protected downloadConfirmation(item: AgendaItem): void {
    if (this.pdfId() === item.id) {
      return;
    }

    this.pdfId.set(item.id);
    this.appointmentApi
      .downloadAppointmentConfirmation(item.id)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.pdfId.set(null)),
      )
      .subscribe({
        next: ({ blob, filename }) => {
          const url = URL.createObjectURL(blob);
          const anchor = this.document.createElement('a');
          anchor.href = url;
          anchor.download = filename;
          anchor.rel = 'noopener';
          anchor.style.display = 'none';
          this.document.body.appendChild(anchor);
          anchor.click();
          anchor.remove();
          globalThis.setTimeout(() => URL.revokeObjectURL(url), 0);
          this.notifications.success(
            'El justificante se ha generado y descargado de forma segura.',
          );
        },
        error: (error: unknown) =>
          this.reportMutationError(
            error,
            'No se ha podido descargar el justificante. Comprueba tu permiso e inténtalo de nuevo.',
          ),
      });
  }

  protected select(item: AgendaItem): void {
    this.selectedId.set(item.id);
  }

  protected selected(): AgendaItem | undefined {
    return this.appointments().find((item) => item.id === this.selectedId());
  }

  protected topFor(start: string): number {
    const [hour = 8, minutes = 0] = start.split(':').map(Number);
    return ((hour - 8) * 60 + minutes) * 1.25;
  }

  protected heightFor(duration: number): number {
    return Math.max(duration * 1.25 - 6, 45);
  }

  private openDialog(data: AppointmentDialogData): void {
    this.dialog
      .open<AppointmentDialog, AppointmentDialogData, AppointmentDialogResult>(AppointmentDialog, {
        data,
        autoFocus: 'first-tabbable',
        restoreFocus: true,
        disableClose: true,
        panelClass: 'nexa-dialog',
      })
      .afterClosed()
      .subscribe((result) => {
        if (!result) {
          return;
        }
        this.saveDialogResult(result, data.mode);
      });
  }

  private saveDialogResult(
    result: AppointmentDialogResult,
    mode: AppointmentDialogData['mode'],
  ): void {
    const startsAt = new Date(`${result.date}T${result.start}:00`);
    const endsAt = new Date(startsAt.getTime() + result.duration * 60_000);
    const current = result.id
      ? this.appointments().find((appointment) => appointment.id === result.id)
      : undefined;
    const request =
      mode === 'create'
        ? this.appointmentApi.create({
            clinicId: this.session.tenant()?.clinicId ?? '',
            patientId: result.patientId,
            practitionerId: result.practitionerId,
            roomId: result.roomId,
            startsAt: startsAt.toISOString(),
            endsAt: endsAt.toISOString(),
            timezone: this.timezone(),
            appointmentTypeId: result.appointmentTypeId,
            mode: result.mode,
            reason: result.reason || undefined,
          })
        : mode === 'reschedule' && current
          ? this.appointmentApi.reschedule(current.id, {
              version: current.version,
              startsAt: startsAt.toISOString(),
              endsAt: endsAt.toISOString(),
              timezone: this.timezone(),
              roomId: result.roomId,
              practitionerId: result.practitionerId,
              reason: result.reason || 'Cambio operativo confirmado.',
            })
          : current
            ? this.appointmentApi.update(current.id, current.version, {
                mode: result.mode,
                reason: result.reason || undefined,
              })
            : null;

    if (!request) {
      this.notifications.error('La cita ha cambiado. Actualiza la agenda antes de editarla.');
      return;
    }

    request.subscribe({
      next: (appointment) => {
        this.upsert(appointment);
        this.selectedId.set(appointment.id);
        this.notifications.success(
          mode === 'reschedule'
            ? 'Cita reprogramada y cambio registrado.'
            : 'Cita guardada correctamente.',
        );
      },
      error: (error: unknown) =>
        this.reportMutationError(
          error,
          'No se ha podido guardar la cita. La agenda se volverá a cargar.',
        ),
    });
  }

  private loadData(): void {
    this.referenceApi.load().subscribe({
      next: (reference) => {
        this.practitioners.set(
          reference.practitioners.map((practitioner, index) => ({
            id: practitioner.id,
            name: practitioner.displayName,
            role: practitioner.roles[0] ?? 'Profesional sanitario',
            initials: this.initials(practitioner.displayName),
            tone: ['green', 'blue', 'coral', 'sand'][index % 4] ?? 'green',
          })),
        );
        this.rooms.set(reference.rooms.map((room) => ({ id: room.id, name: room.name })));
        this.appointmentTypes.set(reference.appointmentTypes);
        this.loadAppointments();
      },
      error: (error: unknown) => {
        this.loading.set(false);
        this.agendaError.set(
          toAppError(error, 'No se han podido cargar los recursos autorizados de la agenda.'),
        );
      },
    });
    this.patientApi.search().subscribe({
      next: (response) => this.patients.set(response.items),
      error: (error: unknown) =>
        this.notifications.notify(
          toAppError(error, 'No se ha podido cargar el directorio de pacientes para crear citas.'),
        ),
    });
  }

  private loadAppointments(): void {
    const range = this.visibleDays();
    const firstDate = range[0]?.date;
    const lastDate = range[range.length - 1]?.date;
    if (!firstDate || !lastDate) {
      return;
    }
    const from = this.startOfDay(firstDate).toISOString();
    const toDate = this.startOfDay(lastDate);
    toDate.setDate(toDate.getDate() + 1);
    this.loading.set(true);
    this.agendaError.set(null);
    this.appointmentApi
      .list(from, toDate.toISOString())
      .pipe(finalize(() => this.loading.set(false)))
      .subscribe({
        next: (response) => {
          this.timezone.set(response.timezone);
          this.appointments.set(
            response.items.map((appointment) => this.toAgendaItem(appointment)),
          );
          this.selectedId.set(response.items[0]?.id ?? null);
        },
        error: (error: unknown) =>
          this.agendaError.set(
            toAppError(
              error,
              'No se pudo actualizar la agenda. Comprueba la conexión e inténtalo de nuevo.',
            ),
          ),
      });
  }

  private upsert(appointment: Appointment): void {
    const mapped = this.toAgendaItem(appointment);
    this.appointments.update((appointments) =>
      appointments.some((candidate) => candidate.id === mapped.id)
        ? appointments.map((candidate) => (candidate.id === mapped.id ? mapped : candidate))
        : [...appointments, mapped].sort((left, right) =>
            left.startsAt.localeCompare(right.startsAt),
          ),
    );
  }

  private reportMutationError(error: unknown, fallbackMessage: string): void {
    const appError = toAppError(error, fallbackMessage);
    this.notifications.notify(appError);
    this.loadAppointments();
  }

  private toAgendaItem(appointment: Appointment): AgendaItem {
    const startsAt = new Date(appointment.startsAt);
    const endsAt = new Date(appointment.endsAt);
    const practitionerIndex = Math.max(
      0,
      this.practitioners().findIndex((item) => item.id === appointment.practitionerId),
    );
    return {
      id: appointment.id,
      version: appointment.version,
      patientId: appointment.patientId,
      practitionerId: appointment.practitionerId,
      roomId: appointment.roomId,
      appointmentTypeId: appointment.appointmentTypeId,
      startsAt: appointment.startsAt,
      endsAt: appointment.endsAt,
      patient: appointment.patientDisplayName,
      initials: appointment.patientInitials,
      practitioner: appointment.practitionerName,
      practitionerIndex,
      start: startsAt.toLocaleTimeString('es-ES', {
        timeZone: appointment.timezone,
        hour: '2-digit',
        minute: '2-digit',
      }),
      duration: Math.round((endsAt.getTime() - startsAt.getTime()) / 60_000),
      type: appointment.typeName,
      room: appointment.roomName ?? (appointment.mode === 'video' ? 'Videoconsulta' : 'Sin sala'),
      status: appointment.status,
      statusLabel: this.statusLabel(appointment.status),
      tone:
        appointment.mode === 'video'
          ? 'blue'
          : (['green', 'blue', 'coral', 'sand'][practitionerIndex % 4] as AgendaItem['tone']),
      mode: appointment.mode,
      alerts: [],
    };
  }

  private dialogOptions(): Pick<
    AppointmentDialogData,
    'patients' | 'practitioners' | 'appointmentTypes' | 'rooms'
  > {
    return {
      patients: this.patients().map((patient) => ({ id: patient.id, label: patient.displayName })),
      practitioners: this.practitioners().map((practitioner) => ({
        id: practitioner.id,
        label: practitioner.name,
      })),
      appointmentTypes: this.appointmentTypes().map((type) => ({
        id: type.id,
        label: type.name,
        durationMinutes: type.durationMinutes,
      })),
      rooms: this.rooms().map((room) => ({ id: room.id, label: room.name })),
    };
  }

  private canOpenDialog(): boolean {
    if (
      !this.patients().length ||
      !this.practitioners().length ||
      !this.appointmentTypes().length ||
      !this.rooms().length
    ) {
      this.notifications.error(
        'Aún se están cargando pacientes, profesionales y salas. Inténtalo de nuevo.',
      );
      return false;
    }
    return true;
  }

  private initials(name: string): string {
    return name
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('');
  }

  private statusLabel(status: Appointment['status']): string {
    return (
      {
        pending: 'Por confirmar',
        confirmed: 'Confirmada',
        waiting: 'En espera',
        'in-progress': 'En curso',
        completed: 'Completada',
        cancelled: 'Cancelada',
        'no-show': 'No presentada',
      } as const
    )[status];
  }

  private startOfDay(date: Date): Date {
    const value = new Date(date);
    value.setHours(0, 0, 0, 0);
    return value;
  }

  private daysInMonth(date: Date): number {
    return new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate();
  }

  private dateKey(date: Date): string {
    return `${date.getFullYear()}-${(date.getMonth() + 1).toString().padStart(2, '0')}-${date.getDate().toString().padStart(2, '0')}`;
  }
}
