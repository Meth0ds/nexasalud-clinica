import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';

export interface AppointmentActionDialogData {
  readonly action: 'cancel' | 'no-show';
  readonly patientName: string;
}

export interface AppointmentActionDialogResult {
  readonly reason: string;
}

@Component({
  selector: 'app-appointment-action-dialog',
  imports: [
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatInputModule,
    ReactiveFormsModule,
  ],
  templateUrl: './appointment-action-dialog.html',
  styleUrl: './appointment-action-dialog.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppointmentActionDialog {
  protected readonly data = inject<AppointmentActionDialogData>(MAT_DIALOG_DATA);
  private readonly dialogRef = inject<
    MatDialogRef<AppointmentActionDialog, AppointmentActionDialogResult>
  >(MatDialogRef);
  private readonly formBuilder = inject(FormBuilder);

  protected readonly form = this.formBuilder.nonNullable.group({
    reason: ['', [Validators.required, Validators.minLength(3), Validators.maxLength(500)]],
  });

  protected get title(): string {
    return this.data.action === 'cancel' ? 'Cancelar cita' : 'Registrar no presentación';
  }

  protected get description(): string {
    return this.data.action === 'cancel'
      ? 'La cita dejará de reservar disponibilidad. Indica el motivo operativo de la cancelación.'
      : 'La cita se marcará como no presentada y dejará de reservar disponibilidad. Indica el motivo operativo.';
  }

  protected get confirmLabel(): string {
    return this.data.action === 'cancel' ? 'Confirmar cancelación' : 'Confirmar no presentación';
  }

  protected confirm(): void {
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    this.dialogRef.close({ reason: this.form.controls.reason.value.trim() });
  }
}
