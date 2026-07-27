import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';

import { PatientsService } from '../../../../core/services/patients';
import { ClinicalNotesApiService } from '../../data-access/clinical-notes-api';
import { ClinicalRecords } from './clinical-records';

describe('ClinicalRecords', () => {
  let component: ClinicalRecords;
  let fixture: ComponentFixture<ClinicalRecords>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ClinicalRecords],
      providers: [
        provideRouter([]),
        {
          provide: PatientsService,
          useValue: {
            search: () =>
              of({
                total: 1,
                items: [
                  {
                    id: 'patient-1',
                    displayName: 'Lucía Martín',
                    initials: 'LM',
                    ageRange: '30–39',
                    phoneHint: '•••',
                    emailHint: 'l•••@test',
                    coverageLabel: 'Privado',
                    lastVisitLabel: 'Sin visitas',
                    consentStatus: 'current',
                    assignedPractitioner: 'Dra. Vega',
                    alertLabels: [],
                  },
                ],
              }),
          },
        },
        {
          provide: ClinicalNotesApiService,
          useValue: {
            list: () => of({ total: 0, notes: [] }),
            createDraft: () => of({}),
            sign: () => of({}),
          },
        },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(ClinicalRecords);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('keeps the record closed until an authorized patient is selected', () => {
    expect(component).toBeTruthy();
    expect(fixture.nativeElement.textContent).toContain('Selecciona un contexto asistencial');
    expect(fixture.nativeElement.textContent).not.toContain('Lucas M.');
  });
});
