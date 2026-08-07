import { DatePipe, DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { BreakpointObserver } from '@angular/cdk/layout';
import { MatButtonModule } from '@angular/material/button';
import { MatListModule } from '@angular/material/list';
import { MatMenuModule } from '@angular/material/menu';
import { MatSidenav, MatSidenavModule } from '@angular/material/sidenav';
import { MatTooltipModule } from '@angular/material/tooltip';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { filter, map } from 'rxjs';

import { SessionService } from '../../core/services/session';
import { ThemePreferences } from '../../core/services/theme-preferences';
import { AppIcon, AppIconName } from '../../shared/components/app-icon/app-icon';

interface NavigationItem {
  readonly label: string;
  readonly route: string;
  readonly icon: AppIconName;
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
    MatListModule,
    MatMenuModule,
    MatTooltipModule,
    AppIcon,
  ],
  templateUrl: './app-shell.html',
  styleUrl: './app-shell.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AppShell {
  private static readonly COLLAPSE_KEY = 'nexa.sidebarCollapsed';

  private readonly breakpoints = inject(BreakpointObserver);
  private readonly router = inject(Router);
  private readonly document = inject(DOCUMENT);
  readonly session = inject(SessionService);
  readonly preferences = inject(ThemePreferences);
  readonly today = new Date();
  /** Desktop-only manual collapse; the drawer takes over below 980px. */
  readonly collapsed = signal(false);
  readonly compact = toSignal(
    this.breakpoints.observe('(max-width: 980px)').pipe(map((state) => state.matches)),
    { initialValue: false },
  );
  readonly currentUrl = toSignal(
    this.router.events.pipe(
      filter((event): event is NavigationEnd => event instanceof NavigationEnd),
      map((event) => event.urlAfterRedirects),
    ),
    { initialValue: this.router.url },
  );
  readonly userInitials = computed(() => this.session.user()?.initials ?? '—');
  readonly assuranceLabel = computed(() => {
    const assurance = this.session.assuranceLevel();

    return assurance === 'passkey'
      ? 'Passkey verificada'
      : assurance === 'mfa'
        ? 'MFA verificado'
        : 'Sesión verificada';
  });
  /** Label of the active navigation section, used as breadcrumb in the topbar. */
  readonly currentSectionLabel = computed(() => {
    const url = this.currentUrl() ?? '';
    const all = [
      ...this.principalNavigation,
      ...this.operationsNavigation,
      ...this.systemNavigation,
    ];
    const match = [...all]
      .sort((a, b) => b.route.length - a.route.length)
      .find((item) => url.startsWith(item.route));
    return match?.label ?? 'Espacio clínico';
  });
  readonly todayLabel = computed(() =>
    new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long' }).format(
      this.today,
    ),
  );

  readonly principalNavigation: readonly NavigationItem[] = [
    { label: 'Centro de mando', route: '/inicio', icon: 'home' },
    { label: 'Agenda clínica', route: '/agenda', icon: 'calendar' },
    { label: 'Pacientes', route: '/pacientes', icon: 'patients' },
    { label: 'Historia clínica', route: '/historia-clinica', icon: 'shield' },
    { label: 'Prescripciones', route: '/prescripciones', icon: 'spark' },
    { label: 'Lista de espera', route: '/lista-espera', icon: 'waitlist' },
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
    {
      label: 'Administración',
      route: '/administracion',
      icon: 'shield',
      permission: 'admin.users.read',
    },
  ];

  constructor() {
    const stored = this.document.defaultView?.localStorage.getItem(
      AppShell.COLLAPSE_KEY,
    );
    if (stored === 'true') {
      this.collapsed.set(true);
    }
  }

  toggleCollapsed(): void {
    this.collapsed.set(!this.collapsed());
    this.document.defaultView?.localStorage.setItem(
      AppShell.COLLAPSE_KEY,
      String(this.collapsed()),
    );
  }

  closeOnMobile(drawer: MatSidenav): void {
    if (this.compact()) {
      void drawer.close();
    }
  }
}
