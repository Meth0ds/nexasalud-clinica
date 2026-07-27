import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { AnalyticsApiService } from '../../data-access/analytics-api';
import { Analytics } from './analytics';

describe('Analytics', () => {
  let fixture: ComponentFixture<Analytics>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Analytics],
      providers: [
        {
          provide: AnalyticsApiService,
          useValue: {
            load: () =>
              of({
                range: {
                  from: '2026-07-01T00:00:00Z',
                  to: '2026-07-30T23:59:59Z',
                  timezone: 'Europe/Madrid',
                },
                metrics: {
                  appointments: 12,
                  completionRate: 75,
                  cancellationRate: 8.3,
                  noShowRate: 16.7,
                },
                byStatus: { completed: 9, cancelled: 1, no_show: 2 },
                privacy: { aggregated: true, minimumGroupSize: 5 },
              }),
          },
        },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(Analytics);
    await fixture.whenStable();
    fixture.detectChanges();
  });

  it('renders the authorized analytics response instead of demonstration metrics', () => {
    const text = fixture.nativeElement.textContent;

    expect(text).toContain('12');
    expect(text).toContain('75%');
    expect(text).toContain('Completadas');
    expect(text).not.toContain('1.248');
    expect(text).not.toContain('Dra. Elena Soler');
  });
});
