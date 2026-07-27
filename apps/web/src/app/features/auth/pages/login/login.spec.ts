import { ComponentFixture, TestBed } from '@angular/core/testing';

import { SessionService } from '../../../../core/services/session';
import { Login } from './login';

describe('Login', () => {
  let fixture: ComponentFixture<Login>;
  const login = vi.fn();

  beforeEach(async () => {
    login.mockReset();
    await TestBed.configureTestingModule({
      imports: [Login],
      providers: [{ provide: SessionService, useValue: { login } }],
    }).compileComponents();
    fixture = TestBed.createComponent(Login);
    fixture.componentRef.setInput('returnTo', '/agenda');
    fixture.detectChanges();
  });

  it('presents a professional identity entry point without fabricated appointment data', () => {
    expect(fixture.nativeElement.textContent).toContain('Acceso seguro');
    expect(fixture.nativeElement.textContent).not.toContain('Consulta de seguimiento');
    expect(fixture.nativeElement.textContent).not.toContain('Primera valoración');
  });
});
