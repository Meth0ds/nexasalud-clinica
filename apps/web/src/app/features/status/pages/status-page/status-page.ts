import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatChipsModule } from '@angular/material/chips';
import { MatDividerModule } from '@angular/material/divider';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';

import { AppIcon } from '../../../../shared/components/app-icon/app-icon';

type StatusKind = 'not-found' | 'unavailable';

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

  protected readonly kind = computed<StatusKind>(() =>
    this.route.snapshot.data['kind'] === 'not-found' ? 'not-found' : 'unavailable',
  );
  protected readonly title = computed(() =>
    this.kind() === 'not-found'
      ? 'Esta página no está disponible.'
      : 'No hemos podido cargar esta sección.',
  );
  protected readonly description = computed(() =>
    this.kind() === 'not-found'
      ? 'La dirección puede haber cambiado o no estar incluida en tu contexto autorizado.'
      : 'El servicio ha devuelto un estado inesperado. Tus datos no se han modificado.',
  );

  /**
   * Returns the user to the protected entry route so its session and permission guards run again.
   */
  protected returnToWorkspace(): void {
    void this.router.navigateByUrl('/inicio');
  }
}
