import { ComponentFixture, TestBed } from '@angular/core/testing';

import { ClinicalRecords } from './clinical-records';

describe('ClinicalRecords', () => {
  let component: ClinicalRecords;
  let fixture: ComponentFixture<ClinicalRecords>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ClinicalRecords],
    }).compileComponents();

    fixture = TestBed.createComponent(ClinicalRecords);
    component = fixture.componentInstance;
    await fixture.whenStable();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
