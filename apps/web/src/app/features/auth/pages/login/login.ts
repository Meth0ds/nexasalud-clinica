import { ChangeDetectionStrategy, Component, inject, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';

import { SessionService } from '../../../../core/services/session';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';

@Component({
  selector: 'app-login',
  imports: [MatButtonModule, AppIcon],
  templateUrl: './login.html',
  styleUrl: './login.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Login {
  readonly reason = input<string>();
  readonly error = input<string>();
  readonly returnTo = input<string>('/inicio');
  protected readonly busy = signal(false);
  private readonly session = inject(SessionService);

  protected errorMessage(): string {
    return this.error() === 'mfa_enrollment_completed'
      ? 'Tu segundo factor ya está configurado. Vuelve a entrar y escribe el código de tu aplicación autenticadora para completar el acceso.'
      : this.error() === 'identity_unavailable'
      ? 'El servicio de identidad no está disponible temporalmente. No se ha iniciado ninguna sesión.'
      : this.error() === 'access_denied'
        ? 'El acceso no se ha autorizado. Revisa tu cuenta con el administrador del centro.'
        : 'La verificación no se ha podido completar o ha caducado. Iníciala de nuevo.';
  }

  protected startLogin(returnTo = this.returnTo()): void {
    if (this.busy()) {
      return;
    }
    this.busy.set(true);
    this.session.login(returnTo);
  }
}
