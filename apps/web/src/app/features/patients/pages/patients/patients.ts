import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatCheckboxModule } from '@angular/material/checkbox';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatSelectModule } from '@angular/material/select';
import { RouterLink } from '@angular/router';

import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import { Patient } from '../../../../core/models/app.models';
import { PatientsService } from '../../../../core/services/patients';
import { ErrorNotificationService } from '../../../../core/services/error-notification';

interface PatientRow {
  readonly id: string;
  readonly initials: string;
  readonly name: string;
  readonly age: string;
  readonly phone: string;
  readonly coverage: string;
  readonly lastVisit: string;
  readonly nextVisit: string;
  readonly practitioner: string;
  readonly alerts: readonly string[];
  readonly consent: 'Al día' | 'Revisar' | 'Pendiente';
}

@Component({
  selector: 'app-patients',
  imports: [
    ReactiveFormsModule,
    MatButtonModule,
    MatCheckboxModule,
    MatFormFieldModule,
    MatInputModule,
    MatSelectModule,
    RouterLink,
    AppIcon,
    ScrollReveal,
  ],
  templateUrl: './patients.html',
  styleUrl: './patients.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Patients implements OnInit {
  private readonly formBuilder = new FormBuilder();
  private readonly patientApi = inject(PatientsService);
  private readonly notifications = inject(ErrorNotificationService);
  protected readonly search = signal('');
  protected readonly selectedId = signal<string | null>(null);
  protected readonly createOpen = signal(false);
  protected readonly patients = signal<readonly PatientRow[]>([]);
  protected readonly total = signal(0);
  protected readonly filteredPatients = computed(() => {
    const query = this.search().trim().toLocaleLowerCase('es');
    return query
      ? this.patients().filter((patient) =>
          `${patient.name} ${patient.coverage} ${patient.practitioner}`
            .toLocaleLowerCase('es')
            .includes(query),
        )
      : this.patients();
  });
  protected readonly selected = computed(() =>
    this.patients().find((patient) => patient.id === this.selectedId()),
  );

  protected readonly createForm = this.formBuilder.nonNullable.group({
    name: ['', [Validators.required, Validators.minLength(2)]],
    birthDate: ['', Validators.required],
    identifier: ['', Validators.required],
    phone: ['', Validators.required],
    email: ['', [Validators.required, Validators.email]],
    privacyNotice: [false, Validators.requiredTrue],
  });

  ngOnInit(): void {
    this.loadPatients();
  }

  protected savePatient(): void {
    if (this.createForm.invalid) {
      this.createForm.markAllAsTouched();
      return;
    }
    const value = this.createForm.getRawValue();
    const parts = value.name.trim().split(/\s+/);
    const firstName = parts.shift() ?? '';
    const lastName = parts.join(' ') || 'Sin apellidos indicados';
    this.patientApi.create({
      medicalRecordNumber: value.identifier,
      firstName,
      lastName,
      birthDate: value.birthDate,
      phone: value.phone,
      email: value.email,
      preferredLanguage: 'es',
    }).subscribe({
      next: (patient) => {
        const row = this.toRow(patient);
        this.patients.update((patients) => [row, ...patients]);
        this.total.update((total) => total + 1);
        this.selectedId.set(row.id);
        this.createOpen.set(false);
        this.createForm.reset({ name: '', birthDate: '', identifier: '', phone: '', email: '', privacyNotice: false });
        this.notifications.success('Ficha de paciente creada y acceso registrado.');
      },
    });
  }

  private loadPatients(): void {
    this.patientApi.search().subscribe({
      next: (response) => {
        this.patients.set(response.items.map((patient) => this.toRow(patient)));
        this.total.set(response.total);
        this.selectedId.set(response.items[0]?.id ?? null);
      },
    });
  }

  private toRow(patient: Patient): PatientRow {
    return {
      id: patient.id,
      initials: patient.initials,
      name: patient.displayName,
      age: patient.ageRange,
      phone: patient.phoneHint,
      coverage: patient.coverageLabel,
      lastVisit: patient.lastVisitLabel,
      nextVisit: patient.nextAppointmentLabel ?? 'Sin cita',
      practitioner: patient.assignedPractitioner,
      alerts: patient.alertLabels,
      consent: patient.consentStatus === 'current' ? 'Al día' : patient.consentStatus === 'review' ? 'Revisar' : 'Pendiente',
    };
  }
}
