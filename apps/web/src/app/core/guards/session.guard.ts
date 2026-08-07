import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { map } from 'rxjs';

import { SessionService } from '../services/session';

export const sessionGuard: CanActivateFn = () => {
  const session = inject(SessionService);
  const router = inject(Router);
  return session
    .ensureLoaded()
    .pipe(
      map((state) =>
        state.status === 'authenticated'
          ? true
          : state.status === 'context-required'
            ? router.createUrlTree(['/seleccionar-centro'])
            : state.status === 'no-access'
              ? router.createUrlTree(['/sin-acceso'])
              : router.createUrlTree(['/login'], { queryParams: { reason: 'session' } }),
      ),
    );
};

export const anonymousOnlyGuard: CanActivateFn = () => {
  const session = inject(SessionService);
  const router = inject(Router);
  return session
    .ensureLoaded()
    .pipe(
      map((state) =>
        state.status === 'authenticated'
          ? router.createUrlTree(['/'])
          : state.status === 'context-required'
            ? router.createUrlTree(['/seleccionar-centro'])
            : state.status === 'no-access'
              ? router.createUrlTree(['/sin-acceso'])
              : true,
      ),
    );
};

export const contextSelectionGuard: CanActivateFn = () => {
  const session = inject(SessionService);
  const router = inject(Router);
  return session
    .ensureLoaded()
    .pipe(
      map((state) =>
        state.status === 'context-required'
          ? true
          : state.status === 'authenticated'
            ? router.createUrlTree(['/'])
            : state.status === 'no-access'
              ? router.createUrlTree(['/sin-acceso'])
              : router.createUrlTree(['/login']),
      ),
    );
};

export function permissionGuard(permission: string, fallback = '/inicio'): CanActivateFn {
  return () => {
    const session = inject(SessionService);
    const router = inject(Router);
    return session
      .ensureLoaded()
      .pipe(
        map((state) =>
          state.status === 'authenticated' && session.hasPermission(permission)
            ? true
            : state.status === 'no-access'
              ? router.createUrlTree(['/sin-acceso'])
              : router.createUrlTree([fallback], { queryParams: { reason: 'forbidden' } }),
        ),
      );
  };
}

export const staffWorkspaceGuard: CanActivateFn = () => {
  const session = inject(SessionService);
  const router = inject(Router);
  return session.ensureLoaded().pipe(
    map((state) => {
      if (state.status === 'no-access') {
        return router.createUrlTree(['/sin-acceso']);
      }
      if (state.status !== 'authenticated') {
        return router.createUrlTree(['/login']);
      }
      if (session.hasPermission('appointments.read')) {
        return true;
      }
      return session.hasPermission('portal.self')
        ? router.createUrlTree(['/portal'])
        : router.createUrlTree(['/sin-acceso']);
    }),
  );
};
