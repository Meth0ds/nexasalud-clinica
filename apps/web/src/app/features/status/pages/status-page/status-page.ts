import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatChipsModule } from '@angular/material/chips';
import { MatDividerModule } from '@angular/material/divider';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { SessionService } from '../../../../core/services/session';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';

type StatusKind = 'not-found' | 'unavailable' | 'forbidden';

/**
 * Provides a safe, recoverable destination for unmatched routes and unexpected application failures.
 */
@Component({
  selector: 'app-status-page',
  imports: [MatButtonModule, MatCardModule, MatChipsModule, MatDividerModule, RouterLink, AppIcon],
  templateUrl: './status-page.html',
  styleUrl: './status-page.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class StatusPage {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  protected readonly kind = computed<StatusKind>(() => {
    const kind = this.route.snapshot.data['kind'];
    return kind === 'not-found' || kind === 'forbidden' ? kind : 'unavailable';
  });
  protected readonly title = computed(() =>
    this.kind() === 'not-found'
      ? 'Esta página no está disponible.'
      : this.kind() === 'forbidden'
        ? 'No tienes acceso a este espacio de trabajo.'
        : 'No hemos podido cargar esta sección.',
  );
  protected readonly description = computed(() =>
    this.kind() === 'not-found'
      ? 'La dirección puede haber cambiado o no estar incluida en tu contexto autorizado.'
      : this.kind() === 'forbidden'
        ? 'Tu sesión está verificada, pero tu rol actual no incluye un espacio asistencial. Contacta con el administrador del centro o cierra sesión para usar otra cuenta.'
        : 'El servicio ha devuelto un estado inesperado. Tus datos no se han modificado.',
  );
  protected readonly code = computed(() =>
    this.kind() === 'not-found' ? '404' : this.kind() === 'forbidden' ? '403' : '503',
  );
  protected readonly codeLabel = computed(() =>
    this.kind() === 'not-found'
      ? 'Ruta no localizada'
      : this.kind() === 'forbidden'
        ? 'Acceso denegado'
        : 'Servicio no disponible',
  );
  private readonly session = inject(SessionService);

  /**
   * Ends the current verified session from the terminal no-access state.
   */
  protected signOut(): void {
    this.session.logout();
  }

  /**
   * Returns the user to the protected entry route so its session and permission guards run again.
   */
  protected returnToWorkspace(): void {
    void this.router.navigateByUrl('/inicio');
  }
}
