import { DOCUMENT } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressBarModule } from '@angular/material/progress-bar';
import { MatSelectModule } from '@angular/material/select';
import { MatSlideToggleModule } from '@angular/material/slide-toggle';
import { finalize } from 'rxjs';

import { AppError, toAppError } from '../../../../core/errors/app-error';
import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { SessionService } from '../../../../core/services/session';
import { AppIcon, AppIconName } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import {
  PortalApiService,
  PortalAppointment,
  PortalAppointmentMode,
  PortalAvailability,
  PortalAvailabilitySlot,
  PortalBookingCatalog,
  PortalDocument,
  PortalInvoice,
  PortalMessage,
  PortalOverview,
  PortalPrescription,
  PortalSlotHold,
} from '../../data-access/portal-api';

type PortalSection =
  'inicio' | 'reservar' | 'citas' | 'espera' | 'salud' | 'pagos' | 'mensajes' | 'privacidad';
type HealthTab = 'medicacion' | 'documentos';

interface PortalNavItem {
  readonly id: PortalSection;
  readonly label: string;
  readonly shortLabel: string;
  readonly icon: AppIconName;
  readonly available: boolean;
}

interface BookingDateOption {
  readonly id: string;
  readonly day: string;
  readonly date: string;
  readonly month: string;
  readonly availability: string;
}

@Component({
  selector: 'app-patient-portal',
  imports: [
    FormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatInputModule,
    MatProgressBarModule,
    MatSelectModule,
    MatSlideToggleModule,
    AppIcon,
    ScrollReveal,
  ],
  templateUrl: './patient-portal.html',
  styleUrls: [
    './patient-portal.scss',
    './patient-portal-navigation.scss',
    './patient-portal-shell.scss',
    './patient-portal-home-hero.scss',
    './patient-portal-home.scss',
    './patient-portal-home-aside.scss',
    './patient-portal-booking.scss',
    './patient-portal-booking-slots.scss',
    './patient-portal-booking-confirmation.scss',
    './patient-portal-appointments.scss',
    './patient-portal-appointments-detail.scss',
    './patient-portal-waitlist.scss',
    './patient-portal-health.scss',
    './patient-portal-documents.scss',
    './patient-portal-billing.scss',
    './patient-portal-messages.scss',
    './patient-portal-message-reader.scss',
    './patient-portal-privacy.scss',
    './patient-portal-common.scss',
    './patient-portal-responsive-tablet.scss',
    './patient-portal-responsive-mobile-a.scss',
    './patient-portal-responsive-mobile-b.scss',
    './patient-portal-responsive-small.scss',
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PatientPortal {
  private readonly api = inject(PortalApiService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly document = inject(DOCUMENT);
  private readonly notifications = inject(ErrorNotificationService);
  private readonly session = inject(SessionService);

  protected readonly navItems: readonly PortalNavItem[] = [
    { id: 'inicio', label: 'Mi salud', shortLabel: 'Inicio', icon: 'home', available: true },
    {
      id: 'reservar',
      label: 'Pedir cita',
      shortLabel: 'Pedir cita',
      icon: 'plus',
      available: true,
    },
    { id: 'citas', label: 'Mis citas', shortLabel: 'Citas', icon: 'calendar', available: true },
    {
      id: 'espera',
      label: 'Lista de espera',
      shortLabel: 'Espera',
      icon: 'waitlist',
      available: false,
    },
    {
      id: 'salud',
      label: 'Medicación y documentos',
      shortLabel: 'Salud',
      icon: 'patients',
      available: true,
    },
    {
      id: 'pagos',
      label: 'Facturas y pagos',
      shortLabel: 'Pagos',
      icon: 'download',
      available: true,
    },
    {
      id: 'mensajes',
      label: 'Mensajes seguros',
      shortLabel: 'Mensajes',
      icon: 'bell',
      available: true,
    },
    {
      id: 'privacidad',
      label: 'Privacidad y solicitudes',
      shortLabel: 'Privacidad',
      icon: 'shield',
      available: false,
    },
  ];

  protected readonly activeSection = signal<PortalSection>('inicio');
  protected readonly mobileNavOpen = signal(false);
  protected readonly statusMessage = signal('');
  protected readonly currentSectionTitle = computed(
    () =>
      this.navItems.find((item) => item.id === this.activeSection())?.label ??
      'Portal del paciente',
  );

  protected readonly overview = signal<PortalOverview | null>(null);
  protected readonly overviewLoading = signal(true);
  protected readonly overviewError = signal<AppError | null>(null);
  protected readonly messages = signal<readonly PortalMessage[] | null>(null);
  protected readonly messagesLoading = signal(false);
  protected readonly messagesError = signal<AppError | null>(null);
  protected readonly selectedMessageId = signal<string | null>(null);
  protected readonly catalog = signal<PortalBookingCatalog | null>(null);
  protected readonly catalogLoading = signal(true);
  protected readonly catalogError = signal<AppError | null>(null);
  protected readonly availability = signal<PortalAvailability | null>(null);
  protected readonly availabilityLoading = signal(false);
  protected readonly availabilityError = signal<AppError | null>(null);
  protected readonly actionBusy = signal<string | null>(null);
  protected readonly downloadingDocumentId = signal<string | null>(null);

  protected readonly patientName = computed(
    () => this.overview()?.patient.displayName ?? 'Paciente',
  );
  protected readonly patientInitials = computed(() => this.overview()?.patient.initials || 'P');
  protected readonly appointments = computed(() =>
    [...(this.overview()?.appointments ?? [])].sort(
      (left, right) => Date.parse(left.startsAt) - Date.parse(right.startsAt),
    ),
  );
  protected readonly upcomingAppointments = computed(() =>
    this.appointments().filter(
      (appointment) =>
        Date.parse(appointment.startsAt) >= Date.now() &&
        !['completed', 'cancelled', 'no_show'].includes(appointment.status),
    ),
  );
  protected readonly pastAppointments = computed(() =>
    this.appointments()
      .filter(
        (appointment) =>
          Date.parse(appointment.startsAt) < Date.now() ||
          ['completed', 'cancelled', 'no_show'].includes(appointment.status),
      )
      .reverse(),
  );
  protected readonly nextAppointment = computed(() => this.upcomingAppointments()[0] ?? null);
  protected readonly prescriptions = computed(() => this.overview()?.prescriptions ?? []);
  protected readonly documents = computed(() => this.overview()?.documents ?? []);
  protected readonly invoices = computed(() => this.overview()?.invoices ?? []);
  protected readonly selectedMessage = computed(
    () =>
      this.messages()?.find((message) => message.id === this.selectedMessageId()) ??
      this.messages()?.[0] ??
      null,
  );
  protected readonly outstandingBalance = computed(() =>
    this.invoices().reduce((total, invoice) => total + invoice.balanceMinor, 0),
  );
  protected readonly assuranceLabel = computed(() => {
    const level = this.session.assuranceLevel();
    return level === 'mfa' || level === 'passkey'
      ? 'Segundo factor verificado'
      : 'Sesión autenticada';
  });

  protected readonly appointmentFilter = signal<'proximas' | 'anteriores'>('proximas');
  protected readonly cancelCandidate = signal<string | null>(null);

  protected readonly bookingStep = signal(1);
  protected readonly bookingIntent = signal<'new' | 'reschedule'>('new');
  protected readonly bookingAppointment = signal<PortalAppointment | null>(null);
  protected readonly selectedAppointmentTypeId = signal('');
  protected readonly selectedMode = signal<'in-person' | 'video'>('in-person');
  protected readonly selectedClinicId = signal('');
  protected readonly selectedDate = signal('');
  protected readonly selectedSlot = signal<PortalAvailabilitySlot | null>(null);
  protected readonly activeHold = signal<PortalSlotHold | null>(null);
  protected readonly bookingReason = signal('Consulta solicitada desde el portal');
  protected readonly bookingAccessibility = signal('');
  protected readonly bookingConsent = signal(false);
  protected readonly healthTab = signal<HealthTab>('medicacion');

  protected readonly careOptions = computed(() => this.catalog()?.appointmentTypes ?? []);
  protected readonly selectedCareType = computed(() =>
    this.careOptions().find((item) => item.id === this.selectedAppointmentTypeId()),
  );
  protected readonly selectedCare = computed(
    () => this.selectedCareType()?.name ?? 'Tipo de atención',
  );
  protected readonly selectedClinic = computed(() =>
    this.catalog()?.clinics.find((clinic) => clinic.id === this.selectedClinicId()),
  );
  protected readonly bookingDates = computed<readonly BookingDateOption[]>(() => {
    const groups = new Map<string, number>();
    for (const slot of this.availability()?.slots ?? []) {
      const id = dateKey(slot.startsAt, this.availability()?.timezone);
      groups.set(id, (groups.get(id) ?? 0) + 1);
    }
    return [...groups.entries()].map(([id, count]) => {
      const date = new Date(`${id}T12:00:00`);
      return {
        id,
        day: new Intl.DateTimeFormat('es-ES', { weekday: 'short' }).format(date).replace('.', ''),
        date: new Intl.DateTimeFormat('es-ES', { day: '2-digit' }).format(date),
        month: new Intl.DateTimeFormat('es-ES', { month: 'short' })
          .format(date)
          .replace('.', '')
          .toUpperCase(),
        availability: `${count} ${count === 1 ? 'hueco' : 'huecos'}`,
      };
    });
  });
  protected readonly availableSlots = computed(() =>
    (this.availability()?.slots ?? []).filter(
      (slot) => dateKey(slot.startsAt, this.availability()?.timezone) === this.selectedDate(),
    ),
  );
  protected readonly selectedDateLabel = computed(() => {
    const option = this.bookingDates().find((date) => date.id === this.selectedDate());
    return option
      ? `${option.day}, ${option.date} ${option.month.toLowerCase()}`
      : 'Fecha por elegir';
  });
  protected readonly bookingProgress = computed(() => this.bookingStep() * 25);

  constructor() {
    this.loadOverview();
    this.loadCatalog();
  }

  protected activateSection(section: PortalSection): void {
    const item = this.navItems.find((entry) => entry.id === section);
    if (!item?.available) {
      this.announce(`${item?.label ?? 'Esta sección'} no está disponible actualmente.`);
      return;
    }
    this.activeSection.set(section);
    this.mobileNavOpen.set(false);
    if (section === 'mensajes' && this.messages() === null && !this.messagesLoading()) {
      this.loadMessages();
    }
    globalThis.scrollTo?.({ top: 0, behavior: 'smooth' });
  }

  protected loadOverview(): void {
    this.overviewLoading.set(true);
    this.overviewError.set(null);
    this.api
      .getOverview()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.overviewLoading.set(false)),
      )
      .subscribe({
        next: (overview) => this.overview.set(overview),
        error: (error: unknown) => this.overviewError.set(toAppError(error)),
      });
  }

  protected loadCatalog(): void {
    this.catalogLoading.set(true);
    this.catalogError.set(null);
    this.api
      .getBookingCatalog()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.catalogLoading.set(false)),
      )
      .subscribe({
        next: (catalog) => {
          this.catalog.set(catalog);
          const contextClinicId = this.session.tenant()?.clinicId;
          this.selectedClinicId.set(
            catalog.clinics.find((clinic) => clinic.id === contextClinicId)?.id ??
              catalog.clinics[0]?.id ??
              '',
          );
          this.selectedAppointmentTypeId.set(catalog.appointmentTypes[0]?.id ?? '');
        },
        error: (error: unknown) => this.catalogError.set(toAppError(error)),
      });
  }

  protected loadMessages(): void {
    this.messagesLoading.set(true);
    this.messagesError.set(null);
    this.api
      .getMessages()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.messagesLoading.set(false)),
      )
      .subscribe({
        next: (messages) => {
          this.messages.set(messages);
          this.selectedMessageId.set(messages[0]?.id ?? null);
        },
        error: (error: unknown) => this.messagesError.set(toAppError(error)),
      });
  }

  protected selectMessage(id: string): void {
    this.selectedMessageId.set(id);
  }

  protected continueBooking(): void {
    const step = this.bookingStep();
    if (step === 1) {
      if (!this.selectedClinicId() || !this.selectedAppointmentTypeId()) {
        this.announce('Selecciona un centro y un tipo de atención para continuar.');
        return;
      }
      this.loadAvailability();
      return;
    }
    if (step === 2) {
      if (!this.selectedSlot()) {
        this.announce('Selecciona una hora disponible para continuar.');
        return;
      }
      this.bookingStep.set(3);
      return;
    }
    if (step === 3) {
      if (this.bookingReason().trim().length < 3) {
        this.announce('Describe brevemente el motivo de la consulta.');
        return;
      }
      this.createHold();
    }
  }

  protected previousBookingStep(): void {
    if (this.actionBusy()) return;
    this.bookingStep.update((step) => Math.max(1, step - 1));
  }

  protected chooseDate(date: string): void {
    this.selectedDate.set(date);
    this.selectedSlot.set(null);
    this.activeHold.set(null);
  }

  protected confirmBooking(): void {
    const hold = this.activeHold();
    if (!hold || Date.parse(hold.expiresAt) <= Date.now()) {
      this.activeHold.set(null);
      this.bookingStep.set(2);
      this.announce('La retención del hueco ha caducado. Selecciona una hora de nuevo.');
      return;
    }
    if (!this.bookingConsent()) {
      this.announce('Confirma que has revisado los datos de la cita.');
      return;
    }

    const original = this.bookingAppointment();
    this.actionBusy.set('booking');
    const request =
      this.bookingIntent() === 'reschedule' && original
        ? this.api.rescheduleAppointment(
            original.id,
            original.version,
            hold.holdId,
            this.bookingReason().trim(),
          )
        : this.api.createBooking(hold.holdId, this.bookingReason().trim());
    request
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.actionBusy.set(null)),
      )
      .subscribe({
        next: () => {
          const message =
            this.bookingIntent() === 'reschedule'
              ? 'La cita se ha reprogramado correctamente.'
              : 'La cita se ha reservado correctamente.';
          this.notifications.success(message);
          this.announce(message);
          this.resetBooking();
          this.loadOverview();
          this.activateSection('citas');
        },
        error: (error: unknown) => this.handleMutationError(error, true),
      });
  }

  protected startReschedule(appointment: PortalAppointment): void {
    if (!appointment.canReschedule) return;
    this.bookingIntent.set('reschedule');
    this.bookingAppointment.set(appointment);
    const matchingType = this.catalog()?.appointmentTypes.find(
      (type) => type.name === appointment.typeName,
    );
    const matchingClinic = this.catalog()?.clinics.find(
      (clinic) => clinic.name === appointment.clinicName,
    );
    if (matchingType) this.selectedAppointmentTypeId.set(matchingType.id);
    if (matchingClinic) this.selectedClinicId.set(matchingClinic.id);
    this.selectedMode.set(normalizeMode(appointment.mode) === 'video' ? 'video' : 'in-person');
    this.bookingReason.set('Reprogramación solicitada desde el portal');
    this.bookingStep.set(1);
    this.activateSection('reservar');
  }

  protected askToCancel(id: string): void {
    this.cancelCandidate.set(id);
  }

  protected confirmCancellation(appointment: PortalAppointment): void {
    this.actionBusy.set(`cancel:${appointment.id}`);
    this.api
      .cancelAppointment(
        appointment.id,
        appointment.version,
        'Cancelación solicitada por el paciente desde el portal',
      )
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.actionBusy.set(null)),
      )
      .subscribe({
        next: () => {
          this.cancelCandidate.set(null);
          this.notifications.success('La cita se ha cancelado correctamente.');
          this.announce('La cita se ha cancelado correctamente.');
          this.loadOverview();
        },
        error: (error: unknown) => this.handleMutationError(error),
      });
  }

  protected downloadDocument(document: PortalDocument): void {
    this.downloadingDocumentId.set(document.id);
    this.api
      .downloadDocument(document.id)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.downloadingDocumentId.set(null)),
      )
      .subscribe({
        next: ({ blob, filename }) => {
          const url = globalThis.URL.createObjectURL(blob);
          const anchor = this.document.createElement('a');
          anchor.href = url;
          anchor.download = filename;
          anchor.rel = 'noopener';
          anchor.click();
          globalThis.setTimeout(() => globalThis.URL.revokeObjectURL(url), 0);
          this.announce('El documento se ha descargado de forma segura.');
        },
        error: (error: unknown) => this.handleMutationError(error),
      });
  }

  protected announceUnavailable(label: string): void {
    this.announce(`${label} no está disponible actualmente.`);
  }

  protected announce(message: string): void {
    this.statusMessage.set('');
    globalThis.queueMicrotask?.(() => this.statusMessage.set(message));
  }

  protected formatDay(value: string): string {
    return new Intl.DateTimeFormat('es-ES', { weekday: 'long' }).format(new Date(value));
  }

  protected formatDayNumber(value: string): string {
    return new Intl.DateTimeFormat('es-ES', { day: '2-digit' }).format(new Date(value));
  }

  protected formatMonth(value: string): string {
    return new Intl.DateTimeFormat('es-ES', { month: 'short' })
      .format(new Date(value))
      .replace('.', '');
  }

  protected formatDate(value: string | null): string {
    if (!value) return 'Fecha no disponible';
    return new Intl.DateTimeFormat('es-ES', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    }).format(new Date(value));
  }

  protected formatTime(value: string): string {
    return new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' }).format(
      new Date(value),
    );
  }

  protected formatMessageDate(value: string | null): string {
    if (!value) return 'Fecha no disponible';

    return new Intl.DateTimeFormat('es-ES', {
      day: '2-digit',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
    }).format(new Date(value));
  }

  protected modeLabel(mode: PortalAppointmentMode | 'in-person' | 'video' | 'phone'): string {
    return normalizeMode(mode) === 'video'
      ? 'Videoconsulta'
      : normalizeMode(mode) === 'phone'
        ? 'Consulta telefónica'
        : 'En el centro';
  }

  protected statusLabel(status: string): string {
    const labels: Readonly<Record<string, string>> = {
      scheduled: 'Programada',
      confirmed: 'Confirmada',
      checked_in: 'Admisión realizada',
      in_progress: 'En curso',
      completed: 'Completada',
      cancelled: 'Cancelada',
      no_show: 'No asistida',
      draft: 'Borrador',
      signed: 'Firmada',
      issued: 'Emitida',
      paid: 'Pagada',
      partially_paid: 'Pago parcial',
      void: 'Anulada',
    };
    return labels[status] ?? humanize(status);
  }

  protected statusTone(status: string): 'success' | 'warning' | 'danger' | 'info' {
    if (['confirmed', 'completed', 'paid', 'signed'].includes(status)) return 'success';
    if (['cancelled', 'no_show', 'void'].includes(status)) return 'danger';
    if (['scheduled', 'draft', 'partially_paid'].includes(status)) return 'warning';
    return 'info';
  }

  protected documentLabel(document: PortalDocument): string {
    return humanize(document.type);
  }

  protected money(minor: number, currency: string): string {
    return new Intl.NumberFormat('es-ES', { style: 'currency', currency }).format(minor / 100);
  }

  protected prescriptionSummary(prescription: PortalPrescription): string {
    const items = `${prescription.itemCount} ${prescription.itemCount === 1 ? 'medicamento' : 'medicamentos'}`;
    return prescription.validUntil
      ? `${items} · válida hasta ${this.formatDate(prescription.validUntil)}`
      : items;
  }

  protected invoiceConcept(invoice: PortalInvoice): string {
    return invoice.number ? `Factura ${invoice.number}` : 'Documento de facturación';
  }

  private loadAvailability(): void {
    this.availabilityLoading.set(true);
    this.availabilityError.set(null);
    this.selectedSlot.set(null);
    this.activeHold.set(null);
    this.api
      .getAvailability({
        clinicId: this.selectedClinicId(),
        appointmentTypeId: this.selectedAppointmentTypeId(),
        mode: this.selectedMode(),
        days: 14,
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.availabilityLoading.set(false)),
      )
      .subscribe({
        next: (availability) => {
          this.availability.set(availability);
          const firstDate = availability.slots[0]
            ? dateKey(availability.slots[0].startsAt, availability.timezone)
            : '';
          this.selectedDate.set(firstDate);
          this.bookingStep.set(2);
        },
        error: (error: unknown) => {
          const appError = toAppError(error);
          this.availabilityError.set(appError);
          this.notifications.notify(appError);
          this.announce(appError.message);
        },
      });
  }

  private createHold(): void {
    const slot = this.selectedSlot();
    if (!slot) {
      this.bookingStep.set(2);
      return;
    }
    this.actionBusy.set('hold');
    this.api
      .createSlotHold(slot)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.actionBusy.set(null)),
      )
      .subscribe({
        next: (hold) => {
          this.activeHold.set(hold);
          this.bookingStep.set(4);
          this.announce('El hueco está retenido durante cinco minutos mientras confirmas.');
        },
        error: (error: unknown) => this.handleMutationError(error, true),
      });
  }

  private handleMutationError(error: unknown, refreshAvailability = false): void {
    const appError = this.notifications.notify(error);
    this.announce(appError.message);
    if (refreshAvailability && appError.status === 409) {
      this.activeHold.set(null);
      this.bookingStep.set(1);
    }
  }

  private resetBooking(): void {
    this.bookingStep.set(1);
    this.bookingIntent.set('new');
    this.bookingAppointment.set(null);
    this.selectedDate.set('');
    this.selectedSlot.set(null);
    this.activeHold.set(null);
    this.bookingConsent.set(false);
    this.bookingReason.set('Consulta solicitada desde el portal');
    this.availability.set(null);
  }
}

function normalizeMode(
  mode: PortalAppointmentMode | 'in-person' | 'video' | 'phone',
): 'in-person' | 'video' | 'phone' {
  return mode === 'in_person' ? 'in-person' : mode;
}

function dateKey(value: string, timezone = 'Europe/Madrid'): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date(value));
  const year = parts.find((part) => part.type === 'year')?.value;
  const month = parts.find((part) => part.type === 'month')?.value;
  const day = parts.find((part) => part.type === 'day')?.value;
  return `${year}-${month}-${day}`;
}

function humanize(value: string): string {
  const normalized = value.replaceAll('_', ' ').replaceAll('-', ' ').trim();
  return normalized
    ? `${normalized.charAt(0).toUpperCase()}${normalized.slice(1)}`
    : 'Sin especificar';
}
