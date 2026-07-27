import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';

import { AppIcon } from '../../../../shared/components/app-icon/app-icon';

export interface AppointmentDialogData {
  readonly mode: 'create' | 'edit' | 'reschedule';
  readonly id?: string;
  readonly patientId?: string;
  readonly practitionerId?: string;
  readonly date?: string;
  readonly start?: string;
  readonly duration?: number;
  readonly appointmentTypeId?: string;
  readonly roomId?: string;
  readonly appointmentMode?: 'in-person' | 'video' | 'phone';
  readonly patients: readonly { readonly id: string; readonly label: string }[];
  readonly practitioners: readonly { readonly id: string; readonly label: string }[];
  readonly appointmentTypes: readonly {
    readonly id: string;
    readonly label: string;
    readonly durationMinutes: number;
  }[];
  readonly rooms: readonly { readonly id: string; readonly label: string }[];
}

export interface AppointmentDialogResult {
  readonly id?: string;
  readonly patientId: string;
  readonly practitionerId: string;
  readonly date: string;
  readonly start: string;
  readonly duration: number;
  readonly appointmentTypeId: string;
  readonly mode: 'in-person' | 'video' | 'phone';
  readonly roomId: string;
  readonly reason: string;
  readonly notifyPatient: boolean;
}

@Component({
  selector: 'app-appointment-dialog',
  imports: [
    ReactiveFormsModule,
    MatDialogModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    AppIcon,
  ],
  templateUrl: './appointment-dialog.html',
  styleUrl: './appointment-dialog.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppointmentDialog {
  protected readonly data = inject<AppointmentDialogData>(MAT_DIALOG_DATA);
  private readonly dialogRef = inject(MatDialogRef<AppointmentDialog, AppointmentDialogResult>);
  private readonly formBuilder = inject(FormBuilder);

  protected readonly form = this.formBuilder.nonNullable.group({
    patientId: [this.data.patientId ?? this.data.patients[0]?.id ?? '', Validators.required],
    practitionerId: [
      this.data.practitionerId ?? this.data.practitioners[0]?.id ?? '',
      Validators.required,
    ],
    date: [this.data.date ?? new Date().toISOString().slice(0, 10), Validators.required],
    start: [this.data.start ?? '12:00', Validators.required],
    duration: [
      this.data.duration ?? 30,
      [Validators.required, Validators.min(10), Validators.max(240)],
    ],
    appointmentTypeId: [
      this.data.appointmentTypeId ?? this.data.appointmentTypes[0]?.id ?? '',
      Validators.required,
    ],
    appointmentMode: this.formBuilder.nonNullable.control<'in-person' | 'video' | 'phone'>(
      this.data.appointmentMode ?? 'in-person',
      Validators.required,
    ),
    roomId: [this.data.roomId ?? this.data.rooms[0]?.id ?? '', Validators.required],
    reason: ['', [Validators.maxLength(500)]],
    notifyPatient: [true],
  });

  protected get title(): string {
    if (this.data.mode === 'reschedule') {
      return 'Cambiar cita';
    }
    return this.data.mode === 'edit' ? 'Editar cita' : 'Nueva cita';
  }

  protected submit(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();
    this.dialogRef.close({
      id: this.data.id,
      patientId: raw.patientId,
      practitionerId: raw.practitionerId,
      date: raw.date,
      start: raw.start,
      duration: Number(raw.duration),
      appointmentTypeId: raw.appointmentTypeId,
      mode: raw.appointmentMode,
      roomId: raw.roomId,
      reason: raw.reason.trim(),
      notifyPatient: raw.notifyPatient,
    });
  }
}
