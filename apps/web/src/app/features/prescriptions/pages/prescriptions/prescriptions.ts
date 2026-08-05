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
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { finalize, forkJoin, switchMap } from 'rxjs';

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
  PrescriptionApiService,
  PrescriptionMedication,
  PrescriptionRecord,
  PrescriptionStatus,
} from '../../data-access/prescription-api';

@Component({
  selector: 'app-prescriptions',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCardModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    MatProgressSpinnerModule,
    AppIcon,
    StatePanel,
    StatusChip,
    ScrollReveal,
  ],
  templateUrl: './prescriptions.html',
  styleUrls: [
    './prescriptions.scss',
    './prescription-builder.scss',
    './prescription-medication.scss',
    './prescription-signature.scss',
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Prescriptions {
  private readonly formBuilder = inject(FormBuilder);
  private readonly prescriptionApi = inject(PrescriptionApiService);
  private readonly patientsApi = inject(PatientsService);
  private readonly referenceDataApi = inject(ReferenceDataService);
  private readonly notifications = inject(ErrorNotificationService);
  private readonly document = inject(DOCUMENT);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly builderOpen = signal(false);
  protected readonly loading = signal(true);
  protected readonly saving = signal(false);
  protected readonly transitioningId = signal<string | null>(null);
  protected readonly pdfId = signal<string | null>(null);
  protected readonly errorMessage = signal<AppError | null>(null);
  protected readonly actionError = signal<AppError | null>(null);
  protected readonly query = signal('');
  protected readonly signingPrescription = signal<PrescriptionRecord | null>(null);
  protected readonly draftLines = signal<readonly PrescriptionMedication[]>([]);
  protected readonly prescriptions = signal<readonly PrescriptionRecord[]>([]);
  protected readonly patients = signal<readonly Patient[]>([]);
  protected readonly referenceData = signal<ReferenceData | null>(null);
  protected readonly total = signal(0);
  protected readonly filteredPrescriptions = computed(() => {
    const query = this.query().trim().toLocaleLowerCase('es');
    if (!query) {
      return this.prescriptions();
    }

    return this.prescriptions().filter((prescription) =>
      [
        prescription.patientDisplayName,
        prescription.prescriberName ?? '',
        prescription.id,
        ...prescription.items.map((item) => item.display),
      ].some((value) => value.toLocaleLowerCase('es').includes(query)),
    );
  });
  protected readonly counts = computed(() => ({
    pendingReview: this.prescriptions().filter(
      (prescription) => prescription.status === 'pending_review',
    ).length,
    signed: this.prescriptions().filter((prescription) => prescription.status === 'signed').length,
    sent: this.prescriptions().filter((prescription) => prescription.status === 'sent').length,
    safetyAlerts: this.prescriptions().reduce(
      (count, prescription) => count + prescription.safetyAlerts.length,
      0,
    ),
  }));
  protected readonly selectedClinicId = computed(
    () => this.referenceData()?.clinics[0]?.id ?? null,
  );

  protected readonly headerForm = this.formBuilder.nonNullable.group({
    patientId: ['', Validators.required],
    clinicalIndication: [
      '',
      [Validators.required, Validators.minLength(3), Validators.maxLength(1000)],
    ],
    validUntil: [''],
  });

  protected readonly medicationForm = this.formBuilder.nonNullable.group({
    codeSystem: ['CIMA', [Validators.required, Validators.maxLength(120)]],
    code: ['', [Validators.required, Validators.maxLength(120)]],
    display: ['', [Validators.required, Validators.maxLength(255)]],
    doseAmount: ['', [Validators.required, Validators.min(0.01)]],
    doseUnitCode: ['mg', [Validators.required, Validators.maxLength(40)]],
    routeCode: ['oral', [Validators.required, Validators.maxLength(80)]],
    frequencyCode: ['every-8-hours', [Validators.required, Validators.maxLength(80)]],
    durationValue: ['7', [Validators.min(1), Validators.max(3650)]],
    durationUnit: ['day', Validators.maxLength(30)],
    quantity: ['21', [Validators.required, Validators.min(0.01)]],
    quantityUnitCode: ['tablet', [Validators.required, Validators.maxLength(40)]],
    instructions: ['', Validators.maxLength(1000)],
  });

  protected readonly signatureForm = this.formBuilder.nonNullable.group({
    reason: ['', Validators.maxLength(500)],
  });

  constructor() {
    this.load();
  }

  protected openBuilder(): void {
    this.actionError.set(null);
    this.builderOpen.set(true);
  }

  protected closeBuilder(): void {
    if (this.saving()) {
      return;
    }
    this.builderOpen.set(false);
  }

  protected addMedication(): void {
    if (this.medicationForm.invalid) {
      this.medicationForm.markAllAsTouched();
      return;
    }

    const value = this.medicationForm.getRawValue();
    this.draftLines.update((lines) => [
      ...lines,
      {
        codeSystem: value.codeSystem.trim(),
        code: value.code.trim(),
        display: value.display.trim(),
        doseAmount: Number(value.doseAmount),
        doseUnitCode: value.doseUnitCode.trim(),
        routeCode: value.routeCode.trim(),
        frequencyCode: value.frequencyCode.trim(),
        durationValue: value.durationValue.trim() === '' ? null : Number(value.durationValue),
        durationUnit: value.durationValue.trim() === '' ? null : value.durationUnit.trim() || null,
        quantity: Number(value.quantity),
        quantityUnitCode: value.quantityUnitCode.trim(),
        instructions: value.instructions.trim() || null,
      },
    ]);
    this.medicationForm.reset({
      codeSystem: 'CIMA',
      code: '',
      display: '',
      doseAmount: '',
      doseUnitCode: 'mg',
      routeCode: 'oral',
      frequencyCode: 'every-8-hours',
      durationValue: '7',
      durationUnit: 'day',
      quantity: '21',
      quantityUnitCode: 'tablet',
      instructions: '',
    });
  }

  protected removeMedication(index: number): void {
    this.draftLines.update((lines) => lines.filter((_, currentIndex) => currentIndex !== index));
  }

  protected saveDraft(): void {
    const clinicId = this.selectedClinicId();
    if (!clinicId || this.headerForm.invalid || this.draftLines().length === 0 || this.saving()) {
      this.headerForm.markAllAsTouched();
      this.notifications.notify(
        new Error('Completa paciente, indicación y al menos una línea de medicación válida.'),
      );
      return;
    }

    const header = this.headerForm.getRawValue();
    this.saving.set(true);
    this.actionError.set(null);
    this.prescriptionApi
      .create({
        clinicId,
        patientId: header.patientId,
        clinicalIndication: header.clinicalIndication.trim(),
        ...(header.validUntil ? { validUntil: header.validUntil } : {}),
        items: this.draftLines(),
      })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        next: (prescription) => {
          this.prescriptions.update((items) => [prescription, ...items]);
          this.total.update((count) => count + 1);
          this.resetBuilder();
          this.notifications.success('Borrador de prescripción creado y registrado.');
        },
        error: (error: unknown) =>
          this.reportActionError(error, 'No se ha podido crear el borrador de prescripción.'),
      });
  }

  protected requestReview(prescription: PrescriptionRecord): void {
    this.transition(prescription, 'pending_review');
  }

  protected sign(prescription: PrescriptionRecord): void {
    const reasonControl = this.signatureForm.controls.reason;
    reasonControl.reset('');
    reasonControl.setValidators(
      prescription.safetyAlerts.length > 0
        ? [Validators.required, Validators.minLength(8), Validators.maxLength(500)]
        : [Validators.maxLength(500)],
    );
    reasonControl.updateValueAndValidity();
    this.actionError.set(null);
    this.signingPrescription.set(prescription);
  }

  protected closeSignature(): void {
    if (!this.transitioningId()) {
      this.signingPrescription.set(null);
    }
  }

  protected confirmSignature(): void {
    const prescription = this.signingPrescription();
    if (!prescription || this.transitioningId()) {
      return;
    }

    if (this.signatureForm.invalid) {
      this.signatureForm.markAllAsTouched();
      return;
    }

    const reason = this.signatureForm.controls.reason.value.trim();
    this.transitioningId.set(prescription.id);
    this.actionError.set(null);
    this.prescriptionApi
      .transition(prescription.id, prescription.version, 'signed', reason ? { reason } : {})
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.transitioningId.set(null)),
      )
      .subscribe({
        next: (updated) => {
          this.prescriptions.update((items) =>
            items.map((item) => (item.id === updated.id ? updated : item)),
          );
          this.signingPrescription.set(null);
          this.notifications.success(
            'Prescripción firmada. La MFA, el permiso y la trazabilidad han sido verificados por el servidor.',
          );
        },
        error: (error: unknown) =>
          this.reportActionError(error, 'No se ha podido firmar la prescripción.'),
      });
  }

  protected downloadPdf(prescription: PrescriptionRecord): void {
    if (prescription.status !== 'signed' && prescription.status !== 'sent') {
      this.notifications.notify(
        new Error('Solo se puede generar el plan PDF de una prescripción firmada o enviada.'),
      );
      return;
    }

    this.pdfId.set(prescription.id);
    this.actionError.set(null);
    this.prescriptionApi
      .generateMedicationPlan(prescription.id)
      .pipe(
        switchMap((document) => {
          if (!document.downloadPath) {
            throw new Error('La ruta de descarga del documento no está disponible.');
          }
          return this.prescriptionApi.downloadDocument(document.downloadPath);
        }),
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.pdfId.set(null)),
      )
      .subscribe({
        next: (download) => this.downloadBlob(download.blob, download.filename),
        error: (error: unknown) =>
          this.reportActionError(error, 'No se ha podido generar o descargar el plan PDF.'),
      });
  }

  protected statusLabel(status: PrescriptionStatus): string {
    return {
      draft: 'Borrador',
      pending_review: 'Pendiente de firma',
      signed: 'Firmada',
      sent: 'Enviada',
      cancelled: 'Anulada',
    }[status];
  }

  protected statusTone(status: PrescriptionStatus): 'info' | 'warning' | 'success' | 'danger' {
    return status === 'draft'
      ? 'info'
      : status === 'pending_review'
        ? 'warning'
        : status === 'cancelled'
          ? 'danger'
          : 'success';
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

  private transition(prescription: PrescriptionRecord, status: PrescriptionStatus): void {
    this.transitioningId.set(prescription.id);
    this.actionError.set(null);
    this.prescriptionApi
      .transition(prescription.id, prescription.version, status)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.transitioningId.set(null)),
      )
      .subscribe({
        next: (updated) => {
          this.prescriptions.update((items) =>
            items.map((item) => (item.id === updated.id ? updated : item)),
          );
          this.notifications.success('Prescripción enviada a revisión.');
        },
        error: (error: unknown) =>
          this.reportActionError(error, 'No se ha podido cambiar el estado de la prescripción.'),
      });
  }

  protected load(): void {
    this.loading.set(true);
    this.errorMessage.set(null);
    this.actionError.set(null);
    forkJoin({
      prescriptions: this.prescriptionApi.list(),
      patients: this.patientsApi.search(),
      referenceData: this.referenceDataApi.load(),
    })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: ({ prescriptions, patients, referenceData }) => {
          this.prescriptions.set(prescriptions.prescriptions);
          this.total.set(prescriptions.total);
          this.patients.set(patients.items);
          this.referenceData.set(referenceData);
        },
        error: (error: unknown) =>
          this.errorMessage.set(
            toAppError(
              error,
              'No se pudieron cargar las prescripciones. Comprueba tu sesión, el contexto y los permisos clínicos.',
            ),
          ),
      });
  }

  private resetBuilder(): void {
    this.draftLines.set([]);
    this.headerForm.reset({ patientId: '', clinicalIndication: '', validUntil: '' });
    this.builderOpen.set(false);
  }

  private downloadBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const anchor = this.document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    anchor.style.display = 'none';
    this.document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
  }

  private reportActionError(error: unknown, fallback: string): void {
    const appError = toAppError(error, fallback);
    this.actionError.set(appError);
    this.notifications.notify(appError);
  }
}
