import { DatePipe } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { BreakpointObserver } from '@angular/cdk/layout';
import { MatButtonModule } from '@angular/material/button';
import { MatMenuModule } from '@angular/material/menu';
import { MatSidenav, MatSidenavModule } from '@angular/material/sidenav';
import { MatTooltipModule } from '@angular/material/tooltip';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { map } from 'rxjs';

import { SessionService } from '../../core/services/session';
import { ThemePreferences } from '../../core/services/theme-preferences';
import { AppIcon, AppIconName } from '../../shared/components/app-icon/app-icon';

interface NavigationItem {
  readonly label: string;
  readonly route: string;
  readonly icon: AppIconName;
  readonly badge?: string;
  readonly permission?: string;
}

@Component({
  selector: 'app-app-shell',
  imports: [
    DatePipe,
    RouterOutlet,
    RouterLink,
    RouterLinkActive,
    MatSidenavModule,
    MatButtonModule,
    MatMenuModule,
    MatTooltipModule,
    AppIcon,
  ],
  templateUrl: './app-shell.html',
  styleUrl: './app-shell.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppShell {
  private readonly breakpoints = inject(BreakpointObserver);
  readonly session = inject(SessionService);
  readonly preferences = inject(ThemePreferences);
  readonly today = new Date();
  readonly compact = toSignal(
    this.breakpoints.observe('(max-width: 980px)').pipe(map((state) => state.matches)),
    { initialValue: false },
  );
  readonly userInitials = computed(() => this.session.user()?.initials ?? 'NS');

  readonly principalNavigation: readonly NavigationItem[] = [
    { label: 'Centro de mando', route: '/inicio', icon: 'home' },
    { label: 'Agenda clínica', route: '/agenda', icon: 'calendar' },
    { label: 'Pacientes', route: '/pacientes', icon: 'patients' },
    { label: 'Historia clínica', route: '/historia-clinica', icon: 'shield' },
    { label: 'Prescripciones', route: '/prescripciones', icon: 'spark', badge: 'Nuevo' },
    { label: 'Lista de espera', route: '/lista-espera', icon: 'waitlist', badge: '8' },
  ];

  readonly operationsNavigation: readonly NavigationItem[] = [
    { label: 'Comunicaciones', route: '/comunicaciones', icon: 'bell' },
    { label: 'Facturación', route: '/facturacion', icon: 'chart' },
    { label: 'Equipo y turnos', route: '/equipo', icon: 'team' },
    { label: 'Analítica', route: '/analitica', icon: 'chart' },
  ];

  readonly systemNavigation: readonly NavigationItem[] = [
    { label: 'Auditoría', route: '/auditoria', icon: 'eye' },
    { label: 'Configuración', route: '/configuracion', icon: 'settings' },
    { label: 'Administración', route: '/administracion', icon: 'shield', permission: 'admin.users.read' },
  ];

  closeOnMobile(drawer: MatSidenav): void {
    if (this.compact()) {
      void drawer.close();
    }
  }
}
