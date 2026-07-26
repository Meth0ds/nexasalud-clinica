import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { PatientsService } from '../../../../core/services/patients';
import { SessionService } from '../../../../core/services/session';
import { CommunicationsApiService } from '../../data-access/communications-api';

import { Communications } from './communications';

describe('Communications', () => {
  let component: Communications;
  let fixture: ComponentFixture<Communications>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Communications],
      providers: [
        { provide: CommunicationsApiService, useValue: { list: () => of({ total: 0, communications: [] }), create: () => of({}), queue: () => of({}), cancel: () => of({}) } },
        { provide: PatientsService, useValue: { search: () => of({ total: 0, items: [] }) } },
        { provide: SessionService, useValue: { hasPermission: () => false } },
      ],
    }).compileComponents();

    fixture = TestBed.createComponent(Communications);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('renders an empty authorized timeline instead of fabricated conversations', () => {
    expect(component).toBeTruthy();
    expect(fixture.nativeElement.textContent).toContain('Sin comunicaciones');
    expect(fixture.nativeElement.textContent).not.toContain('Ana R.');
  });
});
