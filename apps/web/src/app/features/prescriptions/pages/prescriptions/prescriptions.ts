import { DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';

import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

interface MedicationLine {
  readonly id: string;
  readonly name: string;
  readonly presentation: string;
  readonly dose: string;
  readonly frequency: string;
  readonly duration: string;
  readonly instructions: string;
}

interface PrescriptionRow {
  readonly id: string;
  readonly patient: string;
  readonly initials: string;
  readonly created: string;
  readonly medications: string;
  readonly status: 'Borrador' | 'Pendiente de firma' | 'Firmada' | 'Enviada' | 'Anulada';
  readonly tone: 'info' | 'warning' | 'success' | 'danger';
  readonly prescriber: string;
}

@Component({
  selector: 'app-prescriptions',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    AppIcon,
    ScrollReveal,
  ],
  templateUrl: './prescriptions.html',
  styleUrl: './prescriptions.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Prescriptions {
  private readonly formBuilder = inject(FormBuilder);
  private readonly notifications = inject(ErrorNotificationService);
  private readonly document = inject(DOCUMENT);

  protected readonly builderOpen = signal(false);
  protected readonly signing = signal(false);
  protected readonly draftLines = signal<readonly MedicationLine[]>([]);
  protected readonly prescriptions = signal<readonly PrescriptionRow[]>([
    { id: 'rx-2026-0042', patient: 'Lucas M.', initials: 'LM', created: 'Hoy · 08:42', medications: '1 medicamento', status: 'Pendiente de firma', tone: 'warning', prescriber: 'Dra. Elena Soler' },
    { id: 'rx-2026-0041', patient: 'Elena V.', initials: 'EV', created: 'Ayer · 17:28', medications: '2 medicamentos', status: 'Enviada', tone: 'success', prescriber: 'Dr. Mateo Vidal' },
    { id: 'rx-2026-0040', patient: 'Clara F.', initials: 'CF', created: '17 jul · 12:10', medications: '1 medicamento', status: 'Firmada', tone: 'info', prescriber: 'Dra. Elena Soler' },
    { id: 'rx-2026-0039', patient: 'Ana R.', initials: 'AR', created: '16 jul · 10:05', medications: '3 medicamentos', status: 'Anulada', tone: 'danger', prescriber: 'Dra. Elena Soler' },
  ]);

  protected readonly headerForm = this.formBuilder.nonNullable.group({
    patient: ['', Validators.required],
    indication: ['', [Validators.required, Validators.maxLength(500)]],
    confidentialDispensing: [false],
  });

  protected readonly medicationForm = this.formBuilder.nonNullable.group({
    medication: ['', Validators.required],
    presentation: ['Comprimidos', Validators.required],
    dose: ['', Validators.required],
    route: ['Oral', Validators.required],
    frequency: ['Cada 8 horas', Validators.required],
    duration: ['7 días', Validators.required],
    instructions: ['', Validators.maxLength(500)],
  });

  protected openBuilder(): void {
    this.builderOpen.set(true);
  }

  protected closeBuilder(): void {
    this.builderOpen.set(false);
    this.signing.set(false);
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
        id: globalThis.crypto.randomUUID(),
        name: value.medication.trim(),
        presentation: value.presentation,
        dose: `${value.dose} · ${value.route}`,
        frequency: value.frequency,
        duration: value.duration,
        instructions: value.instructions.trim(),
      },
    ]);
    this.medicationForm.reset({
      medication: '',
      presentation: 'Comprimidos',
      dose: '',
      route: 'Oral',
      frequency: 'Cada 8 horas',
      duration: '7 días',
      instructions: '',
    });
    this.notifications.success('Medicamento añadido al borrador.');
  }

  protected removeMedication(id: string): void {
    this.draftLines.update((lines) => lines.filter((line) => line.id !== id));
  }

  protected prepareSignature(): void {
    if (this.headerForm.invalid || this.draftLines().length === 0) {
      this.headerForm.markAllAsTouched();
      this.notifications.notify(
        new Error('Completa paciente, indicación y al menos un medicamento antes de firmar.'),
      );
      return;
    }
    this.signing.set(true);
  }

  protected confirmSignature(): void {
    this.prescriptions.update((items) => [
      {
        id: `rx-2026-${String(items.length + 43).padStart(4, '0')}`,
        patient: this.headerForm.controls.patient.value,
        initials: this.headerForm.controls.patient.value.slice(0, 2).toUpperCase(),
        created: 'Ahora',
        medications: `${this.draftLines().length} medicamento${this.draftLines().length === 1 ? '' : 's'}`,
        status: 'Firmada',
        tone: 'info',
        prescriber: 'Dra. Elena Soler',
      },
      ...items,
    ]);
    this.notifications.success('Prescripción firmada. Ya puede enviarse al servicio autorizado.');
    this.draftLines.set([]);
    this.headerForm.reset({ patient: '', indication: '', confidentialDispensing: false });
    this.closeBuilder();
  }

  protected send(row: PrescriptionRow): void {
    if (row.status !== 'Firmada') {
      return;
    }
    this.prescriptions.update((items) =>
      items.map((item) =>
        item.id === row.id ? { ...item, status: 'Enviada', tone: 'success' } : item,
      ),
    );
    this.notifications.success('Prescripción aceptada por el conector de receta electrónica.');
  }

  protected downloadPdf(row: PrescriptionRow): void {
    this.document.defaultView?.open(
      `/api/v1/prescriptions/${encodeURIComponent(row.id)}/documents/medication-plan.pdf`,
      '_blank',
      'noopener,noreferrer',
    );
  }
}
