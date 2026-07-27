import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatProgressSpinnerModule } from '@angular/material/progress-spinner';
import { finalize } from 'rxjs';

import { AppError, toAppError } from '../../../../core/errors/app-error';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';
import { TeamApiService, TeamMember } from '../../data-access/team-api';

@Component({
  selector: 'app-team',
  imports: [
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    MatProgressSpinnerModule,
    AppIcon,
    ScrollReveal,
  ],
  templateUrl: './team.html',
  styleUrl: './team.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class Team {
  private readonly api = inject(TeamApiService);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly members = signal<readonly TeamMember[]>([]);
  protected readonly query = signal('');
  protected readonly loading = signal(true);
  protected readonly error = signal<AppError | null>(null);

  protected readonly activeMembers = computed(
    () => this.members().filter((member) => member.active).length,
  );
  protected readonly inactiveMembers = computed(() => this.members().length - this.activeMembers());
  protected readonly recentAccessCount = computed(
    () => this.members().filter((member) => member.lastLoginAt !== null).length,
  );
  protected readonly filteredMembers = computed(() => {
    const query = this.query().trim().toLocaleLowerCase('es');
    if (!query) {
      return this.members();
    }

    return this.members().filter((member) =>
      [member.displayName ?? '', ...member.roles.map((role) => role.name)].some((value) =>
        value.toLocaleLowerCase('es').includes(query),
      ),
    );
  });

  constructor() {
    this.load();
  }

  protected refresh(): void {
    if (!this.loading()) {
      this.load();
    }
  }

  protected updateQuery(value: string): void {
    this.query.set(value);
  }

  protected roleSummary(member: TeamMember): string {
    return member.roles.length > 0
      ? member.roles.map((role) => role.name).join(', ')
      : 'Sin rol asignado';
  }

  protected formatTimestamp(value: string | null): string {
    if (!value) {
      return 'Sin acceso registrado';
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime())
      ? 'Fecha no disponible'
      : new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }

  private load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api
      .load()
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        finalize(() => this.loading.set(false)),
      )
      .subscribe({
        next: (members) => this.members.set(members),
        error: (error: unknown) => this.error.set(toAppError(error)),
      });
  }
}
