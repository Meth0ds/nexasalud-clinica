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
import { MatButtonToggleModule } from '@angular/material/button-toggle';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { finalize, forkJoin } from 'rxjs';

import { AppError, toAppError } from '../../../../core/errors/app-error';
import { Patient } from '../../../../core/models/app.models';
import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { PatientsService } from '../../../../core/services/patients';
import { SessionService } from '../../../../core/services/session';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { StatePanel } from '../../../../shared/components/state-panel/state-panel';
import { StatusChip } from '../../../../shared/components/status-chip/status-chip';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import {
  CommunicationChannel,
  CommunicationContentClass,
  CommunicationStatus,
  CommunicationsApiService,
  ExternalTemplateKey,
  PatientCommunicationRecord,
} from '../../data-access/communications-api';

type CommunicationFilter = 'all' | CommunicationStatus;

const EXTERNAL_TEMPLATES: readonly {
  readonly key: ExternalTemplateKey;
  readonly label: string;
  readonly description: string;
}[] = [
  {
    key: 'appointment_reminder',
    label: 'Recordatorio de cita',
    description: 'Aviso genérico para acceder al área privada.',
  },
  {
    key: 'appointment_change',
    label: 'Actualización de cita',
    description: 'Aviso genérico de cambio sin datos asistenciales.',
  },
  {
    key: 'billing_notification',
    label: 'Aviso administrativo de facturación',
    description: 'Aviso para revisar documentación en el portal.',
  },
  {
    key: 'generic_notification',
    label: 'Nueva notificación del centro',
    description: 'Aviso neutro sin contenido clínico.',
  },
];

@Component({
  selector: 'app-communications',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatButtonToggleModule,
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
  templateUrl: './communications.html',
  styleUrls: [
    './communications.scss',
    './communications-compose.scss',
    './communications-side.scss',
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Communications {
  private readonly formBuilder = inject(FormBuilder);
  private readonly communicationsApi = inject(CommunicationsApiService);
  private readonly patientsApi = inject(PatientsService);
  private readonly notifications = inject(ErrorNotificationService);
  private readonly session = inject(SessionService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly composeOpen = signal(false);
  protected readonly errorMessage = signal<AppError | null>(null);
  protected readonly actionError = signal<AppError | null>(null);
  protected readonly searchTerm = signal('');
  protected readonly activeFilter = signal<CommunicationFilter>('all');
  protected readonly selectedId = signal<string | null>(null);
  protected readonly communications = signal<readonly PatientCommunicationRecord[]>([]);
  protected readonly patients = signal<readonly Patient[]>([]);
  protected readonly externalTemplates = EXTERNAL_TEMPLATES;
  protected readonly composeForm = this.formBuilder.nonNullable.group({
    patientId: ['', Validators.required],
    channel: this.formBuilder.nonNullable.control<CommunicationChannel>('portal'),
    contentClass: this.formBuilder.nonNullable.control<CommunicationContentClass>('administrative'),
    subject: ['', [Validators.maxLength(160)]],
    body: ['', [Validators.maxLength(4000)]],
    templateKey: this.formBuilder.nonNullable.control<ExternalTemplateKey>('appointment_reminder'),
  });
  protected readonly currentChannel = signal<CommunicationChannel>('portal');
  protected readonly isExternalChannel = computed(() => this.currentChannel() !== 'portal');
  protected readonly canComposeClinical = computed(() =>
    this.session.hasPermission('communications.clinical.write'),
  );
  protected readonly filteredCommunications = computed(() => {
    const term = this.searchTerm().trim().toLocaleLowerCase('es-ES');
    const filter = this.activeFilter();

    return this.communications().filter((record) => {
      const matchesStatus = filter === 'all' || record.status === filter;
      const matchesSearch =
        !term ||
        `${record.patientDisplayName} ${record.subject ?? ''} ${record.preview}`
          .toLocaleLowerCase('es-ES')
          .includes(term);
      return matchesStatus && matchesSearch;
    });
  });
  protected readonly selected = computed(
    () => this.communications().find((record) => record.id === this.selectedId()) ?? null,
  );
  protected readonly draftCount = computed(
    () => this.communications().filter((record) => record.status === 'draft').length,
  );
  protected readonly queuedCount = computed(
    () => this.communications().filter((record) => record.status === 'queued').length,
  );
  protected readonly scheduledCount = computed(
    () =>
      this.communications().filter(
        (record) => record.status === 'queued' && isFuture(record.scheduledAt),
      ).length,
  );
  protected readonly cancelledCount = computed(
    () => this.communications().filter((record) => record.status === 'cancelled').length,
  );

  constructor() {
    this.composeForm.controls.channel.valueChanges
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((channel) => this.configureChannel(channel));
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.errorMessage.set(null);
    this.actionError.set(null);
    forkJoin({ timeline: this.communicationsApi.list(), patients: this.patientsApi.search() })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: ({ timeline, patients }) => {
          const communications = [...timeline.communications].sort(compareCommunications);
          this.communications.set(communications);
          this.patients.set(patients.items);
          this.selectedId.set(communications[0]?.id ?? null);
        },
        error: (error: unknown) =>
          this.errorMessage.set(
            toAppError(error, 'No se ha podido cargar la línea de comunicaciones autorizada.'),
          ),
      });
  }

  protected openCompose(): void {
    this.actionError.set(null);
    if (this.patients().length === 0) {
      this.notifications.error('No hay pacientes autorizados disponibles para esta comunicación.');
      return;
    }
    this.composeForm.reset({
      patientId: '',
      channel: 'portal',
      contentClass: 'administrative',
      subject: '',
      body: '',
      templateKey: 'appointment_reminder',
    });
    this.currentChannel.set('portal');
    this.composeOpen.set(true);
  }

  protected closeCompose(): void {
    if (!this.saving()) {
      this.composeOpen.set(false);
    }
  }

  protected select(record: PatientCommunicationRecord): void {
    this.selectedId.set(record.id);
  }

  protected setFilter(filter: CommunicationFilter): void {
    this.activeFilter.set(filter);
  }

  protected setSearch(value: string): void {
    this.searchTerm.set(value);
  }

  protected save(mode: 'draft' | 'queue'): void {
    if (this.saving()) {
      return;
    }

    const value = this.composeForm.getRawValue();
    this.applyChannelValidators(value.channel);
    if (this.composeForm.invalid) {
      this.composeForm.markAllAsTouched();
      return;
    }

    const input =
      value.channel === 'portal'
        ? {
            patientId: value.patientId,
            channel: value.channel,
            contentClass: value.contentClass,
            mode,
            subject: value.subject.trim(),
            body: value.body.trim(),
          }
        : {
            patientId: value.patientId,
            channel: value.channel,
            contentClass: 'administrative' as const,
            mode,
            templateKey: value.templateKey,
          };
    this.saving.set(true);
    this.actionError.set(null);
    this.communicationsApi
      .create(input)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        next: (record) => {
          this.communications.update((items) =>
            [record, ...items.filter((item) => item.id !== record.id)].sort(compareCommunications),
          );
          this.selectedId.set(record.id);
          this.composeOpen.set(false);
          this.notifications.success(
            mode === 'draft'
              ? 'Borrador cifrado y auditado. Aún no se ha encolado ningún envío.'
              : 'Comunicación validada y añadida a la cola segura. La entrega externa requiere proveedor configurado.',
          );
        },
        error: (error: unknown) =>
          this.reportActionError(error, 'No se ha podido guardar la comunicación de forma segura.'),
      });
  }

  protected queue(record: PatientCommunicationRecord): void {
    if (record.status !== 'draft' || this.saving()) {
      return;
    }

    this.saving.set(true);
    this.actionError.set(null);
    this.communicationsApi
      .queue(record)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        next: (updated) => {
          this.replace(updated);
          this.notifications.success(
            'Borrador validado contra el consentimiento actual y añadido a la cola segura.',
          );
        },
        error: (error: unknown) =>
          this.reportActionError(error, 'No se ha podido añadir la comunicación a la cola segura.'),
      });
  }

  protected cancel(record: PatientCommunicationRecord): void {
    if (record.status === 'cancelled' || this.saving()) {
      return;
    }

    this.saving.set(true);
    this.actionError.set(null);
    this.communicationsApi
      .cancel(record)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        next: (updated) => {
          this.replace(updated);
          this.notifications.success(
            'La comunicación se ha cancelado y queda registrada en la auditoría.',
          );
        },
        error: (error: unknown) =>
          this.reportActionError(error, 'No se ha podido cancelar la comunicación.'),
      });
  }

  protected setChannel(channel: CommunicationChannel): void {
    this.composeForm.controls.channel.setValue(channel);
  }

  protected channelLabel(channel: CommunicationChannel): string {
    return { portal: 'Portal seguro', sms: 'SMS con plantilla', email: 'Correo con plantilla' }[
      channel
    ];
  }

  protected statusLabel(status: CommunicationStatus): string {
    return { draft: 'Borrador', queued: 'En cola segura', cancelled: 'Cancelada' }[status];
  }

  protected statusTone(status: CommunicationStatus): 'info' | 'warning' | 'danger' {
    return status === 'queued' ? 'warning' : status === 'cancelled' ? 'danger' : 'info';
  }

  protected formatDate(value: string | null): string {
    if (!value) {
      return 'Sin fecha';
    }
    const date = new Date(value);
    return Number.isNaN(date.getTime())
      ? 'Sin fecha'
      : new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }

  private configureChannel(channel: CommunicationChannel): void {
    this.currentChannel.set(channel);
    if (channel !== 'portal') {
      this.composeForm.patchValue(
        { contentClass: 'administrative', subject: '', body: '' },
        { emitEvent: false },
      );
    }
    this.applyChannelValidators(channel);
  }

  private applyChannelValidators(channel: CommunicationChannel): void {
    const subject = this.composeForm.controls.subject;
    const body = this.composeForm.controls.body;
    const templateKey = this.composeForm.controls.templateKey;
    if (channel === 'portal') {
      subject.setValidators([
        Validators.required,
        Validators.minLength(3),
        Validators.maxLength(160),
      ]);
      body.setValidators([
        Validators.required,
        Validators.minLength(2),
        Validators.maxLength(4000),
      ]);
      templateKey.clearValidators();
    } else {
      subject.clearValidators();
      body.clearValidators();
      templateKey.setValidators([Validators.required]);
    }
    subject.updateValueAndValidity({ emitEvent: false });
    body.updateValueAndValidity({ emitEvent: false });
    templateKey.updateValueAndValidity({ emitEvent: false });
  }

  private replace(updated: PatientCommunicationRecord): void {
    this.communications.update((items) =>
      items.map((item) => (item.id === updated.id ? updated : item)).sort(compareCommunications),
    );
    this.selectedId.set(updated.id);
  }

  private reportActionError(error: unknown, fallback: string): void {
    const appError = toAppError(error, fallback);
    this.actionError.set(appError);
    this.notifications.notify(appError);
  }
}

function compareCommunications(
  left: PatientCommunicationRecord,
  right: PatientCommunicationRecord,
): number {
  return String(right.createdAt).localeCompare(String(left.createdAt));
}

function isFuture(value: string | null): boolean {
  return value !== null && new Date(value).getTime() > Date.now();
}
