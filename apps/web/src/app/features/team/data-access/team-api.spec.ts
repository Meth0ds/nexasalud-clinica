import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';

import { TeamApiService } from './team-api';

describe('TeamApiService', () => {
  let service: TeamApiService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(TeamApiService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('loads only the authorized team directory from the current tenant', async () => {
    const result = firstValueFrom(service.load());
    const request = http.expectOne('/api/v1/team');

    expect(request.request.method).toBe('GET');
    request.flush({
      data: [
        {
          membershipId: 'membership-1',
          userId: 'user-1',
          displayName: 'Dra. Elena Torres',
          initials: 'ET',
          roles: [{ id: 'role-1', code: 'clinician', name: 'Profesional clínico' }],
          active: true,
          joinedAt: '2026-07-01T08:00:00.000Z',
          lastLoginAt: '2026-07-26T08:00:00.000Z',
        },
      ],
      meta: { total: 1 },
    });

    await expect(result).resolves.toMatchObject([
      { displayName: 'Dra. Elena Torres', roles: [{ name: 'Profesional clínico' }] },
    ]);
  });
});
