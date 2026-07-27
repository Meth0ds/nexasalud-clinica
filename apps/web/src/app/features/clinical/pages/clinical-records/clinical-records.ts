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
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatSelectModule } from '@angular/material/select';
import { MatTabsModule } from '@angular/material/tabs';
import { RouterLink } from '@angular/router';
import { finalize, forkJoin } from 'rxjs';

import { Patient } from '../../../../core/models/app.models';
import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { PatientsService } from '../../../../core/services/patients';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import {
  ClinicalNoteRecord,
  ClinicalNotesApiService,
  ClinicalNoteStatus,
} from '../../data-access/clinical-notes-api';

@Component({
  selector: 'app-clinical-records',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    MatProgressSpinnerModule,
    MatSelectModule,
    MatTabsModule,
    RouterLink,
    AppIcon,
    ScrollReveal,
  ],
  templateUrl: './clinical-records.html',
  styleUrls: [
    './clinical-records.scss',
    './clinical-records-content.scss',
    './clinical-records-panels.scss',
    './clinical-records-note-editor.scss',
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ClinicalRecords {
  private readonly formBuilder = inject(FormBuilder);
  private readonly notesApi = inject(ClinicalNotesApiService);
  private readonly patientsApi = inject(PatientsService);
  private readonly notifications = inject(ErrorNotificationService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly loading = signal(true);
  protected readonly notesLoading = signal(false);
  protected readonly saving = signal(false);
  protected readonly editingNote = signal(false);
  protected readonly privacyVisible = signal(true);
  protected readonly errorMessage = signal<string | null>(null);
  protected readonly selectedPatientId = signal<string | null>(null);
  protected readonly patients = signal<readonly Patient[]>([]);
  protected readonly notes = signal<readonly ClinicalNoteRecord[]>([]);
  protected readonly selectedPatient = computed(
    () => this.patients().find((patient) => patient.id === this.selectedPatientId()) ?? null,
  );
  protected readonly signedNotes = computed(
    () => this.notes().filter((note) => note.status === 'signed').length,
  );
  protected readonly draftNotes = computed(
    () => this.notes().filter((note) => note.status === 'draft').length,
  );
  protected readonly noteForm = this.formBuilder.nonNullable.group({
    content: ['', [Validators.required, Validators.minLength(10), Validators.maxLength(20_000)]],
  });

  constructor() {
    this.loadPatients();
  }

  protected selectPatient(patientId: string): void {
    this.selectedPatientId.set(patientId || null);
    this.notes.set([]);
    this.editingNote.set(false);
    if (patientId) {
      this.loadNotes(patientId);
    }
  }

  protected openNoteEditor(): void {
    if (!this.selectedPatient()) {
      this.notifications.error('Selecciona un paciente autorizado antes de registrar una nota.');
      return;
    }
    this.noteForm.reset({ content: '' });
    this.editingNote.set(true);
  }

  protected closeNoteEditor(): void {
    if (!this.saving()) {
      this.editingNote.set(false);
    }
  }

  protected saveDraft(): void {
    this.saveNote('draft');
  }

  protected signNote(): void {
    this.saveNote('signed');
  }

  protected statusLabel(status: ClinicalNoteStatus): string {
    return status === 'signed' ? 'Firmada' : 'Borrador';
  }

  protected statusTone(status: ClinicalNoteStatus): 'success' | 'warning' {
    return status === 'signed' ? 'success' : 'warning';
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

  private saveNote(target: ClinicalNoteStatus): void {
    const patientId = this.selectedPatientId();
    if (!patientId || this.saving() || this.noteForm.invalid) {
      this.noteForm.markAllAsTouched();
      return;
    }

    const content = this.noteForm.controls.content.value.trim();
    this.saving.set(true);
    const request =
      target === 'signed'
        ? this.notesApi.sign(patientId, content)
        : this.notesApi.createDraft(patientId, content);
    request
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.saving.set(false)),
      )
      .subscribe({
        next: (note) => {
          this.notes.update((notes) => [note, ...notes]);
          this.editingNote.set(false);
          this.notifications.success(
            target === 'signed'
              ? 'Nota firmada. El servidor ha verificado MFA reciente y ha registrado la trazabilidad.'
              : 'Borrador clínico guardado como registro inmutable.',
          );
        },
      });
  }

  private loadPatients(): void {
    forkJoin({ patients: this.patientsApi.search() })
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: ({ patients }) => this.patients.set(patients.items),
        error: () =>
          this.errorMessage.set('No se ha podido cargar el directorio de pacientes autorizado.'),
      });
  }

  private loadNotes(patientId: string): void {
    this.notesLoading.set(true);
    this.notesApi
      .list(patientId)
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.notesLoading.set(false)),
      )
      .subscribe({
        next: ({ notes }) => this.notes.set(notes),
        error: () =>
          this.errorMessage.set(
            'No se ha podido cargar la línea clínica del paciente seleccionado.',
          ),
      });
  }
}
