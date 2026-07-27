import { BreakpointObserver } from '@angular/cdk/layout';
import { registerLocaleData } from '@angular/common';
import localeEs from '@angular/common/locales/es';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';

import { SessionService } from '../../core/services/session';
import { ThemePreferences } from '../../core/services/theme-preferences';
import { AppShell } from './app-shell';

registerLocaleData(localeEs);

describe('AppShell', () => {
  let fixture: ComponentFixture<AppShell>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AppShell],
      providers: [
        provideRouter([]),
        { provide: BreakpointObserver, useValue: { observe: () => of({ matches: false }) } },
        {
          provide: SessionService,
          useValue: {
            tenant: signal({
              id: 'tenant-1',
              name: 'NexaSalud',
              clinicId: 'clinic-1',
              clinicName: 'Centro Norte',
            }),
            user: signal({
              id: 'user-1',
              displayName: 'Dra. Alba Romero',
              emailHint: 'a***@example.test',
              initials: 'AR',
              professionalTitle: 'Medicina familiar',
            }),
            assuranceLevel: signal('mfa'),
            hasPermission: () => true,
            logout: vi.fn(),
          },
        },
        {
          provide: ThemePreferences,
          useValue: { effectiveTheme: signal('light'), toggleTheme: vi.fn() },
        },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(AppShell);
    fixture.detectChanges();
  });

  it('uses the authenticated context and does not display fabricated shell data', () => {
    const text = fixture.nativeElement.textContent;

    expect(text).toContain('Centro Norte');
    expect(text).toContain('Dra. Alba Romero');
    expect(text).not.toContain('Clínica Mirador');
    expect(text).not.toContain('Dra. Elena Soler');
    expect(text).not.toContain('Lista de espera8');
  });

  it('provides a keyboard skip link to the workspace content', () => {
    const link = fixture.nativeElement.querySelector('.nexa-skip-link') as HTMLAnchorElement;

    expect(link.textContent).toContain('Saltar al contenido principal');
    expect(link.getAttribute('href')).toBe('#contenido-principal');
  });
});
