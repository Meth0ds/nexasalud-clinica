import { Routes } from '@angular/router';

import {
  anonymousOnlyGuard,
  contextSelectionGuard,
  permissionGuard,
  sessionGuard,
  staffWorkspaceGuard,
} from './core/guards/session.guard';

export const routes: Routes = [
  {
    path: 'login',
    canActivate: [anonymousOnlyGuard],
    loadComponent: () => import('./features/auth/pages/login/login').then((module) => module.Login),
    title: 'Acceso seguro · NexaSalud',
  },
  {
    path: 'seleccionar-centro',
    canActivate: [contextSelectionGuard],
    loadComponent: () =>
      import('./features/auth/pages/context-selection/context-selection').then(
        (module) => module.ContextSelection,
      ),
    title: 'Seleccionar centro · NexaSalud',
  },
  {
    path: 'portal',
    canActivate: [sessionGuard, permissionGuard('portal.self', '/inicio')],
    loadComponent: () =>
      import('./features/patient-portal/pages/patient-portal/patient-portal').then(
        (module) => module.PatientPortal,
      ),
    title: 'Mi salud · NexaSalud',
  },
  {
    path: '',
    canActivate: [sessionGuard, staffWorkspaceGuard],
    loadComponent: () => import('./layout/app-shell/app-shell').then((module) => module.AppShell),
    children: [
      { path: '', pathMatch: 'full', redirectTo: 'inicio' },
      {
        path: 'inicio',
        loadComponent: () =>
          import('./features/dashboard/pages/dashboard/dashboard').then(
            (module) => module.Dashboard,
          ),
        title: 'Centro de mando · NexaSalud',
      },
      {
        path: 'agenda',
        loadComponent: () =>
          import('./features/agenda/pages/agenda/agenda').then((module) => module.Agenda),
        title: 'Agenda clínica · NexaSalud',
      },
      {
        path: 'pacientes',
        loadComponent: () =>
          import('./features/patients/pages/patients/patients').then((module) => module.Patients),
        title: 'Pacientes · NexaSalud',
      },
      {
        path: 'historia-clinica',
        loadComponent: () =>
          import('./features/clinical/pages/clinical-records/clinical-records').then(
            (module) => module.ClinicalRecords,
          ),
        title: 'Historia clínica · NexaSalud',
      },
      {
        path: 'prescripciones',
        loadComponent: () =>
          import('./features/prescriptions/pages/prescriptions/prescriptions').then(
            (module) => module.Prescriptions,
          ),
        title: 'Prescripciones · NexaSalud',
      },
      {
        path: 'lista-espera',
        loadComponent: () =>
          import('./features/waitlist/pages/waitlist/waitlist').then((module) => module.Waitlist),
        title: 'Lista de espera · NexaSalud',
      },
      {
        path: 'comunicaciones',
        loadComponent: () =>
          import('./features/communications/pages/communications/communications').then(
            (module) => module.Communications,
          ),
        title: 'Comunicaciones · NexaSalud',
      },
      {
        path: 'facturacion',
        loadComponent: () =>
          import('./features/billing/pages/billing/billing').then((module) => module.Billing),
        title: 'Facturación · NexaSalud',
      },
      {
        path: 'equipo',
        loadComponent: () =>
          import('./features/team/pages/team/team').then((module) => module.Team),
        title: 'Equipo · NexaSalud',
      },
      {
        path: 'analitica',
        loadComponent: () =>
          import('./features/analytics/pages/analytics/analytics').then(
            (module) => module.Analytics,
          ),
        title: 'Analítica · NexaSalud',
      },
      {
        path: 'auditoria',
        loadComponent: () =>
          import('./features/audit/pages/audit/audit').then((module) => module.Audit),
        title: 'Auditoría · NexaSalud',
      },
      {
        path: 'configuracion',
        loadComponent: () =>
          import('./features/settings/pages/settings/settings').then((module) => module.Settings),
        title: 'Configuración · NexaSalud',
      },
      {
        path: 'administracion',
        canActivate: [permissionGuard('admin.users.read')],
        loadComponent: () =>
          import('./features/administration/pages/administration/administration').then(
            (module) => module.Administration,
          ),
        title: 'Administración · NexaSalud',
      },
    ],
  },
  {
    path: 'indisponible',
    loadComponent: () =>
      import('./features/status/pages/status-page/status-page').then((module) => module.StatusPage),
    data: { kind: 'unavailable' },
    title: 'Servicio no disponible · NexaSalud',
  },
  {
    path: '**',
    loadComponent: () =>
      import('./features/status/pages/status-page/status-page').then((module) => module.StatusPage),
    data: { kind: 'not-found' },
    title: 'Página no disponible · NexaSalud',
  },
];
