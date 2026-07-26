import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { Router } from '@angular/router';

import { SessionService } from '../../../../core/services/session';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';

@Component({
  selector: 'app-context-selection',
  imports: [MatButtonModule, AppIcon],
  templateUrl: './context-selection.html',
  styleUrl: './context-selection.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ContextSelection {
  protected readonly session = inject(SessionService);
  private readonly router = inject(Router);
  protected readonly busy = signal(false);
  protected readonly error = signal(false);
  protected readonly membershipId = signal<string | null>(
    this.session.contextOptions()?.selected.membershipId ??
      this.session.contextOptions()?.items[0]?.membershipId ??
      null,
  );
  protected readonly selectedMembership = computed(() =>
    this.session.contextOptions()?.items.find(
      (item) => item.membershipId === this.membershipId(),
    ),
  );
  protected readonly clinicId = signal<string | null>(
    this.session.contextOptions()?.selected.clinicId ??
      this.selectedMembership()?.clinics[0]?.id ??
      null,
  );

  protected chooseMembership(id: string): void {
    this.membershipId.set(id);
    const membership = this.session.contextOptions()?.items.find(
      (item) => item.membershipId === id,
    );
    this.clinicId.set(membership?.clinics[0]?.id ?? null);
    this.error.set(false);
  }

  protected chooseClinic(id: string): void {
    this.clinicId.set(id);
    this.error.set(false);
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
        } else {
          this.error.set(true);
        }
      },
      error: () => {
        this.busy.set(false);
        this.error.set(true);
      },
    });
  }
}
