import { ComponentFixture, TestBed } from '@angular/core/testing';
import { of } from 'rxjs';

import { TeamApiService } from '../../data-access/team-api';
import { Team } from './team';

describe('Team', () => {
  let fixture: ComponentFixture<Team>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [Team],
      providers: [
        {
          provide: TeamApiService,
          useValue: {
            load: () =>
              of([
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
              ]),
          },
        },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(Team);
    await fixture.whenStable();
  });

  it('renders the authorized directory from the API instead of fixture personnel', () => {
    expect(fixture.nativeElement.textContent).toContain('Dra. Elena Torres');
    expect(fixture.nativeElement.textContent).not.toContain('Dra. Elena Soler');
  });
});
