import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { DashboardApiService } from './dashboard-api';

describe('DashboardApiService', () => {
  let service: DashboardApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(DashboardApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads the protected active-clinic operational summary', async () => {
    const result = firstValueFrom(service.load());
    const request = http.expectOne('/api/v1/dashboard');
    expect(request.request.method).toBe('GET');
    request.flush({
      data: {
        date: '2026-07-26',
        timezone: 'Europe/Madrid',
        clinic: { id: 'clinic-1', name: 'Centro Norte' },
        metrics: {
          appointments: 0,
          active: 0,
          completed: 0,
          cancelledOrNoShow: 0,
          waitingList: 0,
          occupancyPercent: 0,
        },
        dayAppointments: [],
      },
    });

    await expect(result).resolves.toMatchObject({
      clinic: { name: 'Centro Norte' },
      dayAppointments: [],
    });
  });
});
