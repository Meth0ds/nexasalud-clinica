import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { StatusPage } from './status-page';

describe('StatusPage', () => {
  let fixture: ComponentFixture<StatusPage>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [StatusPage],
      providers: [provideRouter([])],
    }).compileComponents();
    fixture = TestBed.createComponent(StatusPage);
    fixture.detectChanges();
  });

  it('renders a safe recovery route without technical details', () => {
    const text = fixture.nativeElement.textContent;

    expect(text).toContain('No hemos podido cargar esta sección.');
    expect(text).toContain('Volver al espacio de trabajo');
    expect(text).not.toContain('stack');
  });
});
