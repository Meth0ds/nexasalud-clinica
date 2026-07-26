import { DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, OnInit, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatDialog } from '@angular/material/dialog';
import { MatMenuModule } from '@angular/material/menu';
import { MatTooltipModule } from '@angular/material/tooltip';

import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { AppointmentsService } from '../../../../core/services/appointments';
import { PatientsService } from '../../../../core/services/patients';
import { ReferenceDataService } from '../../../../core/services/reference-data';
import { SessionService } from '../../../../core/services/session';
import { Appointment, Patient, ReferenceData } from '../../../../core/models/app.models';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import {
  AppointmentDialog,
  AppointmentDialogData,
  AppointmentDialogResult,
} from '../../components/appointment-dialog/appointment-dialog';

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
  readonly status: string;
  readonly tone: 'green' | 'blue' | 'coral' | 'sand';
  readonly mode: 'in-person' | 'video' | 'phone';
  readonly alerts: readonly string[];
}

@Component({
  selector: 'app-agenda',
  imports: [
    MatButtonModule,
    MatButtonToggleModule,
    MatMenuModule,
    MatTooltipModule,
    AppIcon,
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
  private readonly appointmentApi = inject(AppointmentsService);
  private readonly patientApi = inject(PatientsService);
  private readonly referenceApi = inject(ReferenceDataService);
  private readonly session = inject(SessionService);

  protected readonly view = signal<'day' | 'week' | 'month'>('day');
  protected readonly selectedId = signal<string | null>(null);
  protected readonly appointments = signal<readonly AgendaItem[]>([]);
  protected readonly practitioners = signal<readonly { id: string; name: string; role: string; initials: string; tone: string }[]>([]);
  protected readonly rooms = signal<readonly { id: string; name: string }[]>([]);
  private readonly appointmentTypes = signal<ReferenceData['appointmentTypes']>([]);
  private readonly patients = signal<readonly Patient[]>([]);
  protected readonly hours = Array.from({ length: 12 }, (_, index) => index + 8);

  ngOnInit(): void {
    this.loadData();
  }

  protected setView(value: 'day' | 'week' | 'month'): void {
    this.view.set(value);
  }

  protected openCreate(): void {
    if (!this.canOpenDialog()) {
      return;
    }
    this.openDialog({ mode: 'create', ...this.dialogOptions() });
  }

  protected openEdit(item: AgendaItem, mode: 'edit' | 'reschedule' = 'edit'): void {
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

  protected cancel(item: AgendaItem): void {
    this.appointmentApi.cancel(item.id, item.version, 'Cancelación confirmada desde la agenda.').subscribe({
      next: (appointment) => {
        this.upsert(appointment);
        this.selectedId.set(appointment.id);
        this.notifications.success('Cita cancelada. El cambio ha quedado registrado.');
      },
    });
  }

  protected downloadConfirmation(item: AgendaItem): void {
    this.document.defaultView?.open(
      `/api/v1/appointments/${encodeURIComponent(item.id)}/documents/confirmation.pdf`,
      '_blank',
      'noopener,noreferrer',
    );
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

  private saveDialogResult(result: AppointmentDialogResult, mode: AppointmentDialogData['mode']): void {
    const startsAt = new Date(`${result.date}T${result.start}:00`);
    const endsAt = new Date(startsAt.getTime() + result.duration * 60_000);
    const current = result.id
      ? this.appointments().find((appointment) => appointment.id === result.id)
      : undefined;
    const request = mode === 'create'
      ? this.appointmentApi.create({
          clinicId: this.session.tenant()?.clinicId ?? '',
          patientId: result.patientId,
          practitionerId: result.practitionerId,
          roomId: result.roomId,
          startsAt: startsAt.toISOString(),
          endsAt: endsAt.toISOString(),
          timezone: 'Europe/Madrid',
          appointmentTypeId: result.appointmentTypeId,
          mode: result.mode,
          reason: result.reason || undefined,
        })
      : mode === 'reschedule' && current
        ? this.appointmentApi.reschedule(current.id, {
            version: current.version,
            startsAt: startsAt.toISOString(),
            endsAt: endsAt.toISOString(),
            timezone: 'Europe/Madrid',
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
    });
  }

  private loadData(): void {
    this.referenceApi.load().subscribe({
      next: (reference) => {
        this.practitioners.set(reference.practitioners.map((practitioner, index) => ({
          id: practitioner.id,
          name: practitioner.displayName,
          role: practitioner.roles[0] ?? 'Profesional sanitario',
          initials: this.initials(practitioner.displayName),
          tone: ['green', 'blue', 'coral', 'sand'][index % 4] ?? 'green',
        })));
        this.rooms.set(reference.rooms.map((room) => ({ id: room.id, name: room.name })));
        this.appointmentTypes.set(reference.appointmentTypes);
        this.loadAppointments();
      },
    });
    this.patientApi.search().subscribe({ next: (response) => this.patients.set(response.items) });
  }

  private loadAppointments(): void {
    const date = new Date();
    date.setHours(0, 0, 0, 0);
    const from = date.toISOString();
    const toDate = new Date(date);
    toDate.setDate(toDate.getDate() + 1);
    this.appointmentApi.list(from, toDate.toISOString()).subscribe({
      next: (response) => {
        this.appointments.set(response.items.map((appointment) => this.toAgendaItem(appointment)));
        this.selectedId.set(response.items[0]?.id ?? null);
      },
    });
  }

  private upsert(appointment: Appointment): void {
    const mapped = this.toAgendaItem(appointment);
    this.appointments.update((appointments) =>
      appointments.some((candidate) => candidate.id === mapped.id)
        ? appointments.map((candidate) => candidate.id === mapped.id ? mapped : candidate)
        : [...appointments, mapped].sort((left, right) => left.startsAt.localeCompare(right.startsAt)),
    );
  }

  private toAgendaItem(appointment: Appointment): AgendaItem {
    const startsAt = new Date(appointment.startsAt);
    const endsAt = new Date(appointment.endsAt);
    const practitionerIndex = Math.max(0, this.practitioners().findIndex((item) => item.id === appointment.practitionerId));
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
      start: startsAt.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }),
      duration: Math.round((endsAt.getTime() - startsAt.getTime()) / 60_000),
      type: appointment.typeName,
      room: appointment.roomName ?? (appointment.mode === 'video' ? 'Videoconsulta' : 'Sin sala'),
      status: this.statusLabel(appointment.status),
      tone: appointment.mode === 'video' ? 'blue' : (['green', 'blue', 'coral', 'sand'][practitionerIndex % 4] as AgendaItem['tone']),
      mode: appointment.mode,
      alerts: [],
    };
  }

  private dialogOptions(): Pick<AppointmentDialogData, 'patients' | 'practitioners' | 'appointmentTypes' | 'rooms'> {
    return {
      patients: this.patients().map((patient) => ({ id: patient.id, label: patient.displayName })),
      practitioners: this.practitioners().map((practitioner) => ({ id: practitioner.id, label: practitioner.name })),
      appointmentTypes: this.appointmentTypes().map((type) => ({ id: type.id, label: type.name, durationMinutes: type.durationMinutes })),
      rooms: this.rooms().map((room) => ({ id: room.id, label: room.name })),
    };
  }

  private canOpenDialog(): boolean {
    if (!this.patients().length || !this.practitioners().length || !this.appointmentTypes().length || !this.rooms().length) {
      this.notifications.error('Aún se están cargando pacientes, profesionales y salas. Inténtalo de nuevo.');
      return false;
    }
    return true;
  }

  private initials(name: string): string {
    return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase() ?? '').join('');
  }

  private statusLabel(status: Appointment['status']): string {
    return ({ pending: 'Por confirmar', confirmed: 'Confirmada', waiting: 'En espera', 'in-progress': 'En curso', completed: 'Completada', cancelled: 'Cancelada', 'no-show': 'No presentada' } as const)[status];
  }
}
