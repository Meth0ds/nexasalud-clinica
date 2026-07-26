import { DOCUMENT } from '@angular/common';
import { ChangeDetectionStrategy, Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatTabsModule } from '@angular/material/tabs';
import { RouterLink } from '@angular/router';

import { ErrorNotificationService } from '../../../../core/services/error-notification';
import { AppIcon } from '../../../../shared/components/app-icon/app-icon';
import { ScrollReveal } from '../../../../shared/directives/scroll-reveal';

@Component({
  selector: 'app-clinical-records',
  imports: [FormsModule, MatButtonModule, MatTabsModule, RouterLink, AppIcon, ScrollReveal],
  templateUrl: './clinical-records.html',
  styleUrls: [
    './clinical-records.scss',
    './clinical-records-content.scss',
    './clinical-records-panels.scss',
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ClinicalRecords {
  private readonly notifications = inject(ErrorNotificationService);
  private readonly document = inject(DOCUMENT);
  protected readonly editingNote = signal(false);
  protected readonly noteText = signal('');
  protected readonly privacyVisible = signal(true);

  protected saveNote(): void {
    if (this.noteText().trim().length < 10) {
      this.notifications.notify(new Error('La nota es demasiado breve.'));
      return;
    }
    this.editingNote.set(false);
    this.noteText.set('');
    this.notifications.success('Nota clínica firmada y añadida a la historia.');
  }

  protected generateSummary(): void {
    this.document.defaultView?.open(
      '/api/v1/patients/demo-patient/documents/clinical-summary.pdf',
      '_blank',
      'noopener,noreferrer',
    );
  }
}
