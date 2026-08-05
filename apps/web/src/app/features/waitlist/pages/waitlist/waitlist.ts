import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { finalize, forkJoin } from 'rxjs';

import { AppError, toAppError } from '../../../../core/errors/app-error';
import { Patient, ReferenceData } from '../../../../core/models/app.models';
import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { PatientsService } from '../../../../core/services/patients';
import { ReferenceDataService } from '../../../../core/services/reference-data';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { StatePanel } from '../../../../shared/components/state-panel/state-panel';
import { StatusChip } from '../../../../shared/components/status-chip/status-chip';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import {
  CreateWaitlistEntryInput,
  OfferSlotInput,
  WaitlistApiService,
  WaitlistEntryRecord,
  WaitlistStatus,
} from '../../data-access/waitlist-api';

@Component({
  selector: 'app-waitlist',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    MatProgressSpinnerModule,
    MatSelectModule,
    AppIcon,
    StatePanel,
    StatusChip,
    ScrollReveal,
  ],
  templateUrl: './waitlist.html',
  styleUrls: ['./waitlist.scss', './waitlist-editor.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Waitlist {
  private readonly formBuilder = inject(FormBuilder);
  private readonly waitlistApi = inject(WaitlistApiService);
  private readonly patientsApi = inject(PatientsService);
  private readonly referenceDataApi = inject(ReferenceDataService);
  private readonly notifications = inject(ErrorNotificationService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly creating = signal(false);
  protected readonly errorMessage = signal<AppError | null>(null);
  protected readonly actionError = signal<AppError | null>(null);
  protected readonly searchTerm = signal('');
  protected readonly selectedId = signal<string | null>(null);
  protected readonly offerStartsAt = signal('');
  protected readonly offerMode = signal<'in-person' | 'video' | 'phone'>('in-person');
  protected readonly offerRoomId = signal('');
  protected readonly offerPractitionerId = signal('');
  protected readonly entries = signal<readonly WaitlistEntryRecord[]>([]);
  protected readonly patients = signal<readonly Patient[]>([]);
  protected readonly referenceData = signal<ReferenceData | null>(null);
  protected readonly filteredEntries = computed(() => {
    const term = this.searchTerm().trim().toLocaleLowerCase('es-ES');
    return term
      ? this.entries().filter((entry) =>
          entry.patientDisplayName.toLocaleLowerCase('es-ES').includes(term),
        )
      : this.entries();
  });
  protected readonly selected = computed(
    () => this.entries().find((entry) => entry.id === this.selectedId()) ?? null,
  );
  protected readonly waitingCount = computed(
    () => this.entries().filter((entry) => entry.status === 'waiting').length,
  );
  protected readonly offeredCount = computed(
    () => this.entries().filter((entry) => entry.status === 'offered').length,
  );
  protected readonly clinicalPriorityCount = computed(
    () =>
      this.entries().filter((entry) => entry.status === 'waiting' && entry.priority <= 2).length,
  );
  protected readonly medianWaitingDays = computed(() =>
    median(
      this.entries()
        .filter((entry) => entry.status === 'waiting')
        .map((entry) => daysSince(entry.waitingSince)),
    ),
  );
  protected readonly createForm = this.formBuilder.nonNullable.group({
    patientId: ['', Validators.required],
    clinicId: ['', Validators.required],
    appointmentTypeId: ['', Validators.required],
    desiredFrom: ['', Validators.required],
    desiredTo: ['', Validators.required],
    priority: [3, [Validators.required, Validators.min(1), Validators.max(5)]],
    notes: ['', Validators.maxLength(500)],
  });

  constructor() {
    this.load();
  }

  protected select(entry: WaitlistEntryRecord): void {
    this.selectedId.set(entry.id);
    if (entry.status === 'waiting') {
      this.openOffer(entry);
    }
  }

  protected openOffer(entry: WaitlistEntryRecord): void {
    const referenceData = this.referenceData();
    if (!referenceData) {
      return;
    }
    const type = referenceData.appointmentTypes.find((item) => item.id === entry.appointmentTypeId);
    const clinicId = entry.clinicId || referenceData.clinics[0]?.id || '';
    const rooms = referenceData.rooms.filter((room) => room.clinicId === clinicId);
    this.offerStartsAt.set(nextBusinessHour());
    this.offerMode.set(type?.allowsVideo ? 'in-person' : 'in-person');
    this.offerRoomId.set(rooms[0]?.id ?? '');
    this.offerPractitionerId.set('');
  }

  protected offerRooms(entry: WaitlistEntryRecord): readonly {
    readonly id: string;
    readonly clinicId: string;
    readonly name: string;
    readonly capacity: number;
  }[] {
    const clinicId = entry.clinicId || this.referenceData()?.clinics[0]?.id || '';
    return (this.referenceData()?.rooms ?? []).filter((room) => room.clinicId === clinicId);
  }

  protected setSearch(value: string): void {
    this.searchTerm.set(value);
  }

  protected openCreate(): void {
    this.actionError.set(null);
    const referenceData = this.referenceData();
    if (!referenceData || this.patients().length === 0) {
      this.notifications.error(
        'No se ha podido cargar el contexto autorizado para crear la solicitud.',
      );
      return;
    }

    this.createForm.reset({
      patientId: '',
      clinicId: referenceData.clinics[0]?.id ?? '',
      appointmentTypeId: referenceData.appointmentTypes[0]?.id ?? '',
      desiredFrom: '',
      desiredTo: '',
      priority: 3,
      notes: '',
    });
    this.creating.set(true);
  }

  protected closeCreate(): void {
    if (!this.saving()) {
      this.creating.set(false);
    }
  }

  protected create(): void {
    if (this.saving() || this.createForm.invalid) {
      this.createForm.markAllAsTouched();
      return;
    }

    const value = this.createForm.getRawValue();
    const desiredFrom = asIso(value.desiredFrom);
    const desiredTo = asIso(value.desiredTo);
    if (!desiredFrom || !desiredTo || new Date(desiredTo) <= new Date(desiredFrom)) {
      this.notifications.error('Indica una ventana de atención válida y en orden cronológico.');
      return;
    }

    const input: CreateWaitlistEntryInput = {
      clinicId: value.clinicId,
      patientId: value.patientId,
      appointmentTypeId: value.appointmentTypeId,
      desiredFrom,
      desiredTo,
      priority: value.priority,
      ...(value.notes.trim() ? { notes: value.notes.trim() } : {}),
    };
    this.actionError.set(null);
    this.saving.set(true);
    this.waitlistApi
      .create(input)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        next: (entry) => {
          this.entries.update((entries) => [...entries, entry].sort(compareEntries));
          this.selectedId.set(entry.id);
          this.creating.set(false);
          this.notifications.success(
            'Solicitud incorporada a la lista de espera y auditada en el centro activo.',
          );
        },
        error: (error: unknown) =>
          this.reportActionError(
            error,
            'No se ha podido incorporar la solicitud a la lista de espera.',
          ),
      });
  }

  protected offer(entry: WaitlistEntryRecord): void {
    if (entry.status !== 'waiting' || this.saving()) {
      return;
    }

    const startsAt = this.offerStartsAt();
    if (!startsAt) {
      this.notifications.error('Indica la fecha y hora del hueco a ofrecer.');
      return;
    }
    const referenceData = this.referenceData();
    const type = referenceData?.appointmentTypes.find(
      (item) => item.id === entry.appointmentTypeId,
    );
    const duration = type?.durationMinutes ?? 30;
    const endsAt = new Date(new Date(startsAt).getTime() + duration * 60_000);
    if (type?.requiresRoom && !this.offerRoomId()) {
      this.notifications.error('Este tipo de atención requiere una sala; selecciónala.');
      return;
    }
    if (this.offerMode() === 'video' && type && !type.allowsVideo) {
      this.notifications.error('Este tipo de atención no admite videoconsulta.');
      return;
    }

    const slot: OfferSlotInput = {
      startsAt: new Date(startsAt).toISOString(),
      endsAt: endsAt.toISOString(),
      mode: this.offerMode(),
      ...(this.offerRoomId() ? { roomId: this.offerRoomId() } : {}),
      ...(this.offerPractitionerId() ? { practitionerId: this.offerPractitionerId() } : {}),
    };

    this.actionError.set(null);
    this.saving.set(true);
    this.waitlistApi
      .offer(entry, slot)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        next: (updated) =>
          this.replaceEntry(
            updated,
            'Oferta temporal registrada durante 20 minutos. Confirma el canal autorizado antes de comunicarla al paciente.',
          ),
        error: (error: unknown) =>
          this.reportActionError(error, 'No se ha podido registrar la oferta temporal.'),
      });
  }

  protected accept(entry: WaitlistEntryRecord): void {
    if (entry.status !== 'offered' || this.saving()) {
      return;
    }

    this.actionError.set(null);
    this.saving.set(true);
    this.waitlistApi
      .acceptOffer(entry)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        next: (updated) =>
          this.replaceEntry(updated, 'Oferta aceptada: la cita queda confirmada y auditada.'),
        error: (error: unknown) =>
          this.reportActionError(error, 'No se ha podido aceptar la oferta.'),
      });
  }

  protected reject(entry: WaitlistEntryRecord): void {
    if (entry.status !== 'offered' || this.saving()) {
      return;
    }

    this.actionError.set(null);
    this.saving.set(true);
    this.waitlistApi
      .rejectOffer(entry)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        next: (updated) =>
          this.replaceEntry(
            updated,
            'Oferta rechazada: la solicitud vuelve a la espera y el hueco reservado queda liberado.',
          ),
        error: (error: unknown) =>
          this.reportActionError(error, 'No se ha podido rechazar la oferta.'),
      });
  }

  protected expire(entry: WaitlistEntryRecord): void {
    if (entry.status !== 'offered' || this.saving()) {
      return;
    }

    this.actionError.set(null);
    this.saving.set(true);
    this.waitlistApi
      .expire(entry)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        next: (updated) =>
          this.replaceEntry(updated, 'Oferta caducada: el hueco reservado queda liberado.'),
        error: (error: unknown) =>
          this.reportActionError(error, 'No se ha podido caducar la oferta.'),
      });
  }

  private replaceEntry(updated: WaitlistEntryRecord, message: string): void {
    this.entries.update((entries) =>
      entries.map((item) => (item.id === updated.id ? updated : item)),
    );
    this.selectedId.set(updated.id);
    this.notifications.success(message);
  }

  private reportActionError(error: unknown, fallback: string): void {
    const appError = toAppError(error, fallback);
    this.actionError.set(appError);
    this.notifications.notify(appError);
  }

  protected statusLabel(status: WaitlistStatus): string {
    return {
      waiting: 'En espera',
      offered: 'Oferta temporal',
      booked: 'Reservada',
      expired: 'Caducada',
      cancelled: 'Cancelada',
    }[status];
  }

  protected statusTone(status: WaitlistStatus): 'info' | 'warning' | 'success' | 'danger' {
    switch (status) {
      case 'offered':
        return 'warning';
      case 'booked':
        return 'success';
      case 'expired':
      case 'cancelled':
        return 'danger';
      default:
        return 'info';
    }
  }

  protected priorityLabel(priority: number): string {
    return priority <= 1 ? 'Urgente' : priority === 2 ? 'Prioridad clínica' : 'Ordinaria';
  }

  protected priorityTone(priority: number): 'danger' | 'warning' | 'info' {
    return priority <= 1 ? 'danger' : priority === 2 ? 'warning' : 'info';
  }

  protected windowLabel(entry: WaitlistEntryRecord): string {
    const [from, to] = entry.preferredWindow;
    if (!from || !to) {
      return 'Ventana no disponible';
    }
    const formatter = new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' });
    return `${formatter.format(new Date(from))} — ${formatter.format(new Date(to))}`;
  }

  protected slotLabel(entry: WaitlistEntryRecord): string {
    const slot = entry.offeredSlot;
    if (!slot) {
      return 'Sin hueco asociado';
    }
    const formatter = new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' });
    const detail = [slot.practitionerName, slot.roomName].filter(Boolean).join(' · ');
    return `${formatter.format(new Date(slot.startsAt))} — ${formatter.format(new Date(slot.endsAt))}${detail ? ` · ${detail}` : ''}`;
  }

  protected offerExpiryLabel(entry: WaitlistEntryRecord): string {
    if (!entry.offerExpiresAt) {
      return '';
    }
    const remaining = Math.max(
      0,
      Math.ceil((new Date(entry.offerExpiresAt).getTime() - Date.now()) / 60_000),
    );
    return remaining <= 0 ? 'La oferta vence' : `Vence en ${remaining} min`;
  }

  protected waitingLabel(value: string | null): string {
    const days = daysSince(value);
    return days === 0 ? 'Hoy' : `${days} ${days === 1 ? 'día' : 'días'}`;
  }

  protected load(): void {
    this.loading.set(true);
    this.errorMessage.set(null);
    this.actionError.set(null);
    forkJoin({
      queue: this.waitlistApi.list(),
      patients: this.patientsApi.search(),
      referenceData: this.referenceDataApi.load(),
    })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: ({ queue, patients, referenceData }) => {
          const entries = [...queue.entries].sort(compareEntries);
          this.entries.set(entries);
          this.patients.set(patients.items);
          this.referenceData.set(referenceData);
          this.selectedId.set(entries[0]?.id ?? null);
        },
        error: (error: unknown) =>
          this.errorMessage.set(
            toAppError(error, 'No se ha podido cargar la lista de espera autorizada.'),
          ),
      });
  }
}

function compareEntries(left: WaitlistEntryRecord, right: WaitlistEntryRecord): number {
  return (
    left.priority - right.priority ||
    String(left.waitingSince).localeCompare(String(right.waitingSince))
  );
}

function asIso(value: string): string | null {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

function nextBusinessHour(): string {
  const date = new Date();
  date.setMinutes(0, 0, 0);
  date.setHours(date.getHours() + 1);
  if (date.getHours() < 8) {
    date.setHours(8, 0, 0, 0);
  }
  if (date.getHours() > 17) {
    date.setDate(date.getDate() + 1);
    date.setHours(9, 0, 0, 0);
  }
  if (date.getDay() === 6) {
    date.setDate(date.getDate() + 2);
  }
  if (date.getDay() === 0) {
    date.setDate(date.getDate() + 1);
  }
  return toLocalInput(date);
}

function toLocalInput(date: Date): string {
  const pad = (value: number): string => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function daysSince(value: string | null): number {
  if (!value) {
    return 0;
  }
  const timestamp = new Date(value).getTime();
  return Number.isNaN(timestamp)
    ? 0
    : Math.max(0, Math.floor((Date.now() - timestamp) / 86_400_000));
}

function median(values: readonly number[]): number {
  if (values.length === 0) {
    return 0;
  }
  const sorted = [...values].sort((left, right) => left - right);
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 === 0
    ? Math.round((sorted[middle - 1] + sorted[middle]) / 2)
    : sorted[middle];
}
