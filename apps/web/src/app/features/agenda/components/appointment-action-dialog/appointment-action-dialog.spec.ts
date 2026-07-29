import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';

import { AppointmentActionDialog } from './appointment-action-dialog';

describe('AppointmentActionDialog', () => {
  let fixture: ComponentFixture<AppointmentActionDialog>;
  const close = vi.fn();

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AppointmentActionDialog],
      providers: [
        {
          provide: MAT_DIALOG_DATA,
          useValue: { action: 'cancel', patientName: 'Paciente autorizado' },
        },
        { provide: MatDialogRef, useValue: { close } },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(AppointmentActionDialog);
    fixture.detectChanges();
  });

  it('requires a non-empty operational reason before confirming a cancellation', () => {
    fixture.componentInstance['confirm']();
    expect(close).not.toHaveBeenCalled();

    fixture.componentInstance['form'].controls.reason.setValue('Solicitud de la persona.');
    fixture.componentInstance['confirm']();
    expect(close).toHaveBeenCalledWith({ reason: 'Solicitud de la persona.' });
  });
});
