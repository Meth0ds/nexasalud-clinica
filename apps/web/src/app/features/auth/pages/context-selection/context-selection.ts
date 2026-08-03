import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { MatChipsModule } from '@angular/material/chips';
import { MatDividerModule } from '@angular/material/divider';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { MatRadioModule } from '@angular/material/radio';
import { Router } from '@angular/router';

import { AppError, toAppError } from '../../../../core/errors/app-error';
import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { SessionService } from '../../../../core/services/session';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { StatePanel } from '../../../../shared/components/state-panel/state-panel';

@Component({
  selector: 'app-context-selection',
  imports: [
    MatButtonModule,
    MatCardModule,
    MatChipsModule,
    MatDividerModule,
    MatProgressSpinnerModule,
    MatRadioModule,
    AppIcon,
    StatePanel,
  ],
  templateUrl: './context-selection.html',
  styleUrl: './context-selection.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContextSelection {
  protected readonly session = inject(SessionService);
  private readonly router = inject(Router);
  private readonly notifications = inject(ErrorNotificationService);
  protected readonly busy = signal(false);
  protected readonly error = signal<AppError | null>(null);
  protected readonly membershipId = signal<string | null>(
    this.session.contextOptions()?.selected.membershipId ??
      this.session.contextOptions()?.items[0]?.membershipId ??
      null,
  );
  protected readonly selectedMembership = computed(() =>
    this.session.contextOptions()?.items.find((item) => item.membershipId === this.membershipId()),
  );
  protected readonly clinicId = signal<string | null>(
    this.session.contextOptions()?.selected.clinicId ??
      this.selectedMembership()?.clinics[0]?.id ??
      null,
  );

  protected chooseMembership(id: string): void {
    this.membershipId.set(id);
    const membership = this.session
      .contextOptions()
      ?.items.find((item) => item.membershipId === id);
    this.clinicId.set(membership?.clinics[0]?.id ?? null);
    this.error.set(null);
  }

  protected chooseClinic(id: string): void {
    this.clinicId.set(id);
    this.error.set(null);
  }

  protected continue(): void {
    const membershipId = this.membershipId();
    const clinicId = this.clinicId();
    if (!membershipId || !clinicId || this.busy()) {
      return;
    }

    this.busy.set(true);
    this.session.selectContext(membershipId, clinicId).subscribe({
      next: (state) => {
        this.busy.set(false);
        if (state.status === 'authenticated') {
          void this.router.navigate(['/inicio']);
        } else if (state.status === 'error') {
          this.reportError(state.error);
        } else {
          this.reportError(
            new AppError({
              status: 409,
              code: 'CONTEXT_NOT_READY',
              message: 'El centro sigue pendiente de validación. Inténtalo de nuevo.',
              retryable: true,
            }),
          );
        }
      },
      error: (error: unknown) => {
        this.busy.set(false);
        this.reportError(toAppError(error));
      },
    });
  }

  private reportError(error: AppError): void {
    this.error.set(error);
    this.notifications.notify(error);
  }
}
